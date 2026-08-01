<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Entrega;
use Illuminate\Support\Collection;

/**
 * Lleva las calificaciones de actividades al componente ponderado.
 *
 * Decisión del usuario: la calificación de una actividad ENTRA SOLA. El docente
 * califica actividades y el componente del parcial se recalcula; no hay que
 * capturar el número dos veces ni recordar hacerlo.
 *
 * Dos reglas que evitan que eso muerda:
 *
 * 1. **Lo capturado a mano no se pisa.** Si el componente tiene fuente
 *    `manual`, el calculador lo respeta. Un ajuste del docente —un caso
 *    especial, una corrección— sobrevive al siguiente recálculo. Sin esto,
 *    calificar cualquier actividad borraría el criterio humano en silencio.
 *
 * 2. **Solo promedia lo ya calificado.** Una actividad sin calificar no cuenta
 *    como cero: cuenta como que todavía no pasó. Si contara como cero, un
 *    alumno al corriente aparecería reprobado a media materia.
 */
class CalculadorComponente
{
    /**
     * Recalcula el componente al que pertenece esta actividad, para el alumno
     * que acaba de ser calificado.
     */
    public function tras(Entrega $entrega): ?CalificacionComponente
    {
        $actividad = $entrega->actividad ?? Actividad::find($entrega->actividad_id);

        if ($actividad === null || ! $actividad->pondera()) {
            return null;
        }

        return $this->recalcular(
            (int) $entrega->inscripcion_id,
            (int) $actividad->esquema_evaluacion_id,
        );
    }

    /**
     * Recalcula UN componente de UN alumno a partir de sus actividades.
     *
     * La calificación del componente es el promedio ponderado por los puntos de
     * cada actividad, llevado a escala 0–10: una tarea de 40 puntos pesa cuatro
     * veces más que una de 10 dentro del mismo componente.
     */
    public function recalcular(int $inscripcionId, int $esquemaId): ?CalificacionComponente
    {
        $existente = CalificacionComponente::query()
            ->where('inscripcion_id', $inscripcionId)
            ->where('esquema_evaluacion_id', $esquemaId)
            ->first();

        // Lo que un humano fijó, un humano lo cambia.
        if ($existente !== null && $existente->fuente === 'manual') {
            return $existente;
        }

        $calificadas = $this->entregasCalificadas($inscripcionId, $esquemaId);

        // Nada calificado todavía: se retira lo que hubiera calculado antes en
        // vez de dejar un número viejo que ya no corresponde a nada.
        if ($calificadas->isEmpty()) {
            $existente?->delete();

            return null;
        }

        $puntosPosibles = $calificadas->sum(fn (Entrega $e) => (float) $e->actividad->puntos);

        if ($puntosPosibles <= 0) {
            return $existente;
        }

        $obtenidos = $calificadas->sum(fn (Entrega $e) => (float) $e->calificacion);
        $enDiez = round($obtenidos * 10 / $puntosPosibles, 2);

        return CalificacionComponente::updateOrCreate(
            ['inscripcion_id' => $inscripcionId, 'esquema_evaluacion_id' => $esquemaId],
            [
                'calificacion' => $enDiez,
                'fuente' => 'calculado',
                'capturado_en' => now(),
            ],
        );
    }

    /** Recalcula TODOS los componentes ponderados de un alumno en su materia. */
    public function recalcularInscripcion(Inscripcion $inscripcion): void
    {
        $esquemas = Actividad::query()
            ->whereNotNull('esquema_evaluacion_id')
            ->whereHas('curso', fn ($q) => $q->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id))
            ->distinct()
            ->pluck('esquema_evaluacion_id');

        foreach ($esquemas as $esquemaId) {
            $this->recalcular((int) $inscripcion->id, (int) $esquemaId);
        }
    }

    /**
     * Las entregas YA CALIFICADAS de ese alumno en las actividades de ese
     * componente. Con su actividad cargada: hace falta el peso en puntos.
     *
     * @return Collection<int, Entrega>
     */
    private function entregasCalificadas(int $inscripcionId, int $esquemaId): Collection
    {
        return Entrega::query()
            ->with('actividad:id,puntos,esquema_evaluacion_id')
            ->where('inscripcion_id', $inscripcionId)
            ->whereNotNull('calificacion')
            ->whereHas('actividad', fn ($q) => $q->where('esquema_evaluacion_id', $esquemaId))
            ->get();
    }
}
