<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Conekta — órdenes con checkout alojado.
 *
 * Se le crea una ORDEN con su `checkout`, y ella devuelve una liga donde el
 * alumno elige cómo pagar entre lo que la escuela dejó encendido: tarjeta (con
 * meses sin intereses si se configuraron), efectivo en OXXO o transferencia
 * SPEI.
 *
 * ── Los montos van en CENTAVOS ─────────────────────────────────────────────
 * Es el detalle que más caro sale de esta API: mandar 1650 en vez de 165000
 * cobra dieciséis pesos con cincuenta en lugar de mil seiscientos cincuenta, y
 * al revés cobra cien veces de más. No falla, no avisa: cobra otra cosa. Hay una
 * prueba dedicada sólo a esto.
 *
 * ── Cómo se sabe qué pasó ──────────────────────────────────────────────────
 * Igual que con Mercado Pago: el aviso sólo dice qué orden mirar y la verdad
 * sale de consultarla. Aquí importa todavía más, porque Conekta manda avisos de
 * muchos tipos —`order.paid`, `order.pending_payment`, `order.expired`— y el
 * mismo cobro puede generar varios a lo largo de días si se pagó en OXXO.
 */
class PasarelaConekta implements Pasarela
{
    private const API = 'https://api.conekta.io';

    /** Su versión de API viaja en el Accept; sin esto contesta con otro formato. */
    private const VERSION = 'application/vnd.conekta-v2.1.0+json';

    public function __construct(private readonly PasarelaPago $config) {}

    public function iniciar(IntencionCobro $intencion, string $urlRetorno, string $urlAviso): CobroIniciado
    {
        $respuesta = $this->cliente()->post(self::API.'/orders', [
            'currency' => 'MXN',
            'line_items' => [[
                'name' => 'Pago de servicios escolares',
                'unit_price' => $this->aCentavos((float) $intencion->monto),
                'quantity' => 1,
            ]],
            // Nuestro hilo para reconocer su aviso después.
            'metadata' => ['intencion' => (string) $intencion->id],
            'checkout' => array_filter([
                'type' => 'HostedPayment',
                'allowed_payment_methods' => $this->metodosPermitidos(),
                'success_url' => $urlRetorno,
                'failure_url' => $urlRetorno,
                'monthly_installments_enabled' => $this->config->ofreceMsi(),
                'monthly_installments_options' => $this->config->mesesSinIntereses() ?: null,
                /*
                 * El cobro caduca. Sin fecha, una referencia de OXXO queda viva
                 * indefinidamente y alguien puede pagar en marzo una colegiatura
                 * de agosto —con el cargo ya regularizado por otra vía—.
                 */
                'expires_at' => now()->addDays(3)->timestamp,
            ], fn ($v) => $v !== null),
        ]);

        if ($respuesta->failed()) {
            throw new RuntimeException($this->porQueFallo($respuesta->json(), $respuesta->status()));
        }

        $datos = $respuesta->json();
        $url = $datos['checkout']['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Conekta no devolvió a dónde enviar al alumno a pagar.');
        }

