<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayPal — órdenes v2.
 *
 * ── Lo que la hace distinta de TODAS las demás ─────────────────────────────
 * Aprobar no es cobrar. Cuando quien paga termina en PayPal, la orden queda
 * `APPROVED`: hay permiso para cobrar, pero el dinero **no se ha movido**. Falta
 * un paso nuestro —capturarla— y sólo entonces existe el pago.
 *
 * Es la trampa de esta API: tomar `APPROVED` por bueno liquida el cargo con un
 * dinero que nadie ha transferido, y la autorización caduca a los tres días. El
 * alumno vería su cuenta al corriente y la escuela no habría cobrado nunca.
 *
 * Por eso `consultar` no se limita a mirar: si la orden está aprobada, la
 * CAPTURA. Es el único sitio del sistema donde preguntar tiene un efecto, y lo
 * tiene porque preguntar sin cobrar dejaría el dinero en el aire.
 *
 * ── No ofrece efectivo ni meses sin intereses ──────────────────────────────
 * En México los meses los pone el banco emisor de la tarjeta, no PayPal, y no
 * cobra en tiendas. Por eso su catálogo no tiene opciones que encender: sirve
 * para tarjeta y saldo de PayPal, que es sobre todo lo que hace falta para
 * cobrarle a alguien de fuera del país.
 *
 * ── Habla en cadenas ───────────────────────────────────────────────────────
 * El monto va como texto con dos decimales («1650.00»), no como número. Mandar
 * un float deja que la coma flotante decida los centavos.
 */
class PasarelaPayPal implements Pasarela
{
    public function __construct(private readonly PasarelaPago $config) {}

    public function metodosAElegir(): array
    {
        // Su propia pantalla presenta lo que haya disponible.
        return [];
    }

