<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\ReglaRecargo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El recargo por mora de un cargo vencido.
 *
 * La regla sale del plan, con override opcional por concepto. Dos cosas se
 * respetan siempre:
 *
 *  - Un plan que no permite recargos no recarga nada, aunque una línea suelta
 *    venga marcada (`aplica_recargos` del plan manda).
 *  - En `mensual_acumulativa` el recargo se RECALCULA por completo cada vez, no
 *    se suma sobre lo anterior: así correr el proceso dos veces el mismo día no
 *    infla la deuda, que es el error clásico de estos motores.
 */
class CalculadorRecargos
{
    /** Recargo que le toca hoy a un cargo. Devuelve 0 si no aplica. */
    public function recargoPara(Adeudo $adeudo, ?CarbonImmutable $hoy = null): float
    {
        $hoy ??= CarbonImmutable::today();

        if (! in_array($adeudo->estatus, [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL], true)) {
            return 0.0;
        }

        $linea = $adeudo->conceptoPlan;

        if ($linea === null || ! $linea->aplica_recargos) {
            return 0.0;
        }

        $plan = $linea->plan;

        if ($plan === null || ! $plan->aplica_recargos) {
            return 0.0;
        }

        $regla = $this->reglaPara($plan, $linea->id);

        if ($regla === null) {
            return 0.0;
        }

        $vence = CarbonImmutable::parse($adeudo->fecha_vencimiento);

        // El modo del plan decide si la mora corre desde el día marcado o desde
        // el siguiente; la gracia de la regla se suma encima.
        if ($plan->fecha_limite_modo === PlanCobro::LIMITE_DIA_SIGUIENTE) {
            $vence = $vence->addDay();
        }

        $inicioMora = $vence->addDays($regla->dias_gracia);

        if ($hoy->lessThanOrEqualTo($inicioMora)) {
            return 0.0;
        }

        $periodos = $regla->frecuencia === ReglaRecargo::FRECUENCIA_MENSUAL
            ? max(1, (int) ceil($inicioMora->diffInDays($hoy) / 30))
            : 1;

        // El recargo se calcula sobre lo que realmente se debe, no sobre el
        // bruto: quien ya abonó la mitad no debe recargar por el total.
        $base = (float) $adeudo->monto_total - $this->pagado($adeudo);

        return $base > 0 ? $regla->calcular($base, $periodos) : 0.0;
    }

    /**
     * Deja el recargo del cargo en su valor correcto de hoy (lo crea, lo
     * actualiza o lo quita) y recompone el total. Devuelve true si cambió algo.
     */
    public function recalcular(Adeudo $adeudo, ?CarbonImmutable $hoy = null): bool
    {
        $recargo = $this->recargoPara($adeudo, $hoy);
        $actual = (float) $adeudo->monto_recargos;

        if (abs($recargo - $actual) < 0.01) {
            return false;
        }

        DB::transaction(function () use ($adeudo, $recargo) {
            $adeudo->ajustes()->where('tipo', AdeudoAjuste::TIPO_RECARGO)->delete();

            if ($recargo > 0) {
                AdeudoAjuste::create([
                    'adeudo_id' => $adeudo->id,
                    'tipo' => AdeudoAjuste::TIPO_RECARGO,
                    'origen_id' => null,
                    'etiqueta' => 'Recargo por mora',
                    'monto' => $recargo, // positivo: suma
                    'periodo_aplicado' => now()->format('Y-m'),
                ]);
            }

            $base = (float) $adeudo->monto - (float) $adeudo->monto_descuentos;

            $adeudo->update([
                'monto_recargos' => $recargo,
                'monto_total' => max(0, round($base + $recargo, 2)),
            ]);
        });

        return true;
    }

    /** Recalcula la cartera completa (o la de un alumno). Devuelve cuántos cambió. */
    public function recalcularCartera(?int $matriculaOfertaId = null, ?CarbonImmutable $hoy = null): int
    {
        $tocados = 0;

        Adeudo::query()
            ->whereIn('estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL])
            ->whereNotNull('concepto_plan_id')
            ->when($matriculaOfertaId !== null, fn (Builder $q) => $q->where('matricula_oferta_id', $matriculaOfertaId))
            ->with('conceptoPlan.plan')
            ->chunkById(200, function ($adeudos) use (&$tocados, $hoy) {
                foreach ($adeudos as $adeudo) {
                    if ($this->recalcular($adeudo, $hoy)) {
                        $tocados++;
                    }
                }
            });

        return $tocados;
    }

    /** El override del concepto gana sobre la regla base del plan. */
    private function reglaPara(PlanCobro $plan, int $conceptoPlanId): ?ReglaRecargo
    {
        return ReglaRecargo::query()
            ->where('plan_cobro_id', $plan->id)
            ->where('activo', true)
            ->where(fn (Builder $q) => $q
                ->where('concepto_plan_id', $conceptoPlanId)
                ->orWhereNull('concepto_plan_id'))
            // El override primero: NULL ordena al final.
            ->orderByRaw('concepto_plan_id IS NULL')
            ->first();
    }

    private function pagado(Adeudo $adeudo): float
    {
        return (float) $adeudo->pagos()->sum('pago_adeudo.monto_aplicado');
    }
}
