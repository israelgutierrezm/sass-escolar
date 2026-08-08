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
 * Mercado Pago — Checkout Pro.
 *
 * El flujo son dos objetos suyos y conviene no confundirlos:
 *
 * - La **preferencia** es la orden de cobro que creamos nosotros. Su `id` es lo
 *   que guardamos como referencia externa, y su `init_point` es la URL a la que
 *   se manda a quien paga.
 * - El **pago** es lo que existe después, si alguien efectivamente pagó. Es lo
 *   que trae el aviso, y es donde vive el estado (`approved`, `rejected`…).
 *
 * Por eso el aviso NO se puede resolver leyéndolo: trae el id de un pago que
 * todavía no conocemos. Hay que ir a `/v1/payments/{id}` y de ahí sacar el
 * `external_reference` —que es nuestro id de intención— y el estado real.
 *
 * ── Sandbox y producción son el mismo endpoint ─────────────────────────────
 * A diferencia de otras pasarelas, aquí no cambia la URL: cambia el token. Un
 * token de pruebas contra la misma API devuelve el mundo de pruebas. Por eso el
 * ambiente se sella en la intención: consultarle a un token de producción por un
 * cobro de pruebas devuelve «no existe», no un error claro.
 */
class PasarelaMercadoPago implements Pasarela
{
    private const API = 'https://api.mercadopago.com';

    public function __construct(private readonly PasarelaPago $config) {}

    public function iniciar(IntencionCobro $intencion, string $urlRetorno, string $urlAviso, ?string $metodo = null): CobroIniciado
    {
        $respuesta = $this->cliente()->post(self::API.'/checkout/preferences', [
            'items' => [[
                'title' => 'Pago de servicios escolares',
                'quantity' => 1,
                'currency_id' => 'MXN',
                'unit_price' => (float) $intencion->monto,
            ]],
            /*
             * Nuestro identificador viaja con el cobro y vuelve en el pago. Es
             * el hilo que permite reconocer un aviso: sin él, el webhook trae un
             * pago que no se puede atribuir a nadie.
             */
            'external_reference' => (string) $intencion->id,
            'back_urls' => [
                'success' => $urlRetorno,
                'pending' => $urlRetorno,
                'failure' => $urlRetorno,
            ],
            /*
             * Que el navegador vuelva solo. Es comodidad, no confirmación: el
             * retorno no decide nada, sólo enseña una pantalla.
             */
            'auto_return' => 'approved',
            'notification_url' => $urlAviso,
            'payment_methods' => $this->formasDePago(),
        ]);

        if ($respuesta->failed()) {
            throw new RuntimeException($this->porQueFallo($respuesta->json(), $respuesta->status()));
        }

        $datos = $respuesta->json();
        $url = $datos['init_point'] ?? $datos['sandbox_init_point'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Mercado Pago no devolvió a dónde enviar al alumno a pagar.');
        }

        return new CobroIniciado(
            url: $url,
            referenciaExterna: (string) ($datos['id'] ?? ''),
            crudo: $datos,
        );
    }

    public function interpretarAviso(Request $peticion): ?ResultadoCobro
    {
        /*
         * Mercado Pago avisa de varias cosas y por dos vías distintas: unos
         * avisos traen el tipo y el id en la query (`?type=payment&data.id=1`) y
         * otros en el cuerpo. Se aceptan las dos y se ignora todo lo que no sea
         * un pago —avisos de suscripciones, de contracargos— contestando que se
         * recibió: discutirle a una pasarela sobre sus avisos sólo consigue que
         * los reintente para siempre.
         */
        $tipo = $peticion->input('type') ?? $peticion->input('topic');

        if (! in_array($tipo, ['payment', 'merchant_order'], true)) {
            return null;
        }

        $pagoId = $peticion->input('data.id')
            ?? $peticion->input('data_id')
            ?? $peticion->input('id');

        if (blank($pagoId)) {
            return null;
        }

        return $this->consultarPago((string) $pagoId);
    }

