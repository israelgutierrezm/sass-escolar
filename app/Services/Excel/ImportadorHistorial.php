<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\EstatusHistorial;
use App\Models\ControlEscolar\Historial;
use App\Services\EstatusAcademico;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga masiva de historial académico para un plan: por cada fila (matrícula, materia,
 * calificación, ciclo) crea/actualiza el renglón de historial. La matrícula
 * debe ser de ese plan y la materia estar en él; el estatus se deriva de la
 * calificación con la regla única del sistema. No crea nada si hay errores.
 */
class ImportadorHistorial extends ImportadorBase
{
    public function __construct(private EstatusAcademico $estatus) {}

    /** @return array{errores: array<int, mixed>, resumen: array<string, int>} */
    public function importar(string $path, PlanEstudio $plan): array
    {
        $this->errores = [];
        $libro = IOFactory::load($path);

        $cicloId = Ciclo::query()->pluck('id', 'clave')->all();
        $estatusId = EstatusHistorial::query()->pluck('id', 'clave')->all();
        $minima = (float) ($plan->calificacion_minima_aprobatoria ?? 6);

        // Materias del plan por clave, y matrículas que pertenecen a este plan.
        $materias = PlanMateria::query()->where('plan_id', $plan->id)
            ->get(['id', 'clave_en_plan'])->keyBy(fn ($pm) => mb_strtolower((string) $pm->clave_en_plan))->map->id->all();

        $matriculas = MatriculaOferta::query()->whereHas('oferta', fn ($q) => $q->where('plan_id', $plan->id))
            ->pluck('id', 'matricula')->all();

        $filas = $this->leer($libro, 'Historial académico');

        foreach ($filas as [$fila, $r]) {
            $this->requerido('Historial académico', $fila, $r, [0 => 'Matrícula', 1 => 'Materia (clave en el plan)', 3 => 'Ciclo (clave)']);
            if (filled($r[0] ?? null) && ! isset($matriculas[trim((string) $r[0])])) {
                $this->error('Historial académico', $fila, "La matrícula «{$r[0]}» no existe en este plan.");
            }
            if (filled($r[1] ?? null) && ! isset($materias[mb_strtolower(trim((string) $r[1]))])) {
                $this->error('Historial académico', $fila, "La materia «{$r[1]}» no está en el plan.");
            }
            $this->refExiste('Historial académico', $fila, $r[3] ?? null, array_keys($cicloId), 'El ciclo (clave)');
        }

        if ($this->errores !== []) {
            return ['errores' => $this->errores, 'resumen' => []];
        }

        $n = 0;
        DB::transaction(function () use ($filas, $matriculas, $materias, $cicloId, $estatusId, $minima, &$n) {
            foreach ($filas as [, $r]) {
                $calificacion = filled($r[2] ?? null) ? (float) $r[2] : null;
                $claveEstatus = $this->estatus->resolver($calificacion, $minima)['sugerido'];

                Historial::query()->updateOrCreate(
                    [
                        'matricula_oferta_id' => $matriculas[trim((string) $r[0])],
                        'plan_materia_id' => $materias[mb_strtolower(trim((string) $r[1]))],
                        'ciclo_id' => $cicloId[trim((string) $r[3])] ?? null,
                    ],
                    ['tipo_evaluacion_id' => 1, 'estatus_id' => $estatusId[$claveEstatus] ?? null, 'calificacion' => $calificacion],
                );
                $n++;
            }
        });

        return ['errores' => [], 'resumen' => ['calificaciones' => $n]];
    }
}
