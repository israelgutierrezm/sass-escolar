<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\Academico\PlanEstudio;
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
    /** @var array<int, PlanEstudio|null> */
    private array $planes = [];

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
     * cada actividad, llevado a la escala del plan: una tarea de 40 puntos pesa
     * cuatro veces más que una de 10 dentro del mismo componente.
     */
    public function recalcular(int $inscripcionId, int $esquemaId): ?CalificacionComponente
    {
        $existente = CalificacionComponente::query()
            ->where('inscripcion_id', $inscripcionId)
            ->where('esquema_evaluacion_id', $esquemaId)
            ->first();

        /*
         * Lo que un humano fijó, un humano lo cambia. Pero una fila manual SIN
         * calificación no fijó nada: es el hueco que deja la pantalla de captura
         * al abrirse, y las crea para los seis componentes aunque no se escriba
         * ninguno. Tratarlas como criterio humano dejaba al LMS sin poder
         * alimentar el parcial en cualquier materia donde alguien hubiera
         * entrado alguna vez a capturar.
         */
        if ($existente !== null && $existente->fuente === 'manual' && $existente->calificacion !== null) {
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

        /*
         * En la escala del PLAN, no en 0–10. Este número entra solo al parcial
         * y de ahí al acta, así que convertirlo mal no se nota: se asienta.
         */
        $calificacion = PlanEstudio::enEscalaCon(
            $this->planDe($inscripcionId),
            $obtenidos,
            $puntosPosibles,
        );

        if ($calificacion === null) {
            return $existente;
        }

        /*
         * Revive si estaba borrada. Este mismo método la borra unas líneas
         * arriba cuando no queda nada calificado, así que el ciclo
         * «calificar → descalificar → volver a calificar» es normal aquí: con
         * `updateOrCreate` la segunda vuelta reventaba contra el unique, porque
         * el scope de borrado esconde la fila que la base sí ve.
         */
        return CalificacionComponente::actualizarOReviver(
            ['inscripcion_id' => $inscripcionId, 'esquema_evaluacion_id' => $esquemaId],
            [
                'calificacion' => $calificacion,
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
     * El plan de la materia en la que está inscrito el alumno.
     *
     * Es de dónde sale la escala. Se busca por la inscripción y no se recibe
     * por parámetro porque este calculador lo llama el LMS desde un observer,
     * donde lo único que hay a la mano es la entrega.
     */
    private function planDe(int $inscripcionId): ?PlanEstudio
    {
        // Memoria por inscripción: `recalcularInscripcion` pasa por aquí una vez
        // por componente —hasta seis— y el plan es el mismo en todos.
        if (array_key_exists($inscripcionId, $this->planes)) {
            return $this->planes[$inscripcionId];
        }

        return $this->planes[$inscripcionId] = Inscripcion::query()
            ->with('asignaturaGrupo.planMateria.plan')
            ->find($inscripcionId)
            ?->asignaturaGrupo?->planMateria?->plan;
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
