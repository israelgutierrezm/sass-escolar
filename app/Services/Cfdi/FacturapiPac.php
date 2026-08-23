<?php

declare(strict_types=1);

namespace App\Services\Cfdi;

use App\Models\Finanzas\Factura;
use App\Models\Nomina\ReciboNomina;
use App\Services\Facturacion\FacturapiRechazo;
use App\Services\Facturacion\FacturapiService;

/**
 * PAC respaldado por Facturapi.
 *
 * Traduce la `Factura` del sistema al formato de Facturapi, la emite y devuelve
 * el `ResultadoTimbrado` que el resto del flujo ya sabe manejar. El id de la
 * factura EN Facturapi se guarda en `facturas.facturapi_id` para poder
 * cancelarla y descargar su XML/PDF después.
 *
 * Un RECHAZO (RFC inválido, etc.) vuelve como `ResultadoTimbrado::rechazado`, no
 * como excepción: es una respuesta legítima. Las fallas de comunicación sí se
 * dejan propagar para que la cola reintente.
 */
class FacturapiPac implements Pac
{
    // El servicio se construye por factura (con la llave de su emisor), no se
    // inyecta uno global: cada razón social timbra con su propia organización.

    public function nombre(): string
    {
        return 'facturapi';
    }

    /**
     * Nómina: todavía NO.
     *
     * El complemento de nómina 1.2 es otro documento en la API de Facturapi y
     * escribir su traducción sin credenciales con las que probarla produciría
     * código que parece funcionar y que nadie ha visto responder — exactamente
     * la razón por la que este proyecto tardó en tener driver real de facturas.
     *
     * Se rechaza con un mensaje que dice qué hacer, en vez de reventar: quien
     * encienda el timbrado con este driver ve la razón en su pantalla.
     */
    public function timbrarNomina(ReciboNomina $recibo): ResultadoTimbrado
    {
        return ResultadoTimbrado::rechazado(
            'Este PAC todavía no timbra nómina. Mientras tanto, usa el modo de prueba '
            .'(CFDI_PAC=falso) o timbra los recibos por fuera.',
        );
    }

    public function timbrar(Factura $factura): ResultadoTimbrado
    {
        $factura->loadMissing('conceptos', 'emisor');
        $servicio = FacturapiService::paraEmisor($factura->emisor);

        try {
            $respuesta = $servicio->emitirFactura($this->cuerpoDe($factura));
        } catch (FacturapiRechazo $e) {
            return ResultadoTimbrado::rechazado($e->getMessage(), $e->codigo);
        }

        $uuid = $respuesta['uuid'] ?? null;
        $facturapiId = $respuesta['id'] ?? null;

        if ($uuid === null || $facturapiId === null) {
            return ResultadoTimbrado::rechazado('Facturapi no devolvió el folio fiscal del comprobante.');
        }

        // Se guarda el id de Facturapi en la factura: el job persiste el modelo
        // al marcar la factura como timbrada.
        $factura->facturapi_id = (string) $facturapiId;

        // El XML/PDF se bajan aparte. Si la descarga fallara, la factura YA está
        // timbrada: no se pierde, se podrá bajar después con su facturapi_id.
        return ResultadoTimbrado::timbrado(
            (string) $uuid,
            xml: $this->descargarSilencioso($servicio, (string) $facturapiId, 'xml'),
            pdf: $this->descargarSilencioso($servicio, (string) $facturapiId, 'pdf'),
        );
    }

    public function cancelar(Factura $factura, string $motivo, ?string $uuidSustituta = null): ResultadoTimbrado
    {
        if ($factura->facturapi_id === null) {
            return ResultadoTimbrado::rechazado('Esta factura no tiene id de Facturapi: no se puede cancelar allá.');
        }

        $factura->loadMissing('emisor');
        $servicio = FacturapiService::paraEmisor($factura->emisor);

        // El motivo 01 exige la sustituta; Facturapi la identifica por SU id, no
        // por el UUID del SAT, así que se resuelve a partir del folio fiscal.
        $sustitutaId = null;
        if ($uuidSustituta !== null) {
            $sustitutaId = Factura::query()->where('uuid', $uuidSustituta)->value('facturapi_id');
        }

        try {
            $servicio->cancelarFactura($factura->facturapi_id, $motivo, $sustitutaId);
        } catch (FacturapiRechazo $e) {
            return ResultadoTimbrado::rechazado($e->getMessage(), $e->codigo);
        }

        return ResultadoTimbrado::cancelado();
    }

    /**
     * @return array<string, mixed>
     */
    private function cuerpoDe(Factura $factura): array
    {
        $items = $factura->conceptos->map(function ($c) {
            $importe = (float) $c->importe;
            $iva = (float) $c->iva;
            $tasa = $importe > 0 ? round($iva / $importe, 6) : 0.0;

            return [
                'quantity' => (float) $c->cantidad,
                'product' => [
                    'description' => $c->descripcion,
                    'product_key' => $c->clave_sat,
                    'unit_key' => $c->clave_unidad_sat,
                    'price' => (float) $c->valor_unitario,
                    'tax_included' => false,
                    // Sin IVA (exento/no objeto) se manda taxes vacío: si se
                    // omitiera, Facturapi aplicaría 16% por defecto.
                    'taxes' => $tasa > 0 ? [['type' => 'IVA', 'rate' => $tasa]] : [],
                    'tax_object' => $c->objeto_impuesto ?? '02',
                ],
            ];
        })->all();

        return [
            'customer' => [
                'legal_name' => $factura->receptor_razon_social,
                'tax_id' => $factura->receptor_rfc,
                'tax_system' => $factura->receptor_regimen_fiscal,
                'address' => ['zip' => $factura->receptor_cp],
            ],
            'items' => $items,
            'use' => $factura->receptor_uso_cfdi,
            'payment_form' => $factura->forma_pago_sat,
            'payment_method' => $factura->metodo_pago_sat,
            'currency' => 'MXN',
        ];
    }

    private function descargarSilencioso(FacturapiService $servicio, string $facturapiId, string $tipo): ?string
    {
        try {
            return $tipo === 'xml'
                ? $servicio->descargarXml($facturapiId)
                : $servicio->descargarPdf($facturapiId);
        } catch (\Throwable) {
            return null;
        }
    }
}
