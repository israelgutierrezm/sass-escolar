<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use App\Models\Facturacion\FacturacionConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Punto ÚNICO de contacto con Facturapi.
 *
 * Ningún controlador ni el PAC hablan con Facturapi por su cuenta: todo pasa por
 * aquí, así la API key vive en un solo lugar y nunca se registra en logs.
 *
 * El AMBIENTE (pruebas/producción) de la configuración decide qué llave usar; el
 * endpoint es el mismo —Facturapi separa los mundos por el tipo de llave
 * (`sk_test_` vs `sk_live_`)—.
 *
 * Distinción clave de errores:
 *  - RECHAZO (4xx: RFC inválido, régimen, etc.) → `FacturapiRechazo`: no se
 *    reintenta, se le muestra al usuario.
 *  - FALLA de comunicación (5xx / sin respuesta) → excepción de transporte, que
 *    la cola sí reintenta.
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
     * @return array{ok: bool, mensaje: string}
     */
    public function probarConexion(): array
    {
        try {
            $this->obtener('/customers', ['limit' => 1]);

            return ['ok' => true, 'mensaje' => "Conexión exitosa con Facturapi ({$this->config->ambiente})."];
        } catch (FacturapiRechazo $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo conectar con Facturapi: '.$e->getMessage()];
        }
    }

    // --------------------------------------------------------------- Clientes

    /** @param array<string, mixed> $datos */
    public function crearCliente(array $datos): array
    {
        return $this->enviar('post', '/customers', $datos);
    }

    /** @param array<string, mixed> $datos */
    public function actualizarCliente(string $clienteId, array $datos): array
    {
        return $this->enviar('put', "/customers/{$clienteId}", $datos);
    }

    // -------------------------------------------------------------- Productos

    /** @param array<string, mixed> $datos */
    public function crearProducto(array $datos): array
    {
        return $this->enviar('post', '/products', $datos);
    }

    // --------------------------------------------------------------- Facturas

    /** @param array<string, mixed> $datos */
    public function emitirFactura(array $datos): array
    {
        return $this->enviar('post', '/invoices', $datos);
    }

    /**
     * Factura global (público en general) de un periodo: es una factura normal
     * con el objeto `global` y el cliente genérico. @param array<string, mixed> $datos
     */
    public function emitirFacturaGlobal(array $datos): array
    {
        return $this->enviar('post', '/invoices', $datos);
    }

    /** Complemento de pago (CFDI tipo P). @param array<string, mixed> $datos */
    public function complementoPago(array $datos): array
    {
        return $this->enviar('post', '/invoices', $datos + ['type' => 'payment']);
    }

    public function cancelarFactura(string $facturaId, string $motivo, ?string $sustitutaFacturapiId = null): array
    {
        $params = ['motive' => $motivo];

        if ($sustitutaFacturapiId !== null) {
            $params['substitution'] = $sustitutaFacturapiId;
        }

        return $this->pedir('delete', "/invoices/{$facturaId}", $params);
    }

    public function consultarEstado(string $facturaId): array
    {
        return $this->obtener("/invoices/{$facturaId}");
    }

    public function descargarXml(string $facturaId): string
    {
        return $this->crudo("/invoices/{$facturaId}/xml");
    }

    public function descargarPdf(string $facturaId): string
    {
        return $this->crudo("/invoices/{$facturaId}/pdf");
    }

    public function enviarPorCorreo(string $facturaId, ?string $correo = null): array
    {
        return $this->enviar('post', "/invoices/{$facturaId}/email", $correo !== null ? ['email' => $correo] : []);
    }

    /**
     * Catálogo de motivos de cancelación del SAT (no requiere red).
     *
     * @return array<int, array{clave: string, texto: string}>
     */
    public function motivosCancelacion(): array
    {
        return [
            ['clave' => '01', 'texto' => '01 · Comprobante emitido con errores con relación (hay sustituta)'],
            ['clave' => '02', 'texto' => '02 · Comprobante emitido con errores sin relación'],
            ['clave' => '03', 'texto' => '03 · No se llevó a cabo la operación'],
            ['clave' => '04', 'texto' => '04 · Operación nominativa relacionada en una factura global'],
        ];
    }

    // ---------------------------------------------------------------- Interno

    // ---------------------------------------------- Organizaciones y CSD (Admin)
    //
    // Estos endpoints NO usan la llave de facturación por organización, sino la
    // SECRET ADMIN KEY de la cuenta (`sk_user_...`). Con ella se crea la
    // organización, se le sube el CSD y se pide su llave de pruebas — todo lo
    // que hace falta para que dar de alta una razón social deje el
    // `organization_id` correcto sin teclearlo.

    /**
     * Crea una organización (una razón social/emisor en Facturapi) a partir de
     * sus datos legales. Devuelve el objeto de la organización, incluido su `id`.
     *
     * @param  array<string, mixed>  $datos  al menos: name (razón social)
     * @return array<string, mixed>
     */
    public function crearOrganizacion(array $datos): array
    {
        return $this->manejar($this->clienteAdmin()->post(self::BASE.'/organizations', $datos));
    }

    /** @return array<string, mixed> */
    public function obtenerOrganizacion(string $organizacionId): array
    {
        return $this->manejar($this->clienteAdmin()->get(self::BASE."/organizations/{$organizacionId}"));
    }

    /**
     * Sube el CSD (.cer + .key + contraseña) a una organización. Los archivos
     * viajan como multipart; nunca se registran sus contenidos.
     *
     * @return array<string, mixed>
     */
    public function subirCertificado(string $organizacionId, string $cerContenido, string $keyContenido, string $password): array
    {
        $respuesta = $this->clienteAdmin()
            ->attach('cer', $cerContenido, 'csd.cer')
            ->attach('key', $keyContenido, 'csd.key')
            ->put(self::BASE."/organizations/{$organizacionId}/certificates", ['password' => $password]);

        return $this->manejar($respuesta);
    }

    /**
     * La llave de PRUEBAS de una organización (`sk_test_...`), con la que ya se
     * puede timbrar en el ambiente de pruebas. Facturapi la devuelve como una
     * cadena JSON.
     */
    public function obtenerLlavePruebas(string $organizacionId): string
    {
        $respuesta = $this->clienteAdmin()->get(self::BASE."/organizations/{$organizacionId}/test-api-key");

        if ($respuesta->failed()) {
            $this->reventar($respuesta);
        }

        // Puede venir como cadena JSON ("sk_test_...") o como texto crudo.
        $valor = $respuesta->json();

        return is_string($valor) ? $valor : trim($respuesta->body(), '"');
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function obtener(string $ruta, array $query = []): array
    {
        return $this->pedir('get', $ruta, $query);
    }

    /** @param array<string, mixed> $cuerpo @return array<string, mixed> */
    private function enviar(string $metodo, string $ruta, array $cuerpo): array
    {
        return $this->manejar($this->cliente()->{$metodo}(self::BASE.$ruta, $cuerpo));
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function pedir(string $metodo, string $ruta, array $query = []): array
    {
        return $this->manejar($this->cliente()->{$metodo}(self::BASE.$ruta.($query ? '?'.http_build_query($query) : '')));
    }

    /** Descarga cruda (XML/PDF). */
    private function crudo(string $ruta): string
    {
        $respuesta = $this->cliente()->get(self::BASE.$ruta);

        if ($respuesta->failed()) {
            $this->reventar($respuesta);
        }

        return $respuesta->body();
    }

    /** @return array<string, mixed> */
    private function manejar(Response $respuesta): array
    {
        if ($respuesta->failed()) {
            $this->reventar($respuesta);
        }

        return (array) $respuesta->json();
    }

    /**
     * Convierte una respuesta con error en la excepción correcta: rechazo (4xx)
     * o falla de comunicación (5xx / conexión).
     */
    private function reventar(Response $respuesta): never
    {
        $mensaje = (string) ($respuesta->json('message') ?? $respuesta->json('error') ?? 'error desconocido');
        $codigo = $respuesta->json('code');

        if ($respuesta->clientError()) {
            throw new FacturapiRechazo($mensaje, $codigo !== null ? (string) $codigo : null);
        }

        // 5xx: es cosa de Facturapi/red; conviene reintentar.
        throw new RuntimeException("Facturapi respondió {$respuesta->status()}: {$mensaje}");
    }

    /** Cliente HTTP autenticado. La llave va como usuario en Basic Auth. */
    private function cliente(): PendingRequest
    {
        $key = $this->config->apiKeyActiva();

        if (blank($key)) {
            throw new FacturapiRechazo("Falta la API key de {$this->config->ambiente}.");
        }

        return Http::withBasicAuth($key, '')->acceptJson()->timeout(30);
    }

    /**
     * Cliente para la API de administración (organizaciones y CSD), autenticado
     * con la Secret Admin Key. Timeout más largo: subir un CSD tarda más que un
     * request normal.
     */
    private function clienteAdmin(): PendingRequest
    {
        $key = $this->config->apiKeyUsuario();

        if (blank($key)) {
            throw new FacturapiRechazo('Falta la Secret Admin Key de Facturapi (necesaria para crear organizaciones y subir el CSD).');
        }

        return Http::withBasicAuth($key, '')->acceptJson()->timeout(60);
    }
}
