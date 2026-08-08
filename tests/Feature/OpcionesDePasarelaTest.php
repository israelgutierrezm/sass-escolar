<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\PasarelaPagoController;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use App\Services\Pagos\PasarelaConekta;
use App\Services\Pagos\PasarelaMercadoPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Qué ofrece cada pasarela: meses sin intereses, efectivo en tienda, SPEI.
 *
 * Lo que se configura aquí tiene que llegar a la pasarela, y ése es justo el
 * tramo donde un error no se nota: una escuela enciende doce meses sin
 * intereses, la pantalla se lo confirma, y la liga de pago sale sin MSI porque
 * el dato nunca viajó. No falla nada; simplemente no está.
 */
class OpcionesDePasarelaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        Session::start();
        $this->actingAs($this->usuarioConAlcance());
    }

    /** Sin configurar nada, valen los valores por omisión del catálogo. */
    public function test_por_omision_se_acepta_lo_que_dice_el_catalogo(): void
    {
        $mp = PasarelaPago::para('mercadopago');

        $this->assertTrue($mp->aceptaMetodo('tarjeta'));
        $this->assertTrue($mp->aceptaMetodo('efectivo'), 'El efectivo en tienda viene encendido.');
        $this->assertSame([], $mp->mesesSinIntereses(), 'Los meses NO: cuestan comisión y se eligen.');
    }

    /**
     * Una opción que la pasarela estrene llega con su valor por omisión.
     *
     * Si se devolviera lo guardado tal cual, las escuelas configuradas antes de
     * que existiera la verían apagada para siempre sin haberlo decidido.
     */
    public function test_una_opcion_nueva_no_nace_apagada(): void
    {
        $mp = PasarelaPago::para('mercadopago');

        // Como si se hubiera guardado cuando sólo existía «tarjeta».
        $mp->opciones = ['tarjeta' => true];
        $mp->save();

        $this->assertTrue($mp->fresh()->aceptaMetodo('efectivo'));
    }

    /** Los meses se guardan y se leen de mayor a menor. */
    public function test_los_meses_se_ordenan_del_mayor_al_menor(): void
    {
        $mp = $this->configurar('mercadopago', ['tarjeta' => true, 'msi' => [3, 12, 6]]);

        $this->assertSame([12, 6, 3], $mp->mesesSinIntereses());
        $this->assertSame(12, $mp->mesesMaximos(), 'Varias pasarelas sólo aceptan el plazo máximo.');
    }

    /**
     * Sin tarjeta no hay meses sin intereses.
     *
     * Los meses los pone la tarjeta de crédito. Ofrecerlos con la tarjeta
     * apagada es ofrecer algo que no puede ocurrir.
     */
    public function test_sin_tarjeta_no_hay_meses(): void
    {
        $mp = $this->configurar('mercadopago', ['tarjeta' => false, 'efectivo' => true, 'msi' => [6, 12]]);

        $this->assertSame([], $mp->mesesSinIntereses());
        $this->assertSame(1, $mp->mesesMaximos(), 'Un solo pago.');
    }

    /** Un plazo que la pasarela no ofrece se descarta al guardar. */
    public function test_no_se_guarda_un_plazo_inventado(): void
    {
        $mp = $this->configurar('mercadopago', ['tarjeta' => true, 'msi' => [6, 24, 99]]);

        $this->assertSame([6], $mp->mesesSinIntereses(), '24 y 99 no están en su catálogo.');
    }

    /**
     * No se pueden apagar todas las formas de pago.
     *
     * Dejaría una pasarela que abre el cobro y no ofrece con qué pagarlo: el
     * error saldría con el alumno delante de una pantalla vacía.
     */
    public function test_no_se_pueden_apagar_todas_las_formas_de_pago(): void
    {
        $this->expectException(AvisoParaElUsuario::class);

        $this->configurar('mercadopago', ['tarjeta' => false, 'efectivo' => false, 'transferencia' => false]);
    }

    /** Lo apagado se le EXCLUYE a Mercado Pago, que es como entiende su API. */
    public function test_mercado_pago_recibe_lo_excluido_y_el_plazo(): void
    {
        $config = $this->configurar('mercadopago', [
            'tarjeta' => true, 'efectivo' => false, 'transferencia' => false, 'msi' => [3, 6],
        ]);

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => 'PREF-1', 'init_point' => 'https://mp/x',
        ])]);

        (new PasarelaMercadoPago($config))->iniciar($this->intencion('mercadopago', 1000), 'http://x', 'http://y');

        Http::assertSent(function ($peticion) {
            $formas = $peticion->data()['payment_methods'];
            $excluidos = collect($formas['excluded_payment_types'])->pluck('id')->all();

            return in_array('ticket', $excluidos, true)
                && in_array('bank_transfer', $excluidos, true)
                && ! in_array('credit_card', $excluidos, true)
                && $formas['installments'] === 6;
        });
    }

    /** Con todo encendido no se excluye nada. */
    public function test_con_todo_encendido_no_se_excluye_nada(): void
    {
        $config = $this->configurar('mercadopago', [
            'tarjeta' => true, 'efectivo' => true, 'transferencia' => true,
        ]);

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => 'PREF-1', 'init_point' => 'https://mp/x',
        ])]);

        (new PasarelaMercadoPago($config))->iniciar($this->intencion('mercadopago', 500), 'http://x', 'http://y');

        Http::assertSent(fn ($p) => $p->data()['payment_methods']['excluded_payment_types'] === []
            && $p->data()['payment_methods']['installments'] === 1);
    }

    /** A Conekta se le manda lo PERMITIDO, que es como entiende ella. */
    public function test_conekta_recibe_lo_permitido_y_sus_meses(): void
    {
        $config = $this->configurar('conekta', [
            'tarjeta' => true, 'oxxo' => true, 'spei' => false, 'msi' => [3, 12],
        ], ['private_key' => 'key_test']);

        Http::fake(['api.conekta.io/orders' => Http::response([
            'id' => 'ord_1', 'checkout' => ['url' => 'https://conekta/x'],
        ])]);

        (new PasarelaConekta($config))->iniciar($this->intencion('conekta', 1000), 'http://x', 'http://y');

        Http::assertSent(function ($peticion) {
            $checkout = $peticion->data()['checkout'];

            return $checkout['allowed_payment_methods'] === ['card', 'cash']
                && $checkout['monthly_installments_enabled'] === true
                && $checkout['monthly_installments_options'] === [12, 3];
        });
    }

    /**
     * Conekta cobra en CENTAVOS.
     *
     * Es el error más caro de esta API: mandar 1650 en vez de 165000 cobra
     * dieciséis pesos con cincuenta. No falla, no avisa: cobra otra cosa.
     */
    public function test_conekta_recibe_centavos(): void
    {
        $config = $this->configurar('conekta', ['tarjeta' => true], ['private_key' => 'key_test']);

        Http::fake(['api.conekta.io/orders' => Http::response([
            'id' => 'ord_1', 'checkout' => ['url' => 'https://conekta/x'],
        ])]);

        (new PasarelaConekta($config))->iniciar($this->intencion('conekta', 1650), 'http://x', 'http://y');

        Http::assertSent(fn ($p) => $p->data()['line_items'][0]['unit_price'] === 165000);
    }

    /**
     * Y se REDONDEA al pasarlos, no se trunca.
     *
     * `8.29 * 100` vale 828.9999… en coma flotante, así que truncar cobra 828:
     * un centavo de menos. Nadie lo reclama, y por eso puede estar años sin
     * notarse —hasta que hay que cuadrar la caja contra el reporte de la
     * pasarela—. El monto de esta prueba está elegido para que la diferencia
     * exista: con cifras redondas las dos formas dan lo mismo y la prueba no
     * comprobaría nada.
     */
    public function test_los_centavos_se_redondean_no_se_truncan(): void
    {
        $config = $this->configurar('conekta', ['tarjeta' => true], ['private_key' => 'key_test']);

        Http::fake(['api.conekta.io/orders' => Http::response([
            'id' => 'ord_1', 'checkout' => ['url' => 'https://conekta/x'],
        ])]);

        (new PasarelaConekta($config))->iniciar($this->intencion('conekta', 8.29), 'http://x', 'http://y');

        Http::assertSent(fn ($p) => $p->data()['line_items'][0]['unit_price'] === 829);
    }

    /** Y al leer una orden suya, los centavos vuelven a pesos. */
    public function test_conekta_devuelve_pesos(): void
    {
        $config = $this->configurar('conekta', ['tarjeta' => true], ['private_key' => 'key_test']);

        Http::fake(['api.conekta.io/orders/*' => Http::response([
            'id' => 'ord_1',
            'payment_status' => 'paid',
            'amount' => 165010,
            'metadata' => ['intencion' => '42'],
        ])]);

        $resultado = (new PasarelaConekta($config))->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => 'order.paid', 'data' => ['object' => ['id' => 'ord_1']]]),
        );

        $this->assertSame(1650.10, $resultado->monto);
        $this->assertSame(42, $resultado->intencionId);
    }

    /** PayPal no tiene opciones, y eso no es un olvido. */
    public function test_paypal_no_ofrece_opciones(): void
    {
        $this->assertSame([], PasarelaPago::para('paypal')->opciones());
        $this->assertSame([], PasarelaPago::para('paypal')->metodosAceptados());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $opciones
     * @param  array<string, string>  $credenciales
     */
    private function configurar(string $clave, array $opciones, array $credenciales = []): PasarelaPago
    {
        $peticion = Request::create("/plataforma/configuraciones/pasarelas/{$clave}", 'PUT', [
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'activa' => false,
            'credenciales' => $credenciales ?: ['access_token' => 'TEST-1', 'public_key' => 'PUB-1'],
            'opciones' => $opciones,
        ]);

        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(PasarelaPagoController::class)->guardar($peticion, $clave);

        return PasarelaPago::para($clave)->fresh();
    }

    private function intencion(string $pasarela, float $monto): IntencionCobro
    {
        return IntencionCobro::create([
            'matricula_oferta_id' => $this->alumnoInscrito()['matricula'],
            'pasarela' => $pasarela,
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'monto' => $monto,
        ]);
    }
}
