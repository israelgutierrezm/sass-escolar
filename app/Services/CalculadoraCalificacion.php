<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Support\Collection;

/**
 * Calcula la calificación final desde el esquema de evaluación del plan.
 *
 * La regla es la de la spec: cada fila de `esquema_evaluacion` aporta su
 * `porcentaje` del total, y el resultado es la suma ponderada de lo capturado
 * en `calificaciones_componente`.
 *
 * Tres decisiones que este servicio sostiene:
 *
 *  1. **NULL no es cero.** Un componente sin capturar deja la calificación
 *     INCOMPLETA; no se pondera como 0. Un cero es una calificación (el alumno
 *     no presentó); un NULL es que el docente todavía no llega ahí. Cerrar un
 *     acta con la diferencia borrada sería reprobar gente por descuido.
 *  2. **El esquema manda y se verifica.** Si los porcentajes no suman 100 el
 *     resultado no se calcula: se reporta el motivo. Vale más una materia sin
 *     calificación que un kárdex con números que nadie puede reproducir.
 *  3. **Aprobado lo define el plan**, con su `calificacion_minima_aprobatoria`,
 *     no una constante del código: cada plan tiene su escala.
 *
 * Recibe el esquema ya cargado en vez de resolverlo por alumno, porque en la
 * hoja de captura los 40 alumnos de un grupo comparten la misma materia-en-plan
 * y volver a consultarlo por cada uno sería un N+1 gratuito.
 */
class CalculadoraCalificacion
{
    /** Tolerancia al comparar la suma de porcentajes contra 100. */
    private const EPSILON = 0.01;

    /**
     * @param  Collection<int, \App\Models\Academico\EsquemaEvaluacion>  $esquema
     */
    public function calcular(Inscripcion $inscripcion, Collection $esquema, ?PlanEstudio $plan): ResultadoCalificacion
    {
        if ($esquema->isEmpty()) {
            return ResultadoCalificacion::sinEsquema(
                'La materia no tiene esquema de evaluación configurado en su plan.'
            );
        }

        $suma = (float) $esquema->sum(fn ($componente) => (float) $componente->porcentaje);

        if (abs($suma - 100.0) > self::EPSILON) {
            return ResultadoCalificacion::sinEsquema(
                sprintf('El esquema de evaluación suma %s%%, debe sumar 100%%.', rtrim(rtrim(number_format($suma, 2, '.', ''), '0'), '.'))
            );
        }

        // Lo capturado, indexado por componente para no recorrer la colección
        // una vez por fila del esquema.
        $capturadas = $inscripcion->calificaciones
            ->keyBy('esquema_evaluacion_id');

        $acumulado = 0.0;
        $faltantes = [];

        foreach ($esquema as $componente) {
            /** @var CalificacionComponente|null $capturada */
            $capturada = $capturadas->get($componente->id);
            $valor = $capturada?->calificacion;

            if ($valor === null) {
                $faltantes[] = $componente->componente;

                continue;
            }

            $acumulado += (float) $valor * ((float) $componente->porcentaje / 100);
        }

        $completa = $faltantes === [];
        $final = round($acumulado, 2);
        $minima = (float) ($plan?->calificacion_minima_aprobatoria ?? 0);

        return new ResultadoCalificacion(
            final: $final,
            completa: $completa,
            faltantes: $faltantes,
            // Solo se dictamina cuando ya está toda: un parcial de 10 sobre un
            // 30% no aprueba la materia, y decirlo confundiría al docente.
            aprobada: $completa ? $final >= $minima : null,
        );
    }
}
