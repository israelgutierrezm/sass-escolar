<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\CuentaPorPagar;
use App\Models\Finanzas\Egreso;
use Illuminate\Support\Facades\DB;

/**
 * Paga una cuenta por pagar registrando un EGRESO.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * ── La invariante ──────────────────────────────────────────────────────────
 * El dinero «sale» en UN solo lugar: `egresos`. Pagar una CxP crea un egreso con
 * `origen = 'cxp'`, copiando su centro, partida, proveedor y ciclo, y el estado
 * de la CxP se DERIVA de sus egresos —como el estatus del adeudo en
 * `RegistradorPago`—. Así el ejercido no se cuenta dos veces.
 *
 * ── Concurrencia ───────────────────────────────────────────────────────────
 * Se bloquea la CxP dentro de la transacción y se relee su saldo: dos cajeros
 * pagando la misma cuenta a la vez no pueden pasarse del total. Es el molde del
 * bloqueo de adeudos.
 */
class RegistradorPagoProveedor
{
    /**
     * @param  array{monto: float|string, fecha: string, referencia?: ?string, comprobante_ruta?: ?string, comprobante_nombre?: ?string}  $datos
     *
     * @throws AvisoParaElUsuario 422 con su razón
     */
    public function pagar(CuentaPorPagar $cxp, array $datos): Egreso
    {
        return DB::transaction(function () use ($cxp, $datos) {
            // Relee bajo bloqueo: el saldo se decide con la fila ya bloqueada.
            $cxp = CuentaPorPagar::query()->lockForUpdate()->findOrFail($cxp->id);

            AvisoParaElUsuario::aMenosQue($cxp->estaAbierta(), 422, 'Esa cuenta ya no admite pagos.');

            $monto = round((float) $datos['monto'], 2);
            AvisoParaElUsuario::si($monto <= 0, 422, 'El pago tiene que ser mayor que cero.');
            AvisoParaElUsuario::si($monto > $cxp->saldo() + 0.005, 422, 'El pago no puede pasar del saldo ('.number_format($cxp->saldo(), 2).').');

            $egreso = Egreso::create([
                'fecha' => $datos['fecha'],
                'centro_costo_id' => $cxp->centro_costo_id,
                'proveedor_id' => $cxp->proveedor_id,
                'partida_id' => $cxp->partida_id,
                'ciclo_id' => $cxp->ciclo_id,
                'monto' => $monto,
                'descripcion' => 'Pago a proveedor · '.$cxp->concepto,
                'referencia' => $datos['referencia'] ?? $cxp->referencia,
                'comprobante_ruta' => $datos['comprobante_ruta'] ?? null,
                'comprobante_nombre' => $datos['comprobante_nombre'] ?? null,
                // El enlace va en `cuenta_por_pagar_id`; `origen` marca que es un
                // pago a proveedor (para el guard de la pantalla de egresos), y
                // `origen_id` queda NULL para que el único de egresos deje
                // convivir las parcialidades.
                'origen' => Egreso::ORIGEN_CXP,
                'cuenta_por_pagar_id' => $cxp->id,
            ]);

            $this->recomputarEstado($cxp);

            return $egreso;
        });
    }

    /**
     * Deshace un pago borrando su egreso y recomputando el estado de la CxP.
     * El egreso es la CAPTURA del pago; corregirlo es normal.
     */
    public function revertirPago(Egreso $egreso): void
    {
        AvisoParaElUsuario::aMenosQue($egreso->origen === Egreso::ORIGEN_CXP, 422, 'Ese egreso no es el pago de una cuenta por pagar.');

        DB::transaction(function () use ($egreso) {
            $cxp = CuentaPorPagar::query()->lockForUpdate()->find($egreso->cuenta_por_pagar_id);

            $egreso->delete();

            if ($cxp !== null) {
                $this->recomputarEstado($cxp);
            }
        });
    }

    /** El estado sale de lo pagado; nunca se escribe a mano. */
    public function recomputarEstado(CuentaPorPagar $cxp): void
    {
        $cxp->update(['estado' => $cxp->fresh()->estadoSegunPagos()]);
    }
}
