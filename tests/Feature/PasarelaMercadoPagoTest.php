<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use App\Services\Pagos\EstadoCobro;
use App\Services\Pagos\PasarelaMercadoPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Lo que hablamos con Mercado Pago.
 *
 * Se ejercita contra respuestas fingidas porque no hay forma de tener
 * credenciales reales en una prueba, pero lo que se comprueba no es que la red
 * funcione: es la traducción. Su vocabulario —`approved`, `in_process`,
 * `charged_back`— decide si un cargo se liquida, y confundir uno significa dar
 * por pagada una colegiatura que el banco todavía está revisando.
 */
class PasarelaMercadoPagoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** Se crea la preferencia y se devuelve a dónde mandar al alumno. */
    public function test_iniciar_devuelve_la_liga_de_pago(): void
    {
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => 'PREF-123',
            'init_point' => 'https://mercadopago.com/checkout/PREF-123',
        ])]);

        $intencion = $this->intencion(2500);
        $iniciado = $this->pasarela()->iniciar($intencion, 'http://x/retorno', 'http://x/aviso');

        $this->assertSame('https://mercadopago.com/checkout/PREF-123', $iniciado->url);
        $this->assertSame('PREF-123', $iniciado->referenciaExterna);

        // Lo que se le manda importa tanto como lo que devuelve: sin nuestra
        // referencia viajando con el cobro, su aviso no se puede atribuir a nadie.
        Http::assertSent(function ($peticion) use ($intencion) {
            $cuerpo = $peticion->data();

            return $cuerpo['external_reference'] === (string) $intencion->id
                && $cuerpo['items'][0]['unit_price'] === 2500.0
                && $cuerpo['notification_url'] === 'http://x/aviso';
        });
    }

    /** Si no acepta cobrar, se dice por qué y no se sigue como si nada. */
    public function test_un_rechazo_al_iniciar_se_explica(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(['message' => 'invalid access token'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid access token');

        $this->pasarela()->iniciar($this->intencion(100), 'http://x/retorno', 'http://x/aviso');
    }

    /**
     * El aviso trae el id de un PAGO, no el de nuestra preferencia: hay que ir a
     * preguntar. Es la diferencia entre creerle al mensaje y verificarlo.
     */
    public function test_el_aviso_se_resuelve_preguntando(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/99*' => Http::response([
            'id' => 99,
            'status' => 'approved',
            'transaction_amount' => 1500.5,
            'external_reference' => '77',
        ])]);

        $resultado = $this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => 'payment', 'data' => ['id' => 99]]),
        );

        $this->assertSame(EstadoCobro::APROBADO, $resultado->estado);
        $this->assertSame(77, $resultado->intencionId);
        $this->assertSame(1500.5, $resultado->monto);
        $this->assertSame('99', $resultado->transaccionId);
    }

    /** Un aviso que no es de un pago se ignora sin ruido. */
    public function test_los_avisos_de_otras_cosas_se_ignoran(): void
    {
        Http::fake();

        $this->assertNull($this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => 'subscription_preapproval', 'data' => ['id' => 5]]),
        ));

        Http::assertNothingSent();
    }

    /**
     * Su vocabulario, traducido. `authorized` es dinero apartado y NO cobrado:
     * tomarlo por bueno liquidaría un cargo con dinero que aún puede no llegar.
     */
    public function test_traduce_los_estados(): void
    {
        $esperado = [
            'approved' => EstadoCobro::APROBADO,
            'pending' => EstadoCobro::PENDIENTE,
            'in_process' => EstadoCobro::PENDIENTE,
            'authorized' => EstadoCobro::PENDIENTE,
            'rejected' => EstadoCobro::RECHAZADO,
            'cancelled' => EstadoCobro::CANCELADO,
            'refunded' => EstadoCobro::CANCELADO,
            'charged_back' => EstadoCobro::CANCELADO,
            'una_palabra_nueva' => EstadoCobro::DESCONOCIDO,
        ];

        /*
         * Las respuestas van en SECUENCIA y no re-fingiendo dentro del bucle:
         * `Http::fake()` acumula stubs en vez de reemplazarlos, así que el
         * primero registrado contesta siempre y las nueve iteraciones veían
         * «approved». La prueba pasaba en verde diciendo lo contrario de lo que
         * afirmaba.
         */
        $secuencia = Http::sequence();

        foreach (array_keys($esperado) as $suyo) {
            $secuencia->push(['id' => 1, 'status' => $suyo, 'external_reference' => '5']);
        }

        Http::fake(['api.mercadopago.com/v1/payments/*' => $secuencia]);

        foreach ($esperado as $suyo => $nuestro) {
            $resultado = $this->pasarela()->interpretarAviso(
                Request::create('/aviso', 'POST', ['type' => 'payment', 'data' => ['id' => 1]]),
            );

            $this->assertSame($nuestro, $resultado->estado, "«{$suyo}» se tradujo mal.");
        }
    }

    /**
     * Si no contesta, la respuesta honesta es «no sé» — que deja el cobro
     * abierto. Darlo por fallido dejaría sin aplicar un pago que quizá entró.
     */
    public function test_si_no_contesta_no_se_inventa_un_veredicto(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response([], 500)]);

        $resultado = $this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => 'payment', 'data' => ['id' => 1]]),
        );

        $this->assertSame(EstadoCobro::DESCONOCIDO, $resultado->estado);
    }

    /**
     * Al consultar, un aprobado manda sobre un rechazo posterior.
     *
     * Sobre la misma orden puede haber varios intentos —tarjeta rechazada y
     * luego otra que sí pasa—. Quedarse con el más reciente daría por rechazado
     * un cobro que sí entró.
     */
    public function test_al_consultar_gana_el_pago_aprobado(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response([
            'results' => [
                ['id' => 2, 'status' => 'rejected', 'external_reference' => '5'],
                ['id' => 1, 'status' => 'approved', 'transaction_amount' => 300.0, 'external_reference' => '5'],
            ],
        ])]);

        $resultado = $this->pasarela()->consultar($this->intencion(300));

        $this->assertSame(EstadoCobro::APROBADO, $resultado->estado);
        $this->assertSame('1', $resultado->transaccionId);
    }

    /** Sin pagos todavía, el cobro sigue pendiente: nadie ha terminado. */
    public function test_sin_pagos_el_cobro_sigue_pendiente(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []])]);

        $this->assertSame(EstadoCobro::PENDIENTE, $this->pasarela()->consultar($this->intencion(300))->estado);
    }

    /** Con secreto configurado, una firma que no cuadra se rechaza. */
    public function test_la_firma_del_aviso_se_comprueba(): void
    {
        $config = $this->config(['access_token' => 'TEST-123', 'webhook_secret' => 'shh']);
        $pasarela = new PasarelaMercadoPago($config);

        $peticion = Request::create('/aviso', 'POST', ['data' => ['id' => '99']]);
        $peticion->headers->set('x-request-id', 'req-1');
        $peticion->headers->set('x-signature', 'ts=1700000000,v1=maliciosamente-inventada');

        $this->assertFalse($pasarela->avisoAutentico($peticion, $config));

        // Y la correcta pasa: se firma igual que ellos lo hacen.
        $plantilla = 'id:99;request-id:req-1;ts:1700000000;';
        $buena = hash_hmac('sha256', $plantilla, 'shh');
        $peticion->headers->set('x-signature', "ts=1700000000,v1={$buena}");

        $this->assertTrue($pasarela->avisoAutentico($peticion, $config));
    }

    /**
     * Sin secreto configurado se sigue adelante.
     *
     * No es un descuido: el aviso sólo dice QUÉ preguntar y la verdad sale de la
     * consulta con nuestro token. Exigir el secreto tiraría todos los avisos de
     * las escuelas que no lo configuraron —y ninguno de sus pagos se aplicaría—
     * a cambio de una seguridad que no es la que protege la caja.
     */
    public function test_sin_secreto_configurado_el_aviso_pasa(): void
    {
        $config = $this->config(['access_token' => 'TEST-123']);

        $this->assertTrue(
            (new PasarelaMercadoPago($config))->avisoAutentico(Request::create('/aviso', 'POST'), $config),
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function pasarela(): PasarelaMercadoPago
    {
        return new PasarelaMercadoPago($this->config(['access_token' => 'TEST-123']));
    }

    /** @param  array<string, mixed>  $credenciales */
    private function config(array $credenciales): PasarelaPago
    {
        $config = PasarelaPago::para('mercadopago');

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
            'pasarela' => 'mercadopago',
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'monto' => $monto,
        ]);
    }
}