    public function iniciar(IntencionCobro $intencion, string $urlRetorno, string $urlAviso, ?string $metodo = null): CobroIniciado
    {
        $respuesta = $this->cliente()->post($this->url('/v2/checkout/orders'), [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                // Nuestro hilo: vuelve en la orden y en el aviso.
                'custom_id' => (string) $intencion->id,
                'description' => 'Pago de servicios escolares',
                'amount' => [
                    'currency_code' => 'MXN',
                    'value' => $this->comoTexto((float) $intencion->monto),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => $urlRetorno,
                        'cancel_url' => $urlRetorno,
                        // «Pagar ahora» en vez de «Continuar»: quien llega
                        // sabe que ése es el último paso.
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ]);

        if ($respuesta->failed()) {
            throw new RuntimeException($this->porQueFallo($respuesta->json(), $respuesta->status()));
        }

        $orden = $respuesta->json() ?? [];
        $url = $this->ligaDeAprobacion($orden);

        if ($url === null) {
            throw new RuntimeException('PayPal no devolvió a dónde enviar al alumno a pagar.');
        }

        return new CobroIniciado(
            url: $url,
            referenciaExterna: (string) ($orden['id'] ?? ''),
            crudo: $orden,
        );
    }

    public function interpretarAviso(Request $peticion): ?ResultadoCobro
    {
        $evento = (string) $peticion->input('event_type');

        /*
         * Sólo interesan los de la orden y su cobro. `CHECKOUT.ORDER.APPROVED`
         * es el aviso de que hay permiso —todavía no dinero—, y es justamente
         * el que dispara la captura.
         */
        $deCobro = [
            'CHECKOUT.ORDER.APPROVED',
            'CHECKOUT.ORDER.COMPLETED',
            'PAYMENT.CAPTURE.COMPLETED',
            'PAYMENT.CAPTURE.DENIED',
        ];

        if (! in_array($evento, $deCobro, true)) {
            return null;
        }

        /*
         * Según el evento, el recurso es la ORDEN o la CAPTURA. En el segundo
         * caso la orden se alcanza por el enlace `up`, que es como PayPal
         * apunta de una captura a su orden.
         */
        $ordenId = $peticion->input('resource.id');

        if (str_starts_with($evento, 'PAYMENT.CAPTURE.')) {
            $ordenId = $this->ordenDeLaCaptura($peticion->input('resource', [])) ?? $ordenId;
        }

        if (blank($ordenId)) {
            return null;
        }

        return $this->resolverOrden((string) $ordenId);
    }

    public function consultar(IntencionCobro $intencion): ResultadoCobro
    {
        if (blank($intencion->referencia_externa)) {
            return new ResultadoCobro($intencion->id, EstadoCobro::PENDIENTE);
        }

        return $this->resolverOrden($intencion->referencia_externa);
    }

    public function avisoAutentico(Request $peticion, PasarelaPago $config): bool
    {
        $webhookId = $config->credencialesActivas()['webhook_id'] ?? null;

        /*
         * Sin `webhook_id` configurado no se puede verificar, y se sigue: el
         * aviso sólo dice QUÉ orden mirar y la verdad sale de consultarla —y
         * capturarla— con nuestras credenciales.
         */
        if (blank($webhookId)) {
            return true;
        }

        /*
         * PayPal no firma con un secreto compartido: hay que preguntarle a ELLA
         * si la firma es suya, mandándole las cabeceras del aviso. Es una
         * llamada de red más, pero es la única forma que ofrece.
         */
        $respuesta = $this->cliente()->post($this->url('/v1/notifications/verify-webhook-signature'), [
            'auth_algo' => $peticion->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $peticion->header('PAYPAL-CERT-URL'),
            'transmission_id' => $peticion->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $peticion->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $peticion->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => $peticion->all(),
        ]);

        if ($respuesta->failed()) {
            // Si no se pudo comprobar, se deja pasar: rechazarlo perdería avisos
            // buenos cuando PayPal tenga un mal rato, y la verdad no depende de
            // esto.
            Log::warning('No se pudo verificar la firma de un aviso de PayPal.');

            return true;
        }

        return ($respuesta->json('verification_status') ?? '') === 'SUCCESS';
    }

    // ── Interno ────────────────────────────────────────────────────────────

    /**
     * Mira la orden y, si está aprobada, la COBRA.
     *
     * Ver la nota de la clase: `APPROVED` es permiso, no dinero. Dejarlo así
     * sería quedarse con una autorización que caduca a los tres días.
     */
    private function resolverOrden(string $ordenId): ResultadoCobro
    {
        $respuesta = $this->cliente()->get($this->url("/v2/checkout/orders/{$ordenId}"));

        if ($respuesta->failed()) {
            Log::warning('PayPal no contestó a una consulta de orden.', [
                'orden' => $ordenId,
                'respuesta' => $respuesta->json(),
            ]);

            return new ResultadoCobro(null, EstadoCobro::DESCONOCIDO, crudo: $respuesta->json() ?? []);
        }

        $orden = $respuesta->json() ?? [];

        if (($orden['status'] ?? '') === 'APPROVED') {
            return $this->capturar($ordenId, $orden);
        }

        return $this->deLaOrden($orden);
    }

    /** @param  array<string, mixed>  $orden */
    private function capturar(string $ordenId, array $orden): ResultadoCobro
    {
        $respuesta = $this->cliente()->post($this->url("/v2/checkout/orders/{$ordenId}/capture"));

        if ($respuesta->successful()) {
            return $this->deLaOrden($respuesta->json() ?? []);
        }

        /*
         * Que ya estuviera capturada NO es un error: pasa cuando el aviso y el
         * retorno llegan casi a la vez, que es lo normal. Se vuelve a mirar la
         * orden y se devuelve lo que diga.
         */
        if ($this->yaEstabaCapturada($respuesta->json())) {
            /*
             * `array_merge` y no `+`: el operador de unión CONSERVA la clave
             * que ya existía, así que la orden se quedaba en «APPROVED» —o sea,
             * sin cobrar— justo en el caso en que sí se había cobrado. Lo cazó
             * la prueba de los dos avisos simultáneos.
             */
            return $this->deLaOrden(array_merge($orden, ['status' => 'COMPLETED']));
        }

        Log::error('PayPal no pudo capturar una orden aprobada.', [
            'orden' => $ordenId,
            'respuesta' => $respuesta->json(),
        ]);

        // Aprobada pero sin capturar: NO es dinero, así que queda pendiente y
        // se reintentará al siguiente aviso o consulta.
        return new ResultadoCobro(
            intencionId: $this->referenciaDe($orden),
            estado: EstadoCobro::PENDIENTE,
            crudo: $respuesta->json() ?? [],
        );
    }

    /** @param  array<string, mixed>|null  $error */
    private function yaEstabaCapturada(?array $error): bool
    {
        foreach ($error['details'] ?? [] as $detalle) {
            if (($detalle['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED') {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $orden */
    private function deLaOrden(array $orden): ResultadoCobro
    {
        return new ResultadoCobro(
            intencionId: $this->referenciaDe($orden),
            estado: $this->traducir((string) ($orden['status'] ?? '')),
            monto: $this->montoDe($orden),
            transaccionId: isset($orden['id']) ? (string) $orden['id'] : null,
            crudo: $orden,
        );
    }

    private function traducir(string $estado): EstadoCobro
    {
        return match ($estado) {
            'COMPLETED' => EstadoCobro::APROBADO,
            /*
             * `APPROVED` aparece aquí por si llegara sin haberse podido
             * capturar: es permiso, no dinero, y no debe liquidar nada.
             */
            'CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED' => EstadoCobro::PENDIENTE,
            'VOIDED' => EstadoCobro::CANCELADO,
            default => EstadoCobro::DESCONOCIDO,
        };
    }

    /** @param  array<string, mixed>  $orden */
    private function referenciaDe(array $orden): ?int
    {
        $referencia = $orden['purchase_units'][0]['custom_id'] ?? null;

        return is_numeric($referencia) ? (int) $referencia : null;
    }

    /**
     * Lo que de verdad se cobró.
     *
     * Tras capturar, el importe bueno es el de la CAPTURA, no el de la orden:
     * son iguales casi siempre, pero cuando no lo son manda el dinero que entró.
     *
     * @param  array<string, mixed>  $orden
     */
    private function montoDe(array $orden): ?float
    {
        $unidad = $orden['purchase_units'][0] ?? [];

        $valor = $unidad['payments']['captures'][0]['amount']['value']
            ?? $unidad['amount']['value']
            ?? null;

        return is_numeric($valor) ? (float) $valor : null;
    }

    /** @param  array<string, mixed>  $orden */
    private function ligaDeAprobacion(array $orden): ?string
    {
        foreach ($orden['links'] ?? [] as $enlace) {
            // `payer-action` es el nombre nuevo; `approve` el de siempre.
            if (in_array($enlace['rel'] ?? '', ['payer-action', 'approve'], true)) {
                return (string) $enlace['href'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $captura */
    private function ordenDeLaCaptura(array $captura): ?string
    {
        foreach ($captura['links'] ?? [] as $enlace) {
            if (($enlace['rel'] ?? '') === 'up') {
                return basename(parse_url((string) $enlace['href'], PHP_URL_PATH) ?: '');
            }
        }

        return null;
    }

    /** Dos decimales, como texto. Ver la nota de la clase. */
    private function comoTexto(float $pesos): string
    {
        return number_format($pesos, 2, '.', '');
    }

    private function url(string $ruta): string
    {
        $dominio = $this->config->esProduccion()
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        return $dominio.$ruta;
    }

    private function cliente()
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 300, throw: false);
    }

    /**
     * El permiso para hablar con PayPal, que hay que pedir aparte.
     *
     * Es la única de las cinco que no acepta la llave directamente: se cambia
     * por un token que dura horas. Se guarda en caché porque pedirlo en cada
     * llamada duplicaría las peticiones —y el tiempo de espera— de cada cobro.
     */
    private function token(): string
    {
        $credenciales = $this->config->credencialesActivas();
        $id = $credenciales['client_id'] ?? null;
        $secreto = $credenciales['client_secret'] ?? null;

        if (blank($id) || blank($secreto)) {
            throw new RuntimeException('PayPal no tiene configuradas sus credenciales.');
        }

        // La clave incluye el ambiente y una huella de las credenciales: al
        // cambiarlas, el token viejo deja de usarse solo.
        $clave = 'paypal-token-'.$this->config->ambiente.'-'.substr(sha1((string) $id), 0, 12);

        return Cache::remember($clave, now()->addMinutes(300), function () use ($id, $secreto) {
            $respuesta = Http::withBasicAuth($id, $secreto)
                ->asForm()
                ->timeout(20)
                ->post($this->url('/v1/oauth2/token'), ['grant_type' => 'client_credentials']);

            if ($respuesta->failed()) {
                throw new RuntimeException('PayPal no aceptó las credenciales de la escuela.');
            }

            return (string) $respuesta->json('access_token');
        });
    }

    /** @param  array<string, mixed>|null  $cuerpo */
    private function porQueFallo(?array $cuerpo, int $estado): string
    {
        $motivo = $cuerpo['details'][0]['description']
            ?? $cuerpo['message']
            ?? null;

        return $motivo
            ? "PayPal rechazó la solicitud de cobro: {$motivo}"
            : "PayPal no pudo iniciar el cobro (HTTP {$estado}).";
    }
}
