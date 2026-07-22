<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra un pago y lo aplica a los adeudos que cubre.
 *
 * Todo ocurre en una transacción: un pago cobrado sin aplicar, o adeudos
 * marcados como pagados sin pago que los respalde, son las dos formas de dejar
 * una caja que no cuadra.
 *
 * El estatus del pago NO lo elige quien lo captura: lo dicta
 * `metodos_pago.requiere_confirmacion`. Un pago en ventanilla nace cobrado; una
 * transferencia nace pendiente y solo `confirmar()` la vuelve dinero. Dejarlo a
 * criterio del capturista es cómo se da por pagado un adeudo con dinero que
 * nunca llegó.
 */
class RegistradorPago
{
    /**
     * Registra un pago y lo reparte.
     *
     * @param  array<int, int>|null  $adeudoIds  a cuáles aplicarlo, en orden. Si
     *                                           es null se cubren los más
     *                                           vencidos primero.
     */
    public function registrar(
        int $matriculaOfertaId,
        MetodoPago $metodo,
        float $monto,
        ?array $adeudoIds = null,
        ?string $referencia = null,
        ?string $pasarela = null,
        ?string $pasarelaTxnId = null,
    ): Pago {
        if ($monto <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor que cero.');
        }

        return DB::transaction(function () use (
            $matriculaOfertaId, $metodo, $monto, $adeudoIds, $referencia, $pasarela, $pasarelaTxnId
        ) {
            $pago = Pago::create([
                'matricula_oferta_id' => $matriculaOfertaId,
                'metodo_pago_id' => $metodo->id,
                'monto' => $monto,
                'referencia' => $referencia,
                'pasarela' => $pasarela,
                'pasarela_txn_id' => $pasarelaTxnId,
                'estatus' => $metodo->estatusInicialDePago(),
                'momento' => now(),
            ]);

            $this->aplicar($pago, $this->adeudosACubrir($matriculaOfertaId, $adeudoIds));

            return $pago;
        });
    }

    /**
     * Confirma un pago que estaba esperando el banco o la pasarela. Es lo que
     * convierte la promesa en dinero y, con ello, liquida los adeudos que ya
     * tenía aplicados.
     */
    public function confirmar(Pago $pago): void
    {
        if ($pago->estatus === Pago::ESTATUS_COMPLETADO) {
            return;
        }

        DB::transaction(function () use ($pago) {
            $pago->update(['estatus' => Pago::ESTATUS_COMPLETADO]);

            foreach ($pago->adeudos as $adeudo) {
                $this->actualizarEstatus($adeudo);
            }
        });
    }

    /**
     * Marca un pago como fallido o reembolsado y devuelve los adeudos que
     * cubría a su estado real.
     *
     * La aplicación NO se borra: que un pago se haya intentado y rebotado es
     * parte de la historia de la cuenta, y borrarlo dejaría al alumno
     * preguntando por un cargo que aparecía cubierto la semana pasada.
     */
    public function revertir(Pago $pago, string $estatus = Pago::ESTATUS_FALLIDO): void
    {
        DB::transaction(function () use ($pago, $estatus) {
            $pago->update(['estatus' => $estatus]);

            foreach ($pago->adeudos as $adeudo) {
                $this->actualizarEstatus($adeudo);
            }
        });
    }

    /**
     * Reparte el pago entre los adeudos, del más vencido al menos, hasta
     * agotarlo. Lo que sobre queda sin aplicar (un anticipo) y se ve como tal
     * en el estado de cuenta.
     *
     * @param  Collection<int, Adeudo>  $adeudos
     */
    private function aplicar(Pago $pago, $adeudos): void
    {
        $restante = (float) $pago->monto;

        foreach ($adeudos as $adeudo) {
            if ($restante <= 0) {
                break;
            }

            $saldo = $adeudo->saldo();

            if ($saldo <= 0) {
                continue;
            }

            $aplicado = min($restante, $saldo);

            $pago->adeudos()->attach($adeudo->id, ['monto_aplicado' => round($aplicado, 2)]);

            $restante = round($restante - $aplicado, 2);

            $this->actualizarEstatus($adeudo->refresh());
        }
    }

    /**
     * @return Collection<int, Adeudo>
     */
    private function adeudosACubrir(int $matriculaOfertaId, ?array $adeudoIds)
    {
        $consulta = Adeudo::query()
            ->deMatricula($matriculaOfertaId)
            ->porCobrar();

        if ($adeudoIds !== null) {
            // Se respeta el orden que eligió quien cobra: si el alumno viene a
            // pagar su titulación, no se le aplica a la colegiatura de marzo
            // porque esté más vencida.
            $adeudos = $consulta->whereIn('id', $adeudoIds)->get()->keyBy('id');

            return collect($adeudoIds)
                ->map(fn (int $id) => $adeudos->get($id))
                ->filter()
                ->values();
        }

        return $consulta->orderBy('fecha_vencimiento')->orderBy('id')->get();
    }

    /**
     * El estatus del adeudo se DERIVA de lo aplicado, no se captura. Así no
     * puede quedar un adeudo "pagado" con saldo ni uno "pendiente" ya cubierto.
     *
     * Cancelado y condonado se respetan: son decisiones administrativas y un
     * pago posterior no las revierte solo.
     */
    private function actualizarEstatus(Adeudo $adeudo): void
    {
        if (in_array($adeudo->estatus, [Adeudo::ESTATUS_CANCELADO, Adeudo::ESTATUS_CONDONADO], true)) {
            return;
        }

        $aplicado = $adeudo->montoAplicado();

        $estatus = match (true) {
            $aplicado <= 0 => Adeudo::ESTATUS_PENDIENTE,
            $aplicado >= (float) $adeudo->monto_total => Adeudo::ESTATUS_PAGADO,
            default => Adeudo::ESTATUS_PARCIAL,
        };

        if ($adeudo->estatus !== $estatus) {
            $adeudo->update(['estatus' => $estatus]);
        }
    }
}
