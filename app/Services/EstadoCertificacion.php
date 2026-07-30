<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Emision\Certificacion;

/**
 * Responde dos preguntas sobre una matrícula-carrera: ¿ya cerró su plan (está
 * lista para certificar)? y ¿tiene una certificación (emitida o en un lote)?
 *
 * Es la fuente única de la regla de elegibilidad, para que el buscador de
 * candidatos del lote y el panel del expediente digan lo mismo.
 */
class EstadoCertificacion
{
    /** @var array<int, int> caché de meta de materias por plan dentro del request */
    private array $metaPorPlan = [];

    /**
     * Cuántas materias distintas exige el plan para cerrarse. Si no fija
     * `minimo_asignaturas`, se cae al número de materias de su malla.
     */
    public function metaMaterias(?PlanEstudio $plan): int
    {
        if ($plan === null) {
            return 0;
        }

        return $this->metaPorPlan[$plan->id] ??= (int) ($plan->minimo_asignaturas
            ?: PlanMateria::query()->where('plan_id', $plan->id)->count());
    }

    /** Materias distintas aprobadas (basta un intento aprobado por materia). */
    public function aprobadasDistintas(int $matriculaId): int
    {
        return Historial::query()
            ->where('matricula_oferta_id', $matriculaId)
            ->whereNotNull('plan_materia_id')
            ->whereHas('estatus', fn ($q) => $q->where('clave', 'aprobada'))
            ->distinct()
            ->count('plan_materia_id');
    }

    /** Cerró su plan: aprobó al menos las materias que exige. */
    public function disponible(MatriculaOferta $matricula): bool
    {
        $meta = $this->metaMaterias($matricula->oferta?->plan);

        return $meta > 0 && $this->aprobadasDistintas($matricula->id) >= $meta;
    }

    /**
     * La certificación que «ocupa» a la matrícula: la emitida, o la pendiente en
     * un lote aún sin firmar. Un renglón en `error` no ocupa (se puede reintentar
     * metiéndola a otro lote).
     */
    public function certificacionVigente(int $matriculaId): ?Certificacion
    {
        return Certificacion::query()
            ->with('lote:id,folio,estado')
            ->where('matricula_oferta_id', $matriculaId)
            ->where('estado', '!=', Certificacion::ERROR)
            ->latest('id')
            ->first();
    }

    /** Se puede agregar a un lote: cerró su plan y no está ya en uno. */
    public function elegibleParaLote(MatriculaOferta $matricula): bool
    {
        return $this->disponible($matricula)
            && $this->certificacionVigente($matricula->id) === null;
    }
}
