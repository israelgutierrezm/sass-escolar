<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\RecargoDescuento;
use Carbon\CarbonImmutable;

/**
 * Lo que modifica el monto de un adeudo: becas y descuentos hacia abajo,
 * recargos por mora hacia arriba.
 *
 * Regla central: `monto_total = monto + monto_recargos − monto_descuentos`, y
 * las tres columnas se conservan por separado. No se guarda solo el neto porque
 * la pregunta que llega a ventanilla es "¿por qué me cobran 2 300 si la
 * colegiatura son 2 000?", y un solo número no la responde.
 */
class AplicadorRecargosDescuentos
{
    /**
     * Descuento total que le corresponde a una matrícula sobre un monto, por
     * las becas vigentes a esa fecha.
     *
     * Los descuentos se acumulan pero NUNCA superan el monto: una beca del 60%
     * más otra del 60% dejan el adeudo en cero, no en negativo. Un adeudo
     * negativo sería un saldo a favor, que es otra cosa y no se inventa aquí.
     */
    public function descuentoPara(int $matriculaOfertaId, float $monto, ?CarbonImmutable $fecha = null): float
    {
        $fecha ??= CarbonImmutable::today();

        $becas = BecaAlumno::query()
            ->with('recargoDescuento')
            ->where('matricula_oferta_id', $matriculaOfertaId)
            ->vigentes($fecha->toDateString())
            ->get();

        $descuento = 0.0;

        foreach ($becas as $beca) {
            $regla = $beca->recargoDescuento;

            if ($regla === null || ! $regla->activo || $regla->tipo === RecargoDescuento::TIPO_RECARGO) {
                continue;
            }

            $descuento += $regla->calcularSobre($monto);
        }

        return round(min($descuento, $monto), 2);
    }

    /**
     * Recargo por mora que le toca hoy a un adeudo vencido.
     *
     * Solo aplican los recargos activos de tipo `recargo`. `dias_gracia` es el
     * colchón antes de que empiece a correr: vencer ayer no es lo mismo que
     * llevar un mes debiendo, y casi ninguna escuela cobra mora al día
     * siguiente.
     *
     * El recargo se calcula sobre el monto BASE, no sobre el total ya
     * recargado: capitalizar la mora es otra decisión de negocio, y sería una
     * que nadie tomó explícitamente.
     */
    public function recargoPorMora(Adeudo $adeudo, ?CarbonImmutable $fecha = null): float
    {
        $fecha ??= CarbonImmutable::today();

        if (! $adeudo->estaVencido($fecha->toDateString())) {
            return 0.0;
        }

        $diasDeMora = $adeudo->fecha_vencimiento->diffInDays($fecha);
        $recargo = 0.0;

        $reglas = RecargoDescuento::query()
            ->activos()
            ->deTipo(RecargoDescuento::TIPO_RECARGO)
            ->get();

        foreach ($reglas as $regla) {
            if ($diasDeMora <= (int) ($regla->dias_gracia ?? 0)) {
                continue;
            }

            $recargo += $regla->calcularSobre((float) $adeudo->monto);
        }

        return round($recargo, 2);
    }

    /**
     * Recalcula recargos y total de un adeudo. Devuelve si algo cambió.
     *
     * Un adeudo pagado, cancelado o condonado NO se toca: el recargo se cobra
     * mientras se debe, y volver a moverle el monto a algo ya liquidado
     * descuadraría lo que el alumno pagó contra lo que decía su recibo.
     */
    public function recalcular(Adeudo $adeudo, ?CarbonImmutable $fecha = null): bool
    {
        if (! in_array($adeudo->estatus, [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL], true)) {
            return false;
        }

        $recargo = $this->recargoPorMora($adeudo, $fecha);
        $total = round((float) $adeudo->monto + $recargo - (float) $adeudo->monto_descuentos, 2);

        if ((float) $adeudo->monto_recargos === $recargo && (float) $adeudo->monto_total === $total) {
            return false;
        }

        $adeudo->update([
            'monto_recargos' => $recargo,
            'monto_total' => $total,
        ]);

        return true;
    }

    /**
     * Pasa la mora sobre la cartera de una matrícula (o de toda la escuela).
     *
     * @return int cuántos adeudos cambiaron de monto
     */
    public function recalcularCartera(?int $matriculaOfertaId = null, ?CarbonImmutable $fecha = null): int
    {
        $fecha ??= CarbonImmutable::today();

        $consulta = Adeudo::query()
            ->porCobrar()
            ->whereDate('fecha_vencimiento', '<', $fecha->toDateString());

        if ($matriculaOfertaId !== null) {
            $consulta->where('matricula_oferta_id', $matriculaOfertaId);
        }

        $cambiados = 0;

        foreach ($consulta->cursor() as $adeudo) {
            if ($this->recalcular($adeudo, $fecha)) {
                $cambiados++;
            }
        }

        return $cambiados;
    }
}
