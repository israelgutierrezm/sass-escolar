<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use App\Models\Facturacion\FacturacionConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Punto ÚNICO de contacto con Facturapi.
 *
 * Ningún controlador llama a Facturapi directamente: todo pasa por aquí. Así la
 * API key vive en un solo lugar, nunca se registra en logs, y el día que
 * cambie el proveedor se toca una sola clase.
 *
 * El AMBIENTE (pruebas/producción) de la configuración decide qué llave se usa;
 * el endpoint es el mismo —Facturapi separa los mundos por el tipo de llave
 * (`sk_test_` vs `sk_live_`)—.
 *
 * Hoy sólo `probarConexion()` está implementado. El resto de operaciones quedan
 * declaradas y documentadas para construirse después sin reabrir el diseño.
 */
class FacturapiService
{
    private const BASE = 'https://www.facturapi.io/v2';

    public function __construct(private readonly FacturacionConfig $config) {}

    public static function paraLaEscuela(): self
    {
        return new self(FacturacionConfig::actual());
    }

    /**
     * Prueba la conexión con el ambiente activo. NO registra la llave.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function probarConexion(): array
    {
        $key = $this->config->apiKeyActiva();

        if (blank($key)) {
            return ['ok' => false, 'mensaje' => "Falta la API key de {$this->config->ambiente}."];
        }

        try {
            $respuesta = $this->cliente($key)->get(self::BASE.'/customers', ['limit' => 1]);

            if ($respuesta->successful()) {
                return ['ok' => true, 'mensaje' => "Conexión exitosa con Facturapi ({$this->config->ambiente})."];
            }

            if ($respuesta->status() === 401) {
                return ['ok' => false, 'mensaje' => 'La API key no es válida (401 no autorizado).'];
            }

            return ['ok' => false, 'mensaje' => "Facturapi respondió {$respuesta->status()}: ".$this->mensajeDeError($respuesta)];
        } catch (\Throwable $e) {
            // El mensaje de la excepción no trae la llave (va en el header).
            return ['ok' => false, 'mensaje' => 'No se pudo conectar con Facturapi: '.$e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Operaciones preparadas (pendientes de implementar). Firmas y contrato
    // definidos para construirlas después sin tocar el resto del sistema.
    // ---------------------------------------------------------------------

    /**
     * Alta de cliente (normalmente el alumno, pero la factura puede ir a un
     * tercero: por eso el cliente es un dato aparte del alumno).
     *
     * @param  array<string, mixed>  $datos  nombre/razón social, RFC, régimen, CP, correo, uso CFDI…
     */
    public function crearCliente(array $datos): array
    {
        return $this->pendiente('crearCliente');
    }

    /** @param array<string, mixed> $datos */
    public function actualizarCliente(string $clienteId, array $datos): array
    {
        return $this->pendiente('actualizarCliente');
    }

    /** @param array<string, mixed> $datos  clave SAT del producto/servicio, objeto de impuesto, precio… */
    public function crearProducto(array $datos): array
    {
        return $this->pendiente('crearProducto');
    }

    /** @param array<string, mixed> $datos */
    public function emitirFactura(array $datos): array
    {
        return $this->pendiente('emitirFactura');
    }

    /** Factura global (público en general) de un periodo. @param array<string, mixed> $datos */
    public function emitirFacturaGlobal(array $datos): array
    {
        return $this->pendiente('emitirFacturaGlobal');
    }

    public function cancelarFactura(string $facturaId, string $motivo, ?string $facturaSustituta = null): array
    {
        return $this->pendiente('cancelarFactura');
    }

    public function descargarXml(string $facturaId): string
    {
        return $this->pendiente('descargarXml');
    }

    public function descargarPdf(string $facturaId): string
    {
        return $this->pendiente('descargarPdf');
    }

    public function enviarPorCorreo(string $facturaId, ?string $correo = null): array
    {
        return $this->pendiente('enviarPorCorreo');
    }

    public function consultarEstado(string $facturaId): array
    {
        return $this->pendiente('consultarEstado');
    }

    /** Catálogo de motivos de cancelación del SAT. */
    public function motivosCancelacion(): array
    {
        return $this->pendiente('motivosCancelacion');
    }

    /** Complemento de pago (recepción de pagos, CFDI de tipo P). @param array<string, mixed> $datos */
    public function complementoPago(array $datos): array
    {
        return $this->pendiente('complementoPago');
    }

    // ---------------------------------------------------------------------

    /** Cliente HTTP autenticado. La llave va como usuario en Basic Auth. */
    private function cliente(string $key)
    {
        return Http::withBasicAuth($key, '')->acceptJson()->timeout(15);
    }

    private function mensajeDeError(Response $respuesta): string
    {
        return (string) ($respuesta->json('message') ?? $respuesta->json('error') ?? 'error desconocido');
    }

    /**
     * @return never
     */
    private function pendiente(string $operacion): array
    {
        throw new RuntimeException("La operación de facturación «{$operacion}» aún no está implementada.");
    }
}
