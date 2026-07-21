<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\EstatusHistorial;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\ObservacionHistorial;
use App\Models\ControlEscolar\TipoEvaluacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El asentamiento del acta: el paso que convierte capturas en kárdex.
 *
 * Es la operación más delicada del módulo, porque a partir de aquí la
 * calificación deja de ser editable y pasa a ser historia escolar. Por eso:
 *
 *  - Todo ocurre dentro de UNA transacción. O se asienta el grupo completo con
 *    su folio, o no se asienta nada. Un acta a medias no existe en papel y no
 *    debe existir aquí.
 *  - El folio se genera al CERRAR, no al abrir: un acta que se abandona sin
 *    capturar no debe quemar un número del consecutivo del archivo.
 *  - Corregir no es editar. Una corrección es un acta nueva que apunta a la
 *    original; los renglones de kárdex que aquella asentó se dan de baja
 *    lógica (soft delete) y quedan trazables, nunca se sobreescriben.
 */
class AsentadorActa
{
    public function __construct(
        private readonly CalculadoraCalificacion $calculadora,
        private readonly GeneradorFolioActa $folios,
    ) {}

    /**
     * El acta de trabajo de una materia-grupo: la abierta si ya existe, o una
     * nueva. Todavía sin folio — ese se asigna al cerrar.
     */
    public function actaDeTrabajo(AsignaturaGrupo $materiaGrupo, ?TipoEvaluacion $tipo = null): Acta
    {
        $tipo ??= $this->tipoOrdinaria();

        $abierta = Acta::query()
            ->where('asignatura_grupo_id', $materiaGrupo->id)
            ->where('tipo_evaluacion_id', $tipo->id)
            ->where('situacion', Acta::ABIERTA)
            ->first();

        return $abierta ?? Acta::create([
            'asignatura_grupo_id' => $materiaGrupo->id,
            'tipo_evaluacion_id' => $tipo->id,
            // Placeholder único mientras está abierta: la columna es UNIQUE y
            // el folio real solo se emite al firmar.
            'folio' => 'BORRADOR-'.$materiaGrupo->id.'-'.$tipo->id.'-'.now()->format('YmdHis'),
            'situacion' => Acta::ABIERTA,
        ]);
    }

    /**
     * Motivos por los que el acta NO puede cerrarse. Vacío = adelante.
     *
     * Se devuelven todos, no el primero: el titular necesita ver de una vez a
     * quién le falta capturar, no descubrirlo alumno por alumno.
     *
     * @return array<int, string>
     */
    public function impedimentos(Acta $acta): array
    {
        if ($acta->estaCerrada()) {
            return ['El acta ya está cerrada.'];
        }

        if ($acta->situacion === Acta::CANCELADA) {
            return ['El acta está cancelada.'];
        }

        $materiaGrupo = $acta->asignaturaGrupo;
        $inscripciones = $this->inscripcionesCalificables($materiaGrupo);

        if ($inscripciones->isEmpty()) {
            return ['No hay alumnos inscritos que calificar.'];
        }

        $esquema = $this->esquema($materiaGrupo);
        $plan = $materiaGrupo->planMateria?->plan;
        $motivos = [];

        foreach ($inscripciones as $inscripcion) {
            $resultado = $this->calculadora->calcular($inscripcion, $esquema, $plan);

            if ($resultado->motivo !== null) {
                // El esquema es de la materia, no del alumno: si está mal, el
                // motivo es el mismo para todos y basta decirlo una vez.
                return [$resultado->motivo];
            }

            if (! $resultado->completa) {
                $nombre = $inscripcion->matriculaOferta?->persona?->nombreCompleto() ?? 'un alumno';
                $motivos[] = sprintf('%s: falta capturar %s.', $nombre, implode(', ', $resultado->faltantes));
            }
        }

        return $motivos;
    }

    /**
     * Cierra el acta y vuelca las calificaciones al kárdex.
     *
     * @param  int  $personaId  El docente titular que firma.
     *
     * @throws RuntimeException si el acta no está en condiciones de cerrarse.
     */
    public function cerrar(Acta $acta, int $personaId): Acta
    {
        $impedimentos = $this->impedimentos($acta);

        if ($impedimentos !== []) {
            throw new RuntimeException(implode(' ', $impedimentos));
        }

        return DB::transaction(function () use ($acta, $personaId) {
            $materiaGrupo = $acta->asignaturaGrupo;
            $esquema = $this->esquema($materiaGrupo);
            $plan = $materiaGrupo->planMateria?->plan;

            $aprobada = EstatusHistorial::query()->where('clave', 'aprobada')->firstOrFail();
            $reprobada = EstatusHistorial::query()->where('clave', 'reprobada')->firstOrFail();
            $observacionId = $this->observacionDelActa($acta, $materiaGrupo);

            // Un solo folio para todo el acta, emitido aquí y estampado en cada
            // renglón. Si la transacción falla, el consecutivo se pierde: es
            // preferible un hueco en la numeración a un folio repetido.
            $folio = $this->folios->generar($materiaGrupo);

            // Una corrección sustituye lo que asentó el acta original: sus
            // renglones se dan de baja lógica antes de escribir los nuevos.
            if ($acta->acta_origen_id !== null) {
                Historial::query()->where('acta_id', $acta->acta_origen_id)->delete();
            }

            foreach ($this->inscripcionesCalificables($materiaGrupo) as $inscripcion) {
                $resultado = $this->calculadora->calcular($inscripcion, $esquema, $plan);

                $inscripcion->update(['calificacion_final' => $resultado->final]);

                Historial::create([
                    'matricula_oferta_id' => $inscripcion->matricula_oferta_id,
                    'plan_materia_id' => $materiaGrupo->plan_materia_id,
                    'ciclo_id' => $inscripcion->ciclo_id,
                    'asignatura_grupo_id' => $materiaGrupo->id,
                    'tipo_evaluacion_id' => $this->tipoEvaluacionDelRenglon($acta, $inscripcion),
                    'estatus_id' => $resultado->aprobada ? $aprobada->id : $reprobada->id,
                    'calificacion' => $resultado->final,
                    // El motivo de reprobación (examen, faltas, no presentó) no
                    // lo puede deducir el sistema desde un número: lo asienta
                    // control escolar sobre el renglón.
                    'situacion_reprobatoria_id' => null,
                    'acta_folio' => $folio,
                    'acta_id' => $acta->id,
                    'observacion_id' => $observacionId,
                ]);
            }

            $acta->update([
                'folio' => $folio,
                'situacion' => Acta::CERRADA,
                'cerrada_por' => $personaId,
                'cerrada_en' => now(),
            ]);

            return $acta->refresh();
        });
    }

