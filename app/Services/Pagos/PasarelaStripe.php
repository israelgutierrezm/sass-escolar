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
 * Stripe — Checkout Sessions.
 *
 * Se le pide una SESIÓN de pago y devuelve la liga donde el alumno paga. El
 * objeto que importa después es esa misma sesión: su `payment_status` dice si
 * hubo dinero.
 *
 * ── Habla en formulario, no en JSON ────────────────────────────────────────
 * Es la rareza de esta API frente a las demás: espera
 * `application/x-www-form-urlencoded` con los arreglos en notación de corchetes
 * (`line_items[0][price_data][currency]`). Mandarle JSON no da un error claro,
 * da un «parámetro desconocido» por cada campo.
 *
 * ── Y en centavos ──────────────────────────────────────────────────────────
 * Como Conekta. Ver la nota de `aCentavos`.
 *
 * ── Los meses sin intereses los elige quien paga ───────────────────────────
 * A diferencia de Mercado Pago y Conekta, aquí no se manda la lista de plazos:
 * se enciende `installments` y Stripe ofrece los que el banco emisor de esa
 * tarjeta permita. Por eso la configuración de meses de la escuela sólo decide
 * SI se ofrecen, no cuáles.
 */
class PasarelaStripe implements Pasarela
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private readonly PasarelaPago $config) {}

    public function iniciar(IntencionCobro $intencion, string $urlRetorno, string $urlAviso): CobroIniciado
    {
        $datos = [
            'mode' => 'payment',
            'success_url' => $urlRetorno,
            'cancel_url' => $urlRetorno,
            /*
             * Nuestro identificador viaja con la sesión y vuelve en ella. Es el
             * hilo que permite atribuir el aviso: sin él, el webhook trae una
             * sesión que no se puede ligar a nadie.
             */
            'client_reference_id' => (string) $intencion->id,
            'metadata' => ['intencion' => (string) $intencion->id],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'mxn',
                    'unit_amount' => $this->aCentavos((float) $intencion->monto),
                    'product_data' => ['name' => 'Pago de servicios escolares'],
                ],
            ]],
        ];

        foreach ($this->metodosPermitidos() as $i => $metodo) {
            $datos["payment_method_types"][$i] = $metodo;
        }

        if ($this->config->ofreceMsi()) {
            // Se enciende; los plazos concretos los pone el emisor de la tarjeta.
            $datos['payment_method_options']['card']['installments']['enabled'] = 'true';
        }

        $respuesta = $this->cliente()->asForm()->post(self::API.'/checkout/sessions', $datos);

        if ($respuesta->failed()) {
            throw new RuntimeException($this->porQueFallo($respuesta->json(), $respuesta->status()));
        }

        $sesion = $respuesta->json();
        $url = $sesion['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Stripe no devolvió a dónde enviar al alumno a pagar.');
        }

        return new CobroIniciado(
            url: $url,
            referenciaExterna: (string) ($sesion['id'] ?? ''),
            crudo: $sesion,
        );
    }

    public function interpretarAviso(Request $peticion): ?ResultadoCobro
    {
        $tipo = (string) $peticion->input('type');

        /*
         * Stripe manda avisos de casi todo. Sólo importan los de la sesión de
         * pago; a los demás se les contesta que se recibieron y ahí acaba.
         *
         * `async_payment_succeeded` es el que llega días después cuando se pagó
         * en OXXO: sin él, un pago en efectivo nunca se aplicaría.
         */
        $deSesion = [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
        ];

        if (! in_array($tipo, $deSesion, true)) {
            return null;
        }

        $sesionId = $peticion->input('data.object.id');

        if (blank($sesionId)) {
            return null;
        }

        return $this->consultarSesion((string) $sesionId);
    }

    public function consultar(IntencionCobro $intencion): ResultadoCobro
    {
        if (blank($intencion->referencia_externa)) {
            return new ResultadoCobro($intencion->id, EstadoCobro::PENDIENTE);
        }

        return $this->consultarSesion($intencion->referencia_externa);
    }

    public function avisoAutentico(Request $peticion, PasarelaPago $config): bool
    {
        $secreto = $config->credencialesActivas()['webhook_secret'] ?? null;

        // Sin secreto configurado se sigue: la verdad sale de consultar la
        // sesión con nuestra llave. Ver la nota en `PasarelaMercadoPago`.
        if (blank($secreto)) {
            return true;
        }

        $firma = $peticion->header('Stripe-Signature');

        if (blank($firma)) {
            return false;
        }

        // «t=1704908010,v1=5257a869e7…»
        $partes = collect(explode(',', $firma))
            ->mapWithKeys(function (string $parte) {
                [$clave, $valor] = array_pad(explode('=', trim($parte), 2), 2, null);

                return [trim((string) $clave) => trim((string) $valor)];
            });

        $ts = $partes['t'] ?? null;
        $v1 = $partes['v1'] ?? null;

        if (blank($ts) || blank($v1)) {
            return false;
        }

        /*
         * Se firma sobre el CUERPO CRUDO, no sobre los datos ya interpretados:
         * volver a serializar el JSON cambia el orden y los espacios, y la firma
         * deja de cuadrar aunque el aviso sea legítimo.
         */
        $esperada = hash_hmac('sha256', $ts.'.'.$peticion->getContent(), $secreto);

        return hash_equals($esperada, $v1);
    }

    // ── Interno ────────────────────────────────────────────────────────────

    private function consultarSesion(string $sesionId): ResultadoCobro
    {
        $respuesta = $this->cliente()->get(self::API."/checkout/sessions/{$sesionId}");

        if ($respuesta->failed()) {
            Log::warning('Stripe no contestó a una consulta de sesión.', [
                'sesion' => $sesionId,
                'respuesta' => $respuesta->json(),
            ]);

            return new ResultadoCobro(null, EstadoCobro::DESCONOCIDO, crudo: $respuesta->json() ?? []);
        }

        $sesion = $respuesta->json();
        $referencia = $sesion['client_reference_id'] ?? ($sesion['metadata']['intencion'] ?? null);

        return new ResultadoCobro(
            intencionId: is_numeric($referencia) ? (int) $referencia : null,
            estado: $this->traducir($sesion),
            monto: isset($sesion['amount_total']) ? ((int) $sesion['amount_total']) / 100 : null,
            transaccionId: isset($sesion['payment_intent']) ? (string) $sesion['payment_intent'] : null,
            crudo: $sesion,
        );
    }

    /**
     * El veredicto sale de DOS campos, y confundirlos da por cobrado lo que no
     * lo está: `status` dice en qué punto va la sesión y `payment_status` si
     * hubo dinero. Una sesión `complete` con `payment_status = unpaid` es un
     * pago en OXXO todavía sin pagar.
     *
     * @param  array<string, mixed>  $sesion
     */
    private function traducir(array $sesion): EstadoCobro
    {
        $pago = (string) ($sesion['payment_status'] ?? '');
        $sesionEstado = (string) ($sesion['status'] ?? '');

        if ($pago === 'paid' || $pago === 'no_payment_required') {
            return EstadoCobro::APROBADO;
        }

        return match ($sesionEstado) {
            'open' => EstadoCobro::PENDIENTE,
            // Caducó sin pagarse: la referencia de OXXO ya no sirve.
            'expired' => EstadoCobro::CANCELADO,
            // Terminó la sesión pero el dinero no ha entrado: sigue en proceso.
            'complete' => EstadoCobro::PENDIENTE,
            default => EstadoCobro::DESCONOCIDO,
        };
    }

    /**
     * Qué formas de pago se le ofrecen, en el vocabulario de Stripe.
     *
     * @return array<int, string>
     */
    private function metodosPermitidos(): array
    {
        $traduccion = ['tarjeta' => 'card', 'oxxo' => 'oxxo'];

        $permitidos = array_values(array_filter(array_map(
            fn (string $m) => $traduccion[$m] ?? null,
            $this->config->metodosAceptados(),
        )));

        // Nunca vacío: sin métodos, Stripe rechaza la sesión.
        return $permitidos ?: ['card'];
    }

    /** A centavos, redondeando. Ver la nota en `PasarelaConekta`. */
    private function aCentavos(float $pesos): int
    {
        return (int) round($pesos * 100);
    }

    private function cliente()
    {
        $llave = $this->config->credencialesActivas()['secret_key'] ?? null;

        if (blank($llave)) {
            throw new RuntimeException('Stripe no tiene configurada su clave secreta.');
        }

        return Http::withToken($llave)
            ->timeout(20)
            ->retry(2, 300, throw: false);
    }

    /** @param  array<string, mixed>|null  $cuerpo */
    private function porQueFallo(?array $cuerpo, int $estado): string
    {
        $motivo = $cuerpo['error']['message'] ?? null;

        return $motivo
            ? "Stripe rechazó la solicitud de cobro: {$motivo}"
            : "Stripe no pudo iniciar el cobro (HTTP {$estado}).";
    }
}
