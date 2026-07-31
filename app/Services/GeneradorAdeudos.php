<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\PlanCobroAlumno;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * El motor de cobro: convierte las líneas del plan en los cargos del alumno.
 *
 * Vincular un plan a un alumno es lo que dispara la generación: se crea un
 * adeudo por cada línea del plan, ya con becas y descuentos aplicados y con su
 * desglose en `adeudo_ajustes`. No se espera a que el alumno se inscriba al
 * ciclo, porque en muchas escuelas pagar es el requisito PARA inscribirse.
 *
 * **Idempotente.** Correrlo dos veces no duplica: la pareja (matrícula, línea)
 * se comprueba antes de crear. Si más adelante se agregan líneas al plan, volver
 * a generar crea solo las que faltan.
 */
class GeneradorAdeudos
{
    public function __construct(
        private readonly CalculadorCargo $calculador,
    ) {}

    /**
     * Vincula el plan a los alumnos y les genera sus cargos.
     *
     * @param  array<int, int>  $matriculaIds
     * @return array{asignados: int, cargos: int, omitidos: int}
     */
    public function asignarPlan(PlanCobro $plan, array $matriculaIds): array
    {
        $plan->loadMissing('conceptos');

        $asignados = 0;
        $cargos = 0;
        $omitidos = 0;

        foreach (array_unique($matriculaIds) as $id) {
            $matricula = MatriculaOferta::find($id);

            if ($matricula === null) {
                $omitidos++;

                continue;
            }

            DB::transaction(function () use ($plan, $matricula, &$asignados, &$cargos) {
                $asignacion = PlanCobroAlumno::firstOrNew([
                    'plan_cobro_id' => $plan->id,
                    'matricula_oferta_id' => $matricula->id,
                ]);

                $eraNueva = ! $asignacion->exists;

                $asignacion->fill([
                    'estatus' => PlanCobroAlumno::ACTIVO,
                    'asignado_en' => now(),
                    'asignado_por' => Auth::id(),
                ])->save();

                if ($eraNueva) {
                    $asignados++;
                }

                $cargos += $this->generarCargos($plan, $matricula);
            });
        }

        return ['asignados' => $asignados, 'cargos' => $cargos, 'omitidos' => $omitidos];
    }

    /**
     * Crea los adeudos que le faltan a este alumno para este plan.
     * Devuelve cuántos creó.
     */
    public function generarCargos(PlanCobro $plan, MatriculaOferta $matricula): int
    {
        $creados = 0;

        foreach ($plan->conceptos as $linea) {
            $yaExiste = Adeudo::withTrashed()
                ->where('matricula_oferta_id', $matricula->id)
                ->where('concepto_plan_id', $linea->id)
                ->exists();

            if ($yaExiste) {
                continue;
            }

            $this->crearAdeudo($plan, $linea, $matricula);
            $creados++;
        }

        return $creados;
    }

    /** Crea el adeudo de una línea, con su desglose de becas y descuentos. */
    private function crearAdeudo(PlanCobro $plan, ConceptoPlan $linea, MatriculaOferta $matricula): Adeudo
    {
        $calculo = $this->calculador->para($linea, $matricula);
        $descuentos = abs(array_sum(array_column($calculo['ajustes'], 'monto')));

        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $linea->concepto_id,
            'concepto_plan_id' => $linea->id,
            'ciclo_id' => $plan->ciclo_id,
            'periodo_etiqueta' => $linea->periodoEtiqueta(),
            'monto' => $calculo['monto'],
            'monto_recargos' => 0,
            'monto_descuentos' => $descuentos,
            'monto_total' => $calculo['total'],
            'fecha_generacion' => now()->toDateString(),
            // Sin fecha límite configurada, el cargo vence al cierre del ciclo o,
            // en su defecto, el mismo día: nunca queda sin vencimiento porque la
            // cartera se ordena por esa fecha.
            'fecha_vencimiento' => $linea->fecha_limite?->toDateString()
                ?? $plan->ciclo?->fecha_fin?->toDateString()
                ?? now()->toDateString(),
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);

        foreach ($calculo['ajustes'] as $ajuste) {
            AdeudoAjuste::create($ajuste + ['adeudo_id' => $adeudo->id]);
        }

        return $adeudo;
    }

    /**
     * Recalcula los cargos PENDIENTES de un alumno tras un cambio de beca.
     *
     * Los ya pagados no se tocan: el dinero que entró no se reescribe. Solo se
     * recomponen los que aún se le pueden cobrar distinto.
     */
    public function recalcularPendientes(MatriculaOferta $matricula): int
    {
        $adeudos = Adeudo::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('estatus', Adeudo::ESTATUS_PENDIENTE)
            ->whereNotNull('concepto_plan_id')
            ->with('conceptoPlan')
            ->get();

        $tocados = 0;

        foreach ($adeudos as $adeudo) {
            $linea = $adeudo->conceptoPlan;

            if ($linea === null) {
                continue;
            }

            DB::transaction(function () use ($adeudo, $linea, $matricula, &$tocados) {
                // Se rehacen solo los beneficios; el recargo por mora lo mantiene
                // su propio servicio y no debe perderse en el recálculo.
                $adeudo->ajustes()
                    ->whereIn('tipo', [AdeudoAjuste::TIPO_BECA, AdeudoAjuste::TIPO_DESCUENTO])
                    ->delete();

                $calculo = $this->calculador->para($linea, $matricula);

                foreach ($calculo['ajustes'] as $ajuste) {
                    AdeudoAjuste::create($ajuste + ['adeudo_id' => $adeudo->id]);
                }

                $descuentos = abs(array_sum(array_column($calculo['ajustes'], 'monto')));
                $recargos = (float) $adeudo->monto_recargos;

                $adeudo->update([
                    'monto' => $calculo['monto'],
                    'monto_descuentos' => $descuentos,
                    'monto_total' => max(0, round($calculo['total'] + $recargos, 2)),
                ]);

                $tocados++;
            });
        }

        return $tocados;
    }

    /** Vuelve a generar lo que falte para todos los planes activos del alumno. */
    public function generarPara(MatriculaOferta $matricula): array
    {
        $planes = PlanCobro::query()
            ->whereHas('asignaciones', fn ($q) => $q
                ->where('matricula_oferta_id', $matricula->id)
                ->where('estatus', PlanCobroAlumno::ACTIVO))
            ->with(['conceptos', 'ciclo'])
            ->get();

        $generados = 0;

        foreach ($planes as $plan) {
            $generados += $this->generarCargos($plan, $matricula);
        }

        return ['generados' => $generados, 'planes' => $planes->count()];
    }
}
