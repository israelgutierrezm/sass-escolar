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

    public function puedeConciliar(): bool
    {
        return true;
    }

    /**
     * Le pregunta a Facturapi por el comprobante.
     *
     * Se pregunta por el id de Facturapi y no por el UUID: es el identificador
     * con el que ese proveedor conoce el documento. Una factura timbrada por
     * otro PAC —o una vieja a la que nunca se le guardó el id— no se puede
     * consultar aquí, y eso se dice en vez de darla por vigente.
     *
     * La traducción de los estados está escrita contra la forma DOCUMENTADA de
     * la API y no se ha visto responder a un Facturapi real. Un estado que no
     * se reconozca se devuelve como desconocido con su texto crudo: suponer
     * «vigente» ante una palabra nueva es como se llega a dar por buena una
     * factura cancelada.
     */
    public function consultarEstado(Factura $factura): EstadoEnElPac
    {
        if ($factura->facturapi_id === null) {
            return EstadoEnElPac::desconocido(
                'Esta factura no tiene id de Facturapi: no se puede consultar allá.',
            );
        }

        $factura->loadMissing('emisor');

        try {
            $respuesta = FacturapiService::paraEmisor($factura->emisor)
                ->consultarEstado($factura->facturapi_id);
        } catch (FacturapiRechazo $e) {
            return EstadoEnElPac::desconocido($e->getMessage());
        }

        $cancelacion = match ($respuesta['cancellation_status'] ?? null) {
            'pending' => EstadoEnElPac::CANCELACION_PENDIENTE,
            'accepted' => EstadoEnElPac::CANCELACION_ACEPTADA,
            'rejected' => EstadoEnElPac::CANCELACION_RECHAZADA,
            'expired' => EstadoEnElPac::CANCELACION_VENCIDA,
            default => null,
        };

        return match ($respuesta['status'] ?? null) {
            'valid' => EstadoEnElPac::vigente($cancelacion),
            'canceled', 'cancelled' => EstadoEnElPac::cancelada($cancelacion),
            default => EstadoEnElPac::desconocido(
                'Facturapi devolvió un estado que no se reconoce: '
                .json_encode($respuesta['status'] ?? null),
            ),
        };
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
     * Traduce la factura al cuerpo que espera Facturapi.
     *
     * Público a propósito, y no por comodidad: es una traducción pura, sin red
     * ni efectos, y es lo único de este driver que se puede comprobar sin
     * credenciales. Dejarla privada obligaría a probar el complemento educativo
     * contra el PAC real, o sea a no probarlo.
     *
     * @return array<string, mixed>
     */
    public function cuerpoDe(Factura $factura): array
    {
        $factura->loadMissing('conceptos', 'iedu', 'origen');

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
        ] + $this->complementos($factura) + $this->egreso($factura);
    }

    /**
     * Los complementos del comprobante. Hoy sólo el educativo (IEDU).
     *
     * Va como arreglo aparte y se SUMA al cuerpo en vez de escribir siempre la
     * clave: mandar `complements: []` en una factura que no lo lleva es
     * distinto de no mandarla, y no hay motivo para averiguar cómo lo
     * interpreta el PAC.
     *
     * @return array<string, mixed>
     */
    private function complementos(Factura $factura): array
    {
        $iedu = $factura->iedu;

        if ($iedu === null) {
            return [];
        }

        return ['complements' => [[
            'type' => 'iedu',
            'data' => [
                'student_name' => $iedu->nombre_alumno,
                'student_curp' => $iedu->curp,
                'school_level' => $iedu->nivel_educativo,
                'school_code' => $iedu->aut_rvoe,
            ],
        ]]];
    }

    /**
     * Lo que convierte el comprobante en una NOTA DE CRÉDITO.
     *
     * Son dos cosas y las dos hacen falta: el tipo de comprobante ('E' de
     * egreso) y la relación con el CFDI que reduce. Sin la relación, el SAT
     * recibe un egreso suelto que no rebaja nada — un documento válido que no
     * corrige la factura que se quería corregir.
     *
     * Una factura de ingreso no manda ninguna de las dos claves: el tipo por
     * omisión de Facturapi ya es 'I', y escribirlo daría lo mismo con más
     * ruido.
     *
     * OJO: esta traducción está escrita contra la forma DOCUMENTADA de la API y
     * no se ha visto responder a un Facturapi real —no hay credenciales—. Misma
     * advertencia que la consulta de grabaciones de Meet.
     *
     * @return array<string, mixed>
     */
    private function egreso(Factura $factura): array
    {
        if (! $factura->esNotaCredito()) {
            return [];
        }

        $uuid = $factura->origen?->uuid;

        return array_filter([
            'type' => 'E',
            'related_documents' => $uuid === null ? null : [[
                'relationship' => Factura::RELACION_NOTA_CREDITO,
                'documents' => [$uuid],
            ]],
        ], fn ($v) => $v !== null);
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