    public function consultar(IntencionCobro $intencion): ResultadoCobro
    {
        /*
         * Se busca POR NUESTRA referencia, no por la suya: la intención conoce
         * el id de la preferencia, pero el estado vive en el pago, que es otro
         * objeto. Buscar por `external_reference` es lo que los une.
         */
        $respuesta = $this->cliente()->get(self::API.'/v1/payments/search', [
            'external_reference' => (string) $intencion->id,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]);

        if ($respuesta->failed()) {
            return $this->noSeSupo($respuesta->json());
        }

        $resultados = $respuesta->json('results') ?? [];

        if ($resultados === []) {
            // No hay pago todavía: nadie ha terminado de pagar. No es un fallo.
            return new ResultadoCobro($intencion->id, EstadoCobro::PENDIENTE);
        }

        /*
         * Puede haber varios intentos sobre la misma preferencia —tarjeta
         * rechazada y luego otra que sí pasa—. Manda el aprobado si existe;
         * quedarse con el más reciente daría por rechazado un cobro que sí entró
         * si después alguien reintentó y falló.
         */
        $aprobado = collect($resultados)->firstWhere('status', 'approved');

        return $this->deDatosDePago($aprobado ?? $resultados[0]);
    }

    public function avisoAutentico(Request $peticion, PasarelaPago $config): bool
    {
        $secreto = $config->credencialesActivas()['webhook_secret'] ?? null;

        /*
         * Sin secreto configurado no se puede comprobar quién habla, y aun así
         * se sigue: el aviso sólo dice QUÉ preguntar, y la respuesta sale de
         * consultarle a Mercado Pago con nuestro token. Un aviso falso hace que
         * preguntemos por un pago que no existe, y eso no mueve dinero.
         *
         * La firma se comprueba cuando está configurada porque evita el trabajo
         * inútil y deja constancia de intentos raros, no porque sea lo que
         * protege la caja.
         */
        if (blank($secreto)) {
            return true;
        }

        $firma = $peticion->header('x-signature');
        $peticionId = $peticion->header('x-request-id');

        if (blank($firma)) {
            return false;
        }

        // «ts=1704908010,v1=618c85345248dd820d5fd456117c2ab2ef8eda45a0…»
        $partes = collect(explode(',', $firma))
            ->mapWithKeys(function (string $parte) {
                [$clave, $valor] = array_pad(explode('=', trim($parte), 2), 2, null);

                return [trim((string) $clave) => trim((string) $valor)];
            });

        $ts = $partes['ts'] ?? null;
        $v1 = $partes['v1'] ?? null;

        if (blank($ts) || blank($v1)) {
            return false;
        }

        $id = $peticion->input('data.id') ?? $peticion->input('data_id') ?? '';
        $plantilla = "id:{$id};request-id:{$peticionId};ts:{$ts};";

        return hash_equals(hash_hmac('sha256', $plantilla, $secreto), $v1);
    }

    /** Checkout propio: quien paga elige allí, no aquí. */
    public function metodosAElegir(): array
    {
        return [];
    }

    // ── Interno ────────────────────────────────────────────────────────────

