<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use App\Services\Pagos\EstadoCobro;
use App\Services\Pagos\PasarelaPayPal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * PayPal, donde aprobar no es cobrar.
 *
 * Es la diferencia de fondo con las otras cuatro: cuando quien paga termina en
 * PayPal, la orden queda `APPROVED` —hay permiso, el dinero no se ha movido— y
 * falta capturarla. Tomar `APPROVED` por bueno liquidaría el cargo con dinero
 * que nadie transfirió, y la autorización caduca a los tres días: el alumno
 * vería su cuenta al corriente y la escuela no habría cobrado nunca.
 *
 * Casi todo lo que se prueba aquí sale de eso.
 */
class PasarelaPayPalTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        // El token se cachea; entre pruebas no debe arrastrarse.
        Cache::flush();
    }

    /** Se crea la orden y se devuelve la liga de aprobación. */
    public function test_iniciar_devuelve_la_liga_de_pago(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders' => [
                'id' => '5O190127TN364715T',
                'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api/x'],
                    ['rel' => 'payer-action', 'href' => 'https://www.paypal.com/checkoutnow?token=5O1'],
                ],
            ],
        ]);

        $iniciado = $this->pasarela()->iniciar($this->intencion(1650), 'http://x/retorno', 'http://x/aviso');

        $this->assertSame('https://www.paypal.com/checkoutnow?token=5O1', $iniciado->url);
        $this->assertSame('5O190127TN364715T', $iniciado->referenciaExterna);
    }

    /**
     * El monto va como TEXTO con dos decimales.
     *
     * Mandar un número deja que la coma flotante decida los centavos.
     */
    public function test_el_monto_va_como_texto_con_dos_decimales(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders' => ['id' => 'o1', 'links' => [['rel' => 'approve', 'href' => 'https://p/x']]],
        ]);

        $intencion = $this->intencion(1650.5);
        $this->pasarela()->iniciar($intencion, 'http://x', 'http://y');

        Http::assertSent(function ($peticion) use ($intencion) {
            if (! str_contains($peticion->url(), '/v2/checkout/orders')) {
                return false;
            }

            $unidad = $peticion->data()['purchase_units'][0];

            return $unidad['amount']['value'] === '1650.50'
                && $unidad['custom_id'] === (string) $intencion->id;
        });
    }

    /**
     * Una orden aprobada se CAPTURA, y sólo entonces hay dinero.
     *
     * Es la prueba que sostiene toda esta clase.
     */
    public function test_una_orden_aprobada_se_captura(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders/o1/capture' => [
                'id' => 'o1',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'custom_id' => '7',
                    'payments' => ['captures' => [['amount' => ['value' => '1650.00']]]],
                ]],
            ],
            'v2/checkout/orders/o1' => ['id' => 'o1', 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => '7']]],
        ]);

        $resultado = $this->aviso('CHECKOUT.ORDER.APPROVED', 'o1');

        $this->assertSame(EstadoCobro::APROBADO, $resultado->estado);
        $this->assertSame(1650.0, $resultado->monto, 'El importe bueno es el de la captura.');
        $this->assertSame(7, $resultado->intencionId);

        // Y se llamó de verdad a capturar, no sólo a mirar.
        Http::assertSent(fn ($p) => str_contains($p->url(), '/capture') && $p->method() === 'POST');
    }

    /**
     * Si la captura falla, el cobro queda PENDIENTE, no aprobado.
     *
     * Hay permiso pero no dinero: darlo por cobrado es justo el error que esta
     * clase existe para evitar.
     */
    public function test_si_no_se_puede_capturar_no_hay_cobro(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders/o1/capture' => [['message' => 'INSTRUMENT_DECLINED'], 422],
            'v2/checkout/orders/o1' => ['id' => 'o1', 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => '7']]],
        ]);

        $resultado = $this->aviso('CHECKOUT.ORDER.APPROVED', 'o1');

        $this->assertSame(EstadoCobro::PENDIENTE, $resultado->estado);
        $this->assertSame(7, $resultado->intencionId, 'Aun fallando, se sabe de quién era.');
    }

    /**
     * Que ya estuviera capturada no es un error.
     *
     * Pasa siempre que el aviso y el retorno llegan casi a la vez, que es lo
     * normal: los dos concilian.
     */
    public function test_capturar_dos_veces_no_rompe_nada(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders/o1/capture' => [
                ['details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]],
                422,
            ],
            'v2/checkout/orders/o1' => ['id' => 'o1', 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => '7', 'amount' => ['value' => '500.00']]]],
        ]);

        $resultado = $this->aviso('CHECKOUT.ORDER.APPROVED', 'o1');

        $this->assertSame(EstadoCobro::APROBADO, $resultado->estado);
        $this->assertSame(500.0, $resultado->monto);
    }

    /**
     * Una captura que responde bien pero SIN completar tampoco cobra.
     *
     * Es el último cerrojo: aunque la llamada a capturar salga 200, lo que
     * decide es el estado que devuelve. Si sigue en `APPROVED` hay permiso y no
     * dinero, y darlo por cobrado es exactamente el error contra el que existe
     * toda esta clase. Sin esta prueba, cambiar una línea del `match` dejaba
     * pasar el cobro fantasma sin que fallara nada.
     */
    public function test_una_captura_que_no_completa_no_cobra(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders/o1/capture' => [
                'id' => 'o1',
                'status' => 'APPROVED', // respondió bien, pero no completó
                'purchase_units' => [['custom_id' => '7']],
            ],
            'v2/checkout/orders/o1' => ['id' => 'o1', 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => '7']]],
        ]);

        $this->assertSame(EstadoCobro::PENDIENTE, $this->aviso('CHECKOUT.ORDER.APPROVED', 'o1')->estado);
    }

    /** Una orden ya completada no se vuelve a capturar. */
    public function test_una_orden_completada_no_se_recaptura(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders/o1' => [
                'id' => 'o1',
                'status' => 'COMPLETED',
                'purchase_units' => [['custom_id' => '7', 'amount' => ['value' => '900.00']]],
            ],
        ]);

        $resultado = $this->aviso('CHECKOUT.ORDER.COMPLETED', 'o1');

        $this->assertSame(EstadoCobro::APROBADO, $resultado->estado);

        Http::assertNotSent(fn ($p) => str_contains($p->url(), '/capture'));
    }

    /** Su vocabulario, traducido. */
    public function test_traduce_los_estados(): void
    {
        $esperado = [
            'COMPLETED' => EstadoCobro::APROBADO,
            'CREATED' => EstadoCobro::PENDIENTE,
            'PAYER_ACTION_REQUIRED' => EstadoCobro::PENDIENTE,
            'VOIDED' => EstadoCobro::CANCELADO,
            'PALABRA_NUEVA' => EstadoCobro::DESCONOCIDO,
        ];

        /*
         * En SECUENCIA y con un solo `Http::fake`: llamarlo dentro del bucle
         * acumula stubs en vez de reemplazarlos, así que el primero contestaría
         * siempre y la prueba pasaría en verde comprobando una sola traducción.
         */
        $ordenes = Http::sequence();

        foreach (array_keys($esperado) as $suyo) {
            $ordenes->push(['id' => 'o1', 'status' => $suyo, 'purchase_units' => [['custom_id' => '7']]]);
        }

        Http::fake([
            '*v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            '*v2/checkout/orders/o1' => $ordenes,
        ]);

        foreach ($esperado as $suyo => $nuestro) {
            $this->assertSame($nuestro, $this->aviso('CHECKOUT.ORDER.COMPLETED', 'o1')->estado, "«{$suyo}» se tradujo mal.");
        }
    }

    /** Los avisos de otras cosas se ignoran sin preguntar nada. */
    public function test_los_avisos_de_otras_cosas_se_ignoran(): void
    {
        Http::fake();

        $this->assertNull($this->aviso('BILLING.SUBSCRIPTION.CREATED', 'x'));

        Http::assertNothingSent();
    }

    /** Sandbox y producción son dominios distintos. */
    public function test_pruebas_y_produccion_son_dominios_distintos(): void
    {
        $this->fingir([
            'v1/oauth2/token' => ['access_token' => 'tok'],
            'v2/checkout/orders' => ['id' => 'o1', 'links' => [['rel' => 'approve', 'href' => 'https://p/x']]],
        ]);

        $this->pasarela()->iniciar($this->intencion(100), 'http://x', 'http://y');
        Http::assertSent(fn ($p) => str_contains($p->url(), 'api-m.sandbox.paypal.com'));

        Cache::flush();

        $config = $this->config();
        $config->fill([
            'ambiente' => PasarelaPago::AMBIENTE_PRODUCCION,
            'credenciales_produccion' => ['client_id' => 'id2', 'client_secret' => 'sec2'],
        ])->save();

        (new PasarelaPayPal($config->fresh()))->iniciar($this->intencion(100), 'http://x', 'http://y');

        Http::assertSent(fn ($p) => str_contains($p->url(), '//api-m.paypal.com'));
    }

    /** Sin credenciales aceptadas, se dice y no se sigue. */
    public function test_unas_credenciales_rechazadas_se_explican(): void
    {
        Http::fake(['*/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no aceptó las credenciales');

        $this->pasarela()->iniciar($this->intencion(100), 'http://x', 'http://y');
    }

    /** No ofrece nada que elegir antes: su pantalla lo presenta. */
    public function test_no_pide_elegir_metodo(): void
    {
        $this->assertSame([], $this->pasarela()->metodosAElegir());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $respuestas */
    private function fingir(array $respuestas): void
    {
        Http::fake(collect($respuestas)
            ->mapWithKeys(fn ($cuerpo, string $ruta) => [
                "*{$ruta}" => is_array($cuerpo) && isset($cuerpo[1]) && is_int($cuerpo[1])
                    ? Http::response($cuerpo[0], $cuerpo[1])
                    : Http::response($cuerpo),
            ])
            ->all());
    }

    private function aviso(string $evento, string $ordenId)
    {
        return $this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['event_type' => $evento, 'resource' => ['id' => $ordenId]]),
        );
    }

    private function pasarela(): PasarelaPayPal
    {
        return new PasarelaPayPal($this->config());
    }

    private function config(): PasarelaPago
    {
        $config = PasarelaPago::para('paypal');

        $config->fill([
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'credenciales_pruebas' => ['client_id' => 'id1', 'client_secret' => 'sec1'],
        ])->save();

        return $config->fresh();
    }

    private function intencion(float $monto): IntencionCobro
    {
        return IntencionCobro::create([
            'matricula_oferta_id' => $this->alumnoInscrito()['matricula'],
            'pasarela' => 'paypal',
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'monto' => $monto,
        ]);
    }
}
