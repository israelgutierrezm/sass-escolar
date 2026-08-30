<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\EstatusHistorial;
use App\Models\ControlEscolar\Historial;
use App\Models\Identidad\Persona;
use App\Services\EstatusAcademico;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga masiva de alumnos. Cada fila crea/actualiza la persona (por CURP), su
 * registro de alumno y su matrícula en la oferta (programa académico+plan+campus). Si el
 * archivo trae la hoja «Calificaciones», también carga el historial académico: el estatus se
 * deriva de la calificación con la regla única del sistema (EstatusAcademico).
 * No crea nada si hay errores.
 */
class ImportadorAlumnos extends ImportadorBase
{
    public function __construct(private EstatusAcademico $estatus) {}

    /** @return array{errores: array<int, mixed>, resumen: array<string, int>} */
    public function importar(string $path): array
    {
        $this->errores = [];
        $libro = IOFactory::load($path);

        $situaciones = $this->mapaCatalogo(SituacionAlumno::class);
        $programaAcademicoId = ProgramaAcademico::query()->pluck('id', 'clave')->all();
        $planId = PlanEstudio::query()->pluck('id', 'clave')->all();
        $campusId = Campus::query()->pluck('id', 'clave')->all();
        $cicloId = Ciclo::query()->pluck('id', 'clave')->all();
        $estatusId = EstatusHistorial::query()->pluck('id', 'clave')->all();
        $minimaPlan = PlanEstudio::query()->pluck('calificacion_minima_aprobatoria', 'id')->all();

        $alumnos = $this->leer($libro, 'Alumnos');
        $calif = $this->leer($libro, 'Calificaciones');

        // matrícula → plan_id (del archivo y de la BD), para resolver materias.
        $planPorMatricula = [];
        foreach ($alumnos as [, $r]) {
            $mat = $this->str($r[7] ?? null);
            if ($mat !== null) {
                $planPorMatricula[$mat] = $planId[trim((string) ($r[11] ?? ''))] ?? null;
            }
        }
        foreach (MatriculaOferta::query()->with('oferta:id,plan_id')->get(['id', 'matricula', 'oferta_id']) as $mo) {
            $planPorMatricula[$mo->matricula] ??= $mo->oferta?->plan_id;
        }

        $materiasDePlan = [];
        $mapaMaterias = function (?int $planEst) use (&$materiasDePlan): array {
            if ($planEst === null) {
                return [];
            }

            return $materiasDePlan[$planEst] ??= PlanMateria::query()->where('plan_id', $planEst)
                ->get(['id', 'clave_en_plan'])->keyBy(fn ($pm) => mb_strtolower((string) $pm->clave_en_plan))->map->id->all();
        };

        // ---- Validación ----
        foreach ($alumnos as [$fila, $r]) {
            $this->requerido('Alumnos', $fila, $r, [0 => 'Nombre', 1 => 'Primer apellido', 3 => 'CURP', 7 => 'Matrícula', 10 => 'Programa académico (clave)', 11 => 'Plan (clave)', 12 => 'Campus (clave)']);
            if (filled($r[3] ?? null) && mb_strlen(trim((string) $r[3])) !== 18) {
                $this->error('Alumnos', $fila, 'La CURP debe tener 18 caracteres.');
            }
            $this->refExiste('Alumnos', $fila, $r[10] ?? null, array_keys($programaAcademicoId), 'El programa académico (clave)');
            $this->refExiste('Alumnos', $fila, $r[11] ?? null, array_keys($planId), 'El plan (clave)');
            $this->refExiste('Alumnos', $fila, $r[12] ?? null, array_keys($campusId), 'El campus (clave)');
            $this->enCatalogo('Alumnos', $fila, $r[13] ?? null, $situaciones, 'Situación', opcional: true);

            if ($this->sinErrorEn('Alumnos', $fila) && $this->resolverOferta($r, $programaAcademicoId, $planId, $campusId) === null) {
                $this->error('Alumnos', $fila, 'No existe una oferta con ese programa académico, plan y campus.');
            }
        }

        $matriculasValidas = array_values(array_unique(array_merge(
            array_filter(array_map(fn ($f) => $this->str($f[1][7] ?? null), $alumnos)),
            MatriculaOferta::query()->pluck('matricula')->all(),
        )));

        foreach ($calif as [$fila, $r]) {
            $this->requerido('Calificaciones', $fila, $r, [0 => 'Matrícula', 1 => 'Materia (clave en el plan)', 3 => 'Ciclo (clave)']);
            $this->refExiste('Calificaciones', $fila, $r[0] ?? null, $matriculasValidas, 'La matrícula');
            $this->refExiste('Calificaciones', $fila, $r[3] ?? null, array_keys($cicloId), 'El ciclo (clave)');

            $mat = trim((string) ($r[0] ?? ''));
            $planEst = $planPorMatricula[$mat] ?? null;
            $mats = $mapaMaterias($planEst);
            if ($planEst !== null && filled($r[1] ?? null) && ! isset($mats[mb_strtolower(trim((string) $r[1]))])) {
                $this->error('Calificaciones', $fila, "La materia «{$r[1]}» no está en el plan del alumno «{$mat}».");
            }
        }

        if ($this->errores !== []) {
            return ['errores' => $this->errores, 'resumen' => []];
        }

        // ---- Creación ----
        $resumen = ['alumnos' => 0, 'matriculas' => 0, 'calificaciones' => 0];
        $situacionDefault = SituacionAlumno::query()->where('clave', 'activo')->value('id') ?? SituacionAlumno::query()->min('id');
        $matriculaOfertaId = [];

        DB::transaction(function () use ($alumnos, $calif, $situaciones, $situacionDefault, $programaAcademicoId, $planId, $campusId, $cicloId, $estatusId, $minimaPlan, $planPorMatricula, $mapaMaterias, &$resumen, &$matriculaOfertaId) {
            foreach ($alumnos as [, $r]) {
                $persona = Persona::query()->updateOrCreate(
                    ['curp' => mb_strtoupper(trim((string) $r[3]))],
                    [
                        'nombre' => trim((string) $r[0]),
                        'primer_apellido' => trim((string) $r[1]),
                        'segundo_apellido' => $this->str($r[2] ?? null),
                        'email' => $this->str($r[4] ?? null),
                        'fecha_nacimiento' => $this->fecha($r[5] ?? null),
                        'celular' => $this->str($r[6] ?? null),
                    ],
                );

                $sit = $this->idOpcional($situaciones, $r[13] ?? null) ?? $situacionDefault;
                Alumno::query()->updateOrCreate(['persona_id' => $persona->id], ['situacion_id' => $sit]);

                $oferta = $this->resolverOferta($r, $programaAcademicoId, $planId, $campusId);
                $matricula = trim((string) $r[7]);
                $m = MatriculaOferta::query()->updateOrCreate(
                    ['matricula' => $matricula],
                    [
                        'persona_id' => $persona->id,
                        'oferta_id' => $oferta->id,
                        'generacion' => $this->str($r[8] ?? null),
                        'fecha_ingreso' => $this->fecha($r[9] ?? null),
                        'situacion_id' => $sit,
                    ],
                );
                $matriculaOfertaId[$matricula] = $m->id;
                $resumen['matriculas']++;
                $resumen['alumnos']++;
            }

            foreach ($calif as [, $r]) {
                $matricula = trim((string) $r[0]);
                $moId = $matriculaOfertaId[$matricula]
                    ?? MatriculaOferta::query()->where('matricula', $matricula)->value('id');
                $planEst = $planPorMatricula[$matricula] ?? null;
                $pmId = $mapaMaterias($planEst)[mb_strtolower(trim((string) $r[1]))] ?? null;

                if ($moId === null || $pmId === null) {
                    continue;
                }

                $calificacion = filled($r[2] ?? null) ? (float) $r[2] : null;
                $claveEstatus = $this->estatus->resolver($calificacion, (float) ($minimaPlan[$planEst] ?? 6))['sugerido'];

                Historial::query()->updateOrCreate(
                    ['matricula_oferta_id' => $moId, 'plan_materia_id' => $pmId, 'ciclo_id' => $cicloId[trim((string) $r[3])] ?? null],
                    ['tipo_evaluacion_id' => 1, 'estatus_id' => $estatusId[$claveEstatus] ?? null, 'calificacion' => $calificacion],
                );
                $resumen['calificaciones']++;
            }
        });

        return ['errores' => [], 'resumen' => $resumen];
    }

    /**
     * @param  array<int, mixed>  $r
     * @param  array<string, int>  $programaAcademicoId
     * @param  array<string, int>  $planId
     * @param  array<string, int>  $campusId
     */
    private function resolverOferta(array $r, array $programaAcademicoId, array $planId, array $campusId): ?Oferta
    {
        $ca = $programaAcademicoId[trim((string) ($r[10] ?? ''))] ?? null;
        $pl = $planId[trim((string) ($r[11] ?? ''))] ?? null;
        $cm = $campusId[trim((string) ($r[12] ?? ''))] ?? null;

        if ($ca === null || $pl === null || $cm === null) {
            return null;
        }

        return Oferta::query()->where('programa_academico_id', $ca)->where('plan_id', $pl)->where('campus_id', $cm)->first();
    }

    /** True si aún no hay ningún error en esa hoja/fila. */
    private function sinErrorEn(string $hoja, int $fila): bool
    {
        foreach ($this->errores as $e) {
            if ($e['hoja'] === $hoja && $e['fila'] === $fila) {
                return false;
            }
        }

        return true;
    }
}