    /**
     * Abre un acta de corrección sobre una ya cerrada. La original se conserva
     * intacta; al cerrar la corrección, sus renglones de kárdex sustituyen a
     * los de aquella.
     */
    public function abrirCorreccion(Acta $original, string $motivo): Acta
    {
        if (! $original->estaCerrada()) {
            throw new RuntimeException('Solo se corrige un acta cerrada.');
        }

        $enCurso = Acta::query()->where('acta_origen_id', $original->id)
            ->where('situacion', Acta::ABIERTA)
            ->first();

        if ($enCurso !== null) {
            return $enCurso;
        }

        return Acta::create([
            'asignatura_grupo_id' => $original->asignatura_grupo_id,
            'tipo_evaluacion_id' => $original->tipo_evaluacion_id,
            'folio' => 'BORRADOR-CORR-'.$original->id.'-'.now()->format('YmdHis'),
            'situacion' => Acta::ABIERTA,
            'acta_origen_id' => $original->id,
            'observaciones' => $motivo,
        ]);
    }

    /**
     * Los alumnos que entran al acta. Una baja no se califica: dejó de cursar.
     *
     * @return Collection<int, Inscripcion>
     */
    public function inscripcionesCalificables(AsignaturaGrupo $materiaGrupo): Collection
    {
        return Inscripcion::query()
            ->with([
                'calificaciones',
                'situacion:id,clave,nombre',
                'matriculaOferta:id,persona_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->where('asignatura_grupo_id', $materiaGrupo->id)
            ->whereHas('situacion', fn ($q) => $q->where('clave', '!=', 'baja'))
            ->get()
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->values();
    }

    /**
     * @return Collection<int, \App\Models\Academico\EsquemaEvaluacion>
     */
    public function esquema(AsignaturaGrupo $materiaGrupo): Collection
    {
        $materiaGrupo->loadMissing(['planMateria.esquemaEvaluacion', 'planMateria.plan']);

        return ($materiaGrupo->planMateria?->esquemaEvaluacion ?? collect())
            ->sortBy(['orden', 'id'])
            ->values();
    }

    /**
     * Un recursamiento se asienta como tal en el kárdex aunque el acta sea la
     * ordinaria del grupo: el catálogo `tipos_evaluacion` distingue ambos y el
     * historial debe reflejar cómo se cursó realmente.
     */
    private function tipoEvaluacionDelRenglon(Acta $acta, Inscripcion $inscripcion): int
    {
        if ($inscripcion->tipo !== Inscripcion::TIPO_RECURSAMIENTO) {
            return $acta->tipo_evaluacion_id;
        }

        return TipoEvaluacion::query()->where('clave', 'recursamiento')->value('id')
            ?? $acta->tipo_evaluacion_id;
    }

    /**
     * Un acta firmada después del límite de captura del ciclo se marca como
     * extemporánea en el kárdex, y una corrección como corrección. Es lo que
     * el catálogo `observaciones_historial` ya preveía.
     */
    private function observacionDelActa(Acta $acta, AsignaturaGrupo $materiaGrupo): ?int
    {
        $clave = match (true) {
            $acta->acta_origen_id !== null => 'correccion_calificacion',
            $this->fueraDeVentana($materiaGrupo) => 'acta_extemporanea',
            default => 'sin_observacion',
        };

        return ObservacionHistorial::query()->where('clave', $clave)->value('id');
    }

    private function fueraDeVentana(AsignaturaGrupo $materiaGrupo): bool
    {
        $limite = $materiaGrupo->grupo?->ciclo?->captura_calif_hasta;

        return $limite !== null && now()->toDateString() > $limite->toDateString();
    }

    private function tipoOrdinaria(): TipoEvaluacion
    {
        return TipoEvaluacion::query()->where('clave', 'ordinaria')->firstOrFail();
    }
}
