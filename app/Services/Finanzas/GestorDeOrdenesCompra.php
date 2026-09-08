<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\CuentaPorPagar;
use App\Models\Finanzas\OrdenCompra;
use Illuminate\Support\Facades\DB;

/**
 * La máquina de estados de una orden de compra, en UN sitio.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Autorizar la vuelve un compromiso; RECIBIRLA genera la cuenta por pagar (la
 * obligación de pagar lo recibido). La OC no crea egreso —el egreso nace al pagar
 * la CxP—, así que el ejercido sigue teniendo una sola fuente.
 */
class GestorDeOrdenesCompra
{
    /** Autoriza una OC en borrador: la vuelve un compromiso. */
    public function autorizar(OrdenCompra $oc, int $personaId): void
    {
        AvisoParaElUsuario::aMenosQue($oc->esBorrador(), 422, 'Sólo se autoriza una orden en borrador.');
        // El total > 0 cubre también «no tiene conceptos» (una orden vacía suma
        // cero): un solo guard, sin una comprobación redundante que no se pueda
        // ejercitar por separado.
        AvisoParaElUsuario::si($oc->total() <= 0, 422, 'La orden no tiene nada que autorizar: su total es cero. Agrega conceptos.');

        $oc->update([
            'estado' => OrdenCompra::AUTORIZADA,
            'autorizada_por' => $personaId,
            'autorizada_en' => now(),
        ]);
    }

    /**
     * Recibe (total o parcialmente) una OC autorizada: genera la cuenta por
     * pagar por lo recibido y avanza el estado. Bajo bloqueo de la OC: dos
     * recepciones a la vez no pueden pasar de lo pedido.
     *
     * @param  array{monto: float|string, fecha: string, vencimiento: string, referencia?: ?string}  $datos
     *
     * @throws AvisoParaElUsuario 422 con su razón
     */
    public function recibir(OrdenCompra $oc, array $datos): CuentaPorPagar
    {
        return DB::transaction(function () use ($oc, $datos) {
            $oc = OrdenCompra::query()->lockForUpdate()->findOrFail($oc->id);

            AvisoParaElUsuario::aMenosQue(
                in_array($oc->estado, [OrdenCompra::AUTORIZADA, OrdenCompra::RECIBIDA], true),
                422,
                'Sólo se recibe una orden autorizada.',
            );

            $monto = round((float) $datos['monto'], 2);
            AvisoParaElUsuario::si($monto <= 0, 422, 'Lo recibido tiene que ser mayor que cero.');
            AvisoParaElUsuario::si($monto > $oc->porRecibir() + 0.005, 422, 'Eso pasa de lo que falta por recibir ('.number_format($oc->porRecibir(), 2).').');

            $cxp = CuentaPorPagar::create([
                'proveedor_id' => $oc->proveedor_id,
                'centro_costo_id' => $oc->centro_costo_id,
                'partida_id' => $oc->partida_id,
                'ciclo_id' => $oc->ciclo_id,
                'concepto' => 'Recepción de la orden de compra #'.$oc->id,
                'monto' => $monto,
                'fecha' => $datos['fecha'],
                'vencimiento' => $datos['vencimiento'],
                'referencia' => $datos['referencia'] ?? $oc->referencia,
                'origen' => CuentaPorPagar::ORIGEN_ORDEN_COMPRA,
                'origen_id' => $oc->id,
                'orden_compra_id' => $oc->id,
            ]);

            // Cerrada cuando ya no queda nada por recibir; si no, recibida parcial.
            $oc->update(['estado' => $oc->fresh()->porRecibir() <= 0.005 ? OrdenCompra::CERRADA : OrdenCompra::RECIBIDA]);

            return $cxp;
        });
    }

    /**
     * Recompone el estado de una OC ya autorizada a partir de lo recibido. Se
     * llama cuando una CxP suya se CANCELA (se deshace una recepción): sin esto,
     * la OC quedaría «cerrada» con saldo por recibir y sin poder recibir otra vez.
     * No toca un borrador ni una cancelada.
     */
    public function recomputarEstado(OrdenCompra $oc): void
    {
        if (in_array($oc->estado, [OrdenCompra::BORRADOR, OrdenCompra::CANCELADA], true)) {
            return;
        }

        $recibido = $oc->montoRecibido();

        $oc->update(['estado' => match (true) {
            $recibido <= 0.005 => OrdenCompra::AUTORIZADA,
            $recibido + 0.005 >= $oc->total() => OrdenCompra::CERRADA,
            default => OrdenCompra::RECIBIDA,
        }]);
    }

    /**
     * Cancela una OC ANTES de recibir nada. Si ya generó cuentas por pagar, no se
     * cancela desde aquí: se cancela cada CxP (y su recepción) primero.
     */
    public function cancelar(OrdenCompra $oc): void
    {
        // Sólo borrador o autorizada. Recibir SIEMPRE avanza el estado a
        // recibida/cerrada, así que una autorizada nunca tiene recepciones: el
        // estado es el guard, sin uno redundante sobre `montoRecibido`.
        AvisoParaElUsuario::aMenosQue(
            in_array($oc->estado, [OrdenCompra::BORRADOR, OrdenCompra::AUTORIZADA], true),
            422,
            'Esta orden ya no se puede cancelar. Si tiene recepciones, cancela primero sus cuentas por pagar.',
        );

        $oc->update(['estado' => OrdenCompra::CANCELADA]);
    }
}
