<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Finanzas\DatosFacturacion;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\Pago;
use App\Services\EmisorFactura;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La política de facturación automática al confirmar un pago.
 *
 * ── Qué decide, y qué NO ───────────────────────────────────────────────────
 * Con el automático encendido, un pago recién confirmado se factura NOMINATIVO
 * si el alumno tiene sus datos fiscales y pidió factura; si no, no se emite nada
 * y el pago queda para la factura GLOBAL del periodo —ausencia de datos no es
 * invalidez (R06.08)—. Un pago con datos incompletos no va a la global (lo
 * excluye `EmisorFactura::globalizables`): queda pendiente para que se corrijan.
 *
 * ── Nunca tumba el cobro ───────────────────────────────────────────────────
 * El pago YA está confirmado cuando esto corre. Una falla al facturar —el PAC
 * caído, un dato malo— no puede borrar un cobro válido (R06.09): se registra el
 * motivo, el pago queda pendiente de facturar (la tarjeta del panel lo muestra)
 * y aquí NO se propaga la excepción.
 */
class FacturacionAutomatica
{
    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly EmisorFactura $emisor,
    ) {}

    public function alConfirmar(Pago $pago): ?Factura
    {
        if (! $this->ajustes->bool(CatalogoAjustes::FACTURA_AUTOMATICA)) {
            return null;
        }

        // Sólo pagos de MATRÍCULA y cobrados: el de un aspirante lo lleva
        // admisiones a mano, y un pago sin confirmar no es dinero.
        if ($pago->matricula_oferta_id === null || ! $pago->estaCobrado()) {
            return null;
        }

        $perfil = DatosFacturacion::query()
            ->where('persona_id', $pago->matriculaOferta?->persona_id)
            ->where('quiere_factura', true)
            ->whereNotNull('rfc')
            ->whereNotNull('razon_social')
            ->whereNotNull('regimen_fiscal')
            ->whereNotNull('cp')
            ->first();

        // Sin perfil nominativo COMPLETO no se emite ahora: o es público general
        // (entra a la global) o le faltan datos (queda pendiente). En ninguno de
        // los dos casos es un error, y no se convierte a global en silencio.
        if ($perfil === null) {
            return null;
        }

        try {
            return $this->emisor->emitir($pago->matricula_oferta_id, [$pago->id], [
                'rfc' => $perfil->rfc,
                'razon_social' => $perfil->razon_social,
                'uso_cfdi' => $perfil->uso_cfdi ?: config('cfdi.uso_cfdi_default'),
                'regimen_fiscal' => $perfil->regimen_fiscal,
                'cp' => $perfil->cp,
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo facturar automáticamente un pago confirmado.', [
                'pago' => $pago->id,
                'motivo' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