        return new CobroIniciado(
            url: $url,
            referenciaExterna: (string) ($datos['id'] ?? ''),
            crudo: $datos,
        );
    }

    public function interpretarAviso(Request $peticion): ?ResultadoCobro
    {
        $tipo = (string) $peticion->input('type');

        // Sólo interesan los avisos de órdenes. A los demás se les contesta que
        // se recibieron y ahí se acaba.
        if (! str_starts_with($tipo, 'order.')) {
            return null;
        }

        $ordenId = $peticion->input('data.object.id');

        if (blank($ordenId)) {
            return null;
        }

        return $this->consultarOrden((string) $ordenId);
    }

    public function consultar(IntencionCobro $intencion): ResultadoCobro
    {
        if (blank($intencion->referencia_externa)) {
            return new ResultadoCobro($intencion->id, EstadoCobro::PENDIENTE);
        }

        return $this->consultarOrden($intencion->referencia_externa);
    }

    public function avisoAutentico(Request $peticion, PasarelaPago $config): bool
    {
        /*
         * Conekta no firma sus webhooks con un secreto compartido: propone
         * validar por IP de origen o por su llave pública. Ninguna de las dos
         * se puede dar por configurada en una escuela cualquiera, así que se
         * acepta el aviso y la verdad sale de consultar la orden CON nuestra
         * llave privada.
         *
         * Un aviso falso consigue que le preguntemos a Conekta por una orden
         * que no existe. Eso no mueve dinero, que es lo que importa.
         */
        return true;
    }

    // ── Interno ────────────────────────────────────────────────────────────

    private function consultarOrden(string $ordenId): ResultadoCobro
    {
        $respuesta = $this->cliente()->get(self::API."/orders/{$ordenId}");

        if ($respuesta->failed()) {
            Log::warning('Conekta no contestó a una consulta de orden.', [
                'orden' => $ordenId,
                'respuesta' => $respuesta->json(),
            ]);

            return new ResultadoCobro(null, EstadoCobro::DESCONOCIDO, crudo: $respuesta->json() ?? []);
        }

        $orden = $respuesta->json();
        $referencia = $orden['metadata']['intencion'] ?? null;

        return new ResultadoCobro(
            intencionId: is_numeric($referencia) ? (int) $referencia : null,
            estado: $this->traducir((string) ($orden['payment_status'] ?? '')),
            // De vuelta a pesos: Conekta habla en centavos.
            monto: isset($orden['amount']) ? ((int) $orden['amount']) / 100 : null,
            transaccionId: isset($orden['id']) ? (string) $orden['id'] : null,
            crudo: $orden,
        );
    }

    private function traducir(string $estado): EstadoCobro
    {
        return match ($estado) {
            'paid' => EstadoCobro::APROBADO,
            /*
             * `pending_payment` es lo normal mientras el alumno no ha ido al
             * OXXO, y puede durar días. `preauthorized` es dinero apartado, no
             * cobrado.
             */
            'pending_payment', 'preauthorized' => EstadoCobro::PENDIENTE,
            'declined' => EstadoCobro::RECHAZADO,
            'refunded', 'partially_refunded', 'voided', 'expired', 'canceled' => EstadoCobro::CANCELADO,
            default => EstadoCobro::DESCONOCIDO,
        };
    }

    /**
     * Qué formas de pago se le ofrecen al alumno, en el vocabulario de Conekta.
     *
     * @return array<int, string>
     */
    private function metodosPermitidos(): array
    {
        $traduccion = ['tarjeta' => 'card', 'oxxo' => 'cash', 'spei' => 'bank_transfer'];

        $permitidos = array_values(array_filter(array_map(
            fn (string $m) => $traduccion[$m] ?? null,
            $this->config->metodosAceptados(),
        )));

        /*
         * Nunca se manda una lista vacía: Conekta la rechaza y el cobro no se
         * abre. La pantalla de configuración ya impide apagarlo todo, pero una
         * pasarela guardada antes de esa regla podría llegar así, y el alumno no
         * tiene por qué pagar el error con una liga rota.
         */
        return $permitidos ?: ['card'];
    }

    /**
     * A centavos, sin que la coma flotante muerda.
     *
     * Truncar en vez de redondear pierde un centavo en los montos que no existen
     * exactos en binario: `(int) (8.29 * 100)` da 828, no 829, porque el
     * producto vale 828.9999… Un centavo por cobro no lo reclama nadie, y por
     * eso mismo puede estar años sin que se note, hasta que hay que cuadrar la
     * caja contra el reporte de la pasarela y no cuadra por ningún lado.
     */
    private function aCentavos(float $pesos): int
    {
        return (int) round($pesos * 100);
    }

    private function cliente()
    {
        $llave = $this->config->credencialesActivas()['private_key'] ?? null;

        if (blank($llave)) {
            throw new RuntimeException('Conekta no tiene configurada su llave privada.');
        }

        return Http::withBasicAuth($llave, '')
            ->withHeaders(['Accept' => self::VERSION])
            ->timeout(20)
            // Se reintenta la consulta, no la creación: repetir una orden deja
            // cobros huérfanos; preguntar dos veces es inofensivo.
            ->retry(2, 300, throw: false);
    }

    /** @param  array<string, mixed>|null  $cuerpo */
    private function porQueFallo(?array $cuerpo, int $estado): string
    {
        $motivo = $cuerpo['details'][0]['message']
            ?? $cuerpo['message']
            ?? null;

        return $motivo
            ? "Conekta rechazó la solicitud de cobro: {$motivo}"
            : "Conekta no pudo iniciar el cobro (HTTP {$estado}).";
    }
}
