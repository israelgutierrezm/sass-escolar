<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use App\Services\Pagos\EstadoCobro;
use App\Services\Pagos\PasarelaStripe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Lo que hablamos con Stripe.
 *
 * Tiene dos rarezas frente a las demás y las dos se pagan caras: habla en
 * formulario en vez de JSON, y su veredicto sale de dos campos distintos que es
 * fácil confundir. Confundirlos da por cobrada una colegiatura que sigue sin
 * pagarse.
 */
class PasarelaStripeTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** La sesión se crea y devuelve la liga de pago. */
    public function test_iniciar_devuelve_la_liga_de_pago(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_1',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
        ])]);

        $iniciado = $this->pasarela()->iniciar($this->intencion(1650), 'http://x/retorno', 'http://x/aviso');

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $iniciado->url);
        $this->assertSame('cs_test_1', $iniciado->referenciaExterna);
    }

    /**
     * Va como FORMULARIO, no como JSON.
     *
     * Es la rareza de esta API: mandarle JSON no da un error claro, da un
     * «parámetro desconocido» por cada campo y ninguna sesión.
     */
    public function test_habla_en_formulario_y_en_centavos(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://x'])]);

        $this->pasarela(['tarjeta' => true])->iniciar($this->intencion(1650), 'http://x', 'http://y');

        Http::assertSent(function ($peticion) {
            $cuerpo = (string) $peticion->body();

            return str_contains($peticion->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
                // 1650 pesos = 165000 centavos, en notación de corchetes.
                && str_contains(urldecode($cuerpo), 'line_items[0][price_data][unit_amount]=165000')
                && str_contains(urldecode($cuerpo), 'line_items[0][price_data][currency]=mxn');
        });
    }

    /** Nuestra referencia viaja con la sesión: sin ella el aviso no se atribuye. */
    public function test_manda_nuestra_referencia(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://x'])]);

        $intencion = $this->intencion(500);
        $this->pasarela()->iniciar($intencion, 'http://x', 'http://y');

        Http::assertSent(fn ($p) => str_contains(
            urldecode((string) $p->body()),
            "client_reference_id={$intencion->id}",
        ));
    }

    /** Lo apagado no se le ofrece a quien paga. */
    public function test_solo_se_ofrecen_los_metodos_encendidos(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://x'])]);

        $this->pasarela(['tarjeta' => true, 'oxxo' => true])->iniciar($this->intencion(500), 'http://x', 'http://y');

        Http::assertSent(function ($peticion) {
            $cuerpo = urldecode((string) $peticion->body());

            return str_contains($cuerpo, 'payment_method_types[0]=card')
                && str_contains($cuerpo, 'payment_method_types[1]=oxxo');
        });
    }

    /**
     * Los meses se ENCIENDEN, no se listan.
     *
     * A diferencia de las otras, aquí los plazos los pone el banco emisor de la
     * tarjeta; la escuela sólo decide si se ofrecen.
     */
    public function test_los_meses_se_encienden(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://x'])]);

        $this->pasarela(['tarjeta' => true, 'msi' => [3, 6]])->iniciar($this->intencion(500), 'http://x', 'http://y');

        Http::assertSent(fn ($p) => str_contains(
            urldecode((string) $p->body()),
            'payment_method_options[card][installments][enabled]=true',
        ));

        // Y sin meses configurados, no se manda nada de installments.
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_2', 'url' => 'https://x'])]);
        $this->pasarela(['tarjeta' => true, 'msi' => []])->iniciar($this->intencion(500), 'http://x', 'http://y');

        Http::assertSent(fn ($p) => ! str_contains(urldecode((string) $p->body()), 'installments'));
    }

    /**
     * Una sesión terminada SIN pagar no es un cobro.
     *
     * Es el caso del OXXO: `status = complete` porque la sesión acabó, pero
     * `payment_status = unpaid` porque nadie ha ido a la tienda. Mirar sólo el
     * primero liquidaría el cargo con dinero que no existe.
     */
    public function test_una_sesion_completa_sin_pagar_no_liquida(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([
            'id' => 'cs_1',
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'client_reference_id' => '7',
            'amount_total' => 165000,
        ])]);

        $resultado = $this->aviso('checkout.session.completed', 'cs_1');

        $this->assertSame(EstadoCobro::PENDIENTE, $resultado->estado);
        $this->assertSame(7, $resultado->intencionId);
    }

    /** Y una pagada sí, con su monto de vuelta en pesos. */
    public function test_una_sesion_pagada_liquida(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([
            'id' => 'cs_1',
            'status' => 'complete',
            'payment_status' => 'paid',
            'client_reference_id' => '7',
            'amount_total' => 165000,
            'payment_intent' => 'pi_123',
        ])]);

        $resultado = $this->aviso('checkout.session.completed', 'cs_1');

        $this->assertSame(EstadoCobro::APROBADO, $resultado->estado);
        $this->assertSame(1650.0, $resultado->monto);
        $this->assertSame('pi_123', $resultado->transaccionId);
    }

    /**
     * El aviso tardío del OXXO se atiende.
     *
     * Llega días después de que la sesión terminó; sin escucharlo, un pago en
     * efectivo no se aplicaría nunca.
     */
    public function test_se_escucha_el_pago_tardio_en_efectivo(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([
            'id' => 'cs_1', 'status' => 'complete', 'payment_status' => 'paid',
            'client_reference_id' => '7', 'amount_total' => 50000,
        ])]);

        $resultado = $this->aviso('checkout.session.async_payment_succeeded', 'cs_1');

        $this->assertSame(EstadoCobro::APROBADO, $resultado->estado);
    }

    /** Una sesión caducada se cierra: su referencia de OXXO ya no sirve. */
    public function test_una_sesion_caducada_se_cancela(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([
            'id' => 'cs_1', 'status' => 'expired', 'payment_status' => 'unpaid', 'client_reference_id' => '7',
        ])]);

        $this->assertSame(EstadoCobro::CANCELADO, $this->aviso('checkout.session.expired', 'cs_1')->estado);
    }

    /** Los avisos de otras cosas se ignoran sin preguntar nada. */
    public function test_los_avisos_de_otras_cosas_se_ignoran(): void
    {
        Http::fake();

        $this->assertNull($this->aviso('customer.created', 'cus_1'));

        Http::assertNothingSent();
    }

    /** Si no acepta cobrar, se dice por qué. */
    public function test_un_rechazo_al_iniciar_se_explica(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([
            'error' => ['message' => 'Invalid API Key provided'],
        ], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid API Key provided');

        $this->pasarela()->iniciar($this->intencion(100), 'http://x', 'http://y');
    }

    /**
     * La firma se comprueba sobre el cuerpo CRUDO.
     *
     * Volver a serializar el JSON cambia el orden y los espacios, y la firma
     * dejaría de cuadrar aunque el aviso sea legítimo.
     */
    public function test_la_firma_del_aviso_se_comprueba(): void
    {
        $config = $this->config(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_1']);
        $pasarela = new PasarelaStripe($config);

        $cuerpo = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_1"}}}';
        $peticion = Request::create('/aviso', 'POST', [], [], [], [], $cuerpo);

        $peticion->headers->set('Stripe-Signature', 't=1700000000,v1=inventada');
        $this->assertFalse($pasarela->avisoAutentico($peticion, $config));

        $buena = hash_hmac('sha256', '1700000000.'.$cuerpo, 'whsec_1');
        $peticion->headers->set('Stripe-Signature', "t=1700000000,v1={$buena}");
        $this->assertTrue($pasarela->avisoAutentico($peticion, $config));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function aviso(string $tipo, string $sesionId)
    {
        return $this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => $tipo, 'data' => ['object' => ['id' => $sesionId]]]),
        );
    }

    /** @param  array<string, mixed>  $opciones */
    private function pasarela(array $opciones = ['tarjeta' => true]): PasarelaStripe
    {
        $config = $this->config(['secret_key' => 'sk_test', 'publishable_key' => 'pk_test']);

        $config->opciones = $opciones;
        $config->save();

        return new PasarelaStripe($config->fresh());
    }

    /** @param  array<string, mixed>  $credenciales */
    private function config(array $credenciales): PasarelaPago
    {
        $config = PasarelaPago::para('stripe');

        $config->fill([
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'credenciales_pruebas' => $credenciales,
        ])->save();

        return $config->fresh();
    }

    private function intencion(float $monto): IntencionCobro
    {
        return IntencionCobro::create([
            'matricula_oferta_id' => $this->alumnoInscrito()['matricula'],
            'pasarela' => 'stripe',
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'monto' => $monto,
        ]);
    }
}