    /**
     * Qué se le ofrece al alumno, en el vocabulario de Mercado Pago.
     *
     * ── Se EXCLUYE lo apagado, no se incluye lo encendido ──────────────────
     * Su API no recibe una lista de lo permitido: recibe una de lo excluido, y
     * lo que no se excluya aparece. Invertirlo —mandar lo encendido como si
     * fuera una lista blanca— dejaría todas las formas de pago activas sin que
     * nadie lo notara, porque el cobro funcionaría igual.
     *
     * ── `installments` es el plazo MÁXIMO ──────────────────────────────────
     * No una lista: se manda el mayor de los configurados y Mercado Pago ofrece
     * los plazos hasta ése. Sin meses sin intereses va 1, que es un solo pago.
     *
     * @return array<string, mixed>
     */
    private function formasDePago(): array
    {
        $excluidos = [];

        // «ticket» es el efectivo en tiendas: OXXO, farmacias, tiendas de
        // conveniencia. «bank_transfer» y «account_money» son SPEI y el saldo
        // de la propia cuenta de Mercado Pago.
        if (! $this->config->aceptaMetodo('efectivo')) {
            $excluidos[] = ['id' => 'ticket'];
        }

        if (! $this->config->aceptaMetodo('transferencia')) {
            $excluidos[] = ['id' => 'bank_transfer'];
            $excluidos[] = ['id' => 'account_money'];
        }

        if (! $this->config->aceptaMetodo('tarjeta')) {
            $excluidos[] = ['id' => 'credit_card'];
            $excluidos[] = ['id' => 'debit_card'];
        }

        return [
            'excluded_payment_types' => $excluidos,
            'installments' => $this->config->mesesMaximos(),
        ];
    }

    private function consultarPago(string $pagoId): ResultadoCobro
    {
        $respuesta = $this->cliente()->get(self::API."/v1/payments/{$pagoId}");

        if ($respuesta->failed()) {
            return $this->noSeSupo($respuesta->json());
        }

        return $this->deDatosDePago($respuesta->json());
    }

    /** @param  array<string, mixed>  $pago */
    private function deDatosDePago(array $pago): ResultadoCobro
    {
        $referencia = $pago['external_reference'] ?? null;

        return new ResultadoCobro(
            intencionId: is_numeric($referencia) ? (int) $referencia : null,
            estado: $this->traducir((string) ($pago['status'] ?? '')),
            /*
             * `transaction_amount` es lo que se cobró. Se toma de aquí y no de
             * la intención: si por lo que sea se cobró otra cosa, lo que hay que
             * registrar es el dinero que entró, no el que se pidió.
             */
            monto: isset($pago['transaction_amount']) ? (float) $pago['transaction_amount'] : null,
            transaccionId: isset($pago['id']) ? (string) $pago['id'] : null,
            crudo: $pago,
        );
    }

    private function traducir(string $estado): EstadoCobro
    {
        return match ($estado) {
            'approved' => EstadoCobro::APROBADO,
            // `authorized` es dinero apartado, no cobrado; `in_process` e
            // `in_mediation` siguen abiertos. Ninguno liquida un adeudo.
            'pending', 'in_process', 'in_mediation', 'authorized' => EstadoCobro::PENDIENTE,
            'rejected' => EstadoCobro::RECHAZADO,
            'cancelled', 'refunded', 'charged_back' => EstadoCobro::CANCELADO,
            default => EstadoCobro::DESCONOCIDO,
        };
    }

    /** @param  array<string, mixed>|null  $crudo */
    private function noSeSupo(?array $crudo): ResultadoCobro
    {
        Log::warning('Mercado Pago no contestó a una consulta de cobro.', ['respuesta' => $crudo]);

        return new ResultadoCobro(null, EstadoCobro::DESCONOCIDO, crudo: $crudo ?? []);
    }

    private function cliente()
    {
        $token = $this->config->credencialesActivas()['access_token'] ?? null;

        if (blank($token)) {
            throw new RuntimeException('Mercado Pago no tiene configurado su Access Token.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            /*
             * Se reintenta la consulta, no el cobro: `iniciar` crea una
             * preferencia y repetirla sólo deja órdenes de cobro huérfanas, pero
             * preguntar dos veces por un pago es inofensivo y salva un webhook
             * de un tropiezo de red.
             */
            ->retry(2, 300, throw: false);
    }

    /** @param  array<string, mixed>|null  $cuerpo */
    private function porQueFallo(?array $cuerpo, int $estado): string
    {
        $motivo = $cuerpo['message'] ?? $cuerpo['error'] ?? null;

        return $motivo
            ? "Mercado Pago rechazó la solicitud de cobro: {$motivo}"
            : "Mercado Pago no pudo iniciar el cobro (HTTP {$estado}).";
    }
}
