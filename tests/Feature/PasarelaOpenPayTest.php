<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use App\Services\Pagos\CobroEnLinea;
use App\Services\Pagos\EstadoCobro;
use App\Services\Pagos\PasarelaOpenPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * OpenPay, que es la que no encaja como las demás.
 *
 * Las otras dan una liga a un checkout suyo donde quien paga elige. Ésta cobra
 * por CARGO y hay que decirle desde el principio de qué tipo es, así que la
 * elección tiene que ocurrir en nuestra pantalla. Todo lo que se prueba aquí
 * sale de esa diferencia.
 */
class PasarelaOpenPayTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** Dice qué hay que preguntar antes de salir. */
    public function test_pide_elegir_la_forma_de_pago(): void
    {
        $metodos = collect($this->pasarela(['tarjeta' => true, 'tienda' => true, 'spei' => true])->metodosAElegir());

        $this->assertSame(['tarjeta', 'tienda', 'spei'], $metodos->pluck('clave')->all());
        $this->assertNotEmpty($metodos->first()['etiqueta'], 'Cada opción se le enseña a quien paga.');
    }

    /** Y sólo las que la escuela encendió. */
    public function test_solo_ofrece_lo_encendido(): void
    {
        $metodos = collect($this->pasarela(['tarjeta' => true, 'tienda' => false, 'spei' => false])->metodosAElegir());

        $this->assertSame(['tarjeta'], $metodos->pluck('clave')->all());
    }

    /**
     * Sin elegir, no se cobra.
     *
     * Cobrar con tarjeta a quien iba a pagar en efectivo es cobrarle de una
     * manera que no aceptó, así que se pide en vez de suponer.
     */
    public function test_sin_metodo_no_se_inicia_el_cobro(): void
    {
        $this->activar();
        config()->set('pagos.modo', 'real');

        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 500);

        $this->expectException(AvisoParaElUsuario::class);

        app(CobroEnLinea::class)->iniciar(
            MatriculaOferta::findOrFail($escuela['matricula']),
            'openpay',
            [$adeudo],
            'http://x/retorno',
            'http://x/aviso',
            // sin método
        );
    }

    /**
     * Un método que la escuela no ofrece se rechaza.
     *
     * Los apagados van EXPLÍCITOS: lo que no se declara toma el valor por
     * omisión del catálogo, que para tienda y SPEI es encendido. La primera
     * versión de esta prueba los omitía y por eso no probaba nada —el método
     * «apagado» seguía disponible—.
     */
    public function test_un_metodo_apagado_se_rechaza(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->pasarela(['tarjeta' => true, 'tienda' => false, 'spei' => false])
            ->iniciar($this->intencion(500), 'http://x', 'http://y', 'spei');
    }

    /**
     * Habla en PESOS, al revés que Conekta y Stripe.
     *
     * Mandarle centavos cobraría cien veces de más: mil seiscientos cincuenta
     * pesos se convertirían en ciento sesenta y cinco mil.
     */
    public function test_habla_en_pesos_no_en_centavos(): void
    {
        Http::fake(['*openpay.mx/*' => Http::response([
            'id' => 'trq1', 'payment_method' => ['url' => 'https://openpay/x'],
        ])]);

        $this->pasarela()->iniciar($this->intencion(1650.50), 'http://x', 'http://y', 'tarjeta');

        Http::assertSent(fn ($p) => $p->data()['amount'] === 1650.50);
    }

    /** Cada método pide un cargo distinto. */
    public function test_cada_metodo_pide_su_tipo_de_cargo(): void
    {
        foreach ([['tarjeta', 'card'], ['tienda', 'store'], ['spei', 'bank_account']] as [$nuestro, $suyo]) {
            Http::fake(['*openpay.mx/*' => Http::response([
                'id' => 'trq1', 'payment_method' => ['url' => 'https://openpay/x'],
            ])]);

            $this->pasarela(['tarjeta' => true, 'tienda' => true, 'spei' => true])
                ->iniciar($this->intencion(500), 'http://x', 'http://y', $nuestro);

            Http::assertSent(fn ($p) => ($p->data()['method'] ?? null) === $suyo);
        }
    }

    /**
     * El SPEI no devuelve página: se manda a una pantalla nuestra.
     *
     * Devuelve CLABE, banco y referencia. Antes esto reventaba con «no devolvió
     * a dónde enviar», que es lo que habría visto en producción la primera
     * persona que eligiera transferencia: un error en lugar de los datos que
     * venía a buscar.
     */
    public function test_el_spei_manda_a_nuestras_instrucciones(): void
    {
        Http::fake(['*openpay.mx/*' => Http::response([
            'id' => 'trq2',
            'payment_method' => ['type' => 'bank_transfer', 'clabe' => '646180111812345678', 'bank' => 'STP'],
        ])]);

        $intencion = $this->intencion(500);

        $iniciado = $this->pasarela(['tarjeta' => true, 'spei' => true])
            ->iniciar($intencion, 'http://x', 'http://y', 'spei');

        $this->assertStringContainsString("/pagos/instrucciones/{$intencion->id}", $iniciado->url);
    }

    /** Con tarjeta sí hay página suya, y se usa. */
    public function test_la_tarjeta_va_a_su_formulario(): void
    {
        Http::fake(['*openpay.mx/*' => Http::response([
            'id' => 'trq3', 'payment_method' => ['url' => 'https://sandbox.openpay.mx/pagar/trq3'],
        ])]);

        $iniciado = $this->pasarela()->iniciar($this->intencion(500), 'http://x', 'http://y', 'tarjeta');

        $this->assertSame('https://sandbox.openpay.mx/pagar/trq3', $iniciado->url);
    }

    /** Su vocabulario, traducido. */
    public function test_traduce_los_estados(): void
    {
        $esperado = [
            'completed' => EstadoCobro::APROBADO,
            'in_progress' => EstadoCobro::PENDIENTE,
            'charge_pending' => EstadoCobro::PENDIENTE,
            'failed' => EstadoCobro::RECHAZADO,
            'cancelled' => EstadoCobro::CANCELADO,
            'expired' => EstadoCobro::CANCELADO,
            'palabra_nueva' => EstadoCobro::DESCONOCIDO,
        ];

        $secuencia = Http::sequence();

        foreach (array_keys($esperado) as $suyo) {
            $secuencia->push(['id' => 'trq1', 'status' => $suyo, 'order_id' => 'acadion-9', 'amount' => 500]);
        }

        Http::fake(['*openpay.mx/*' => $secuencia]);

        foreach ($esperado as $suyo => $nuestro) {
            $resultado = $this->pasarela()->interpretarAviso(
                Request::create('/aviso', 'POST', ['type' => 'charge.succeeded', 'transaction' => ['id' => 'trq1']]),
            );

            $this->assertSame($nuestro, $resultado->estado, "«{$suyo}» se tradujo mal.");
        }
    }

    /** Nuestra referencia se recupera de su `order_id`. */
    public function test_reconoce_nuestra_referencia(): void
    {
        Http::fake(['*openpay.mx/*' => Http::response([
            'id' => 'trq1', 'status' => 'completed', 'order_id' => 'acadion-42', 'amount' => 500,
        ])]);

        $resultado = $this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => 'charge.succeeded', 'transaction' => ['id' => 'trq1']]),
        );

        $this->assertSame(42, $resultado->intencionId);
        $this->assertSame(500.0, $resultado->monto);
    }

    /**
     * Un cargo de otro sistema que use la misma cuenta no se atribuye a nadie.
     *
     * Por eso la referencia lleva prefijo: sin él, un `order_id` numérico ajeno
     * se tomaría por una intención nuestra.
     */
    public function test_un_cargo_ajeno_no_se_atribuye(): void
    {
        Http::fake(['*openpay.mx/*' => Http::response([
            'id' => 'trq1', 'status' => 'completed', 'order_id' => '42', 'amount' => 500,
        ])]);

        $resultado = $this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => 'charge.succeeded', 'transaction' => ['id' => 'trq1']]),
        );

        $this->assertNull($resultado->intencionId);
    }

    /** El aviso de verificación de su panel no es un cobro. */
    public function test_el_aviso_de_verificacion_se_ignora(): void
    {
        Http::fake();

        $this->assertNull($this->pasarela()->interpretarAviso(
            Request::create('/aviso', 'POST', ['type' => 'verification', 'verification_code' => 'abc']),
        ));

        Http::assertNothingSent();
    }

    /** El ambiente cambia el DOMINIO, no sólo la llave. */
    public function test_pruebas_y_produccion_son_dominios_distintos(): void
    {
        Http::fake(['*' => Http::response(['id' => 'x', 'payment_method' => ['url' => 'https://o/x']])]);

        $this->pasarela()->iniciar($this->intencion(100), 'http://x', 'http://y', 'tarjeta');
        Http::assertSent(fn ($p) => str_contains($p->url(), 'sandbox-api.openpay.mx'));

        $config = $this->config();
        $config->fill([
            'ambiente' => PasarelaPago::AMBIENTE_PRODUCCION,
            'credenciales_produccion' => ['merchant_id' => 'm1', 'private_key' => 'sk', 'public_key' => 'pk'],
        ])->save();

        (new PasarelaOpenPay($config->fresh()))->iniciar($this->intencion(100), 'http://x', 'http://y', 'tarjeta');

        Http::assertSent(fn ($p) => str_contains($p->url(), '//api.openpay.mx'));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $opciones */
    private function pasarela(array $opciones = ['tarjeta' => true]): PasarelaOpenPay
    {
        $config = $this->config();

        $config->opciones = $opciones;
        $config->save();

        return new PasarelaOpenPay($config->fresh());
    }

    private function config(): PasarelaPago
    {
        $config = PasarelaPago::para('openpay');

        $config->fill([
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'credenciales_pruebas' => ['merchant_id' => 'm1', 'private_key' => 'sk_test', 'public_key' => 'pk_test'],
        ])->save();

        return $config->fresh();
    }

    private function activar(): void
    {
        $config = $this->config();
        $config->opciones = ['tarjeta' => true, 'tienda' => true, 'spei' => true];
        $config->activa = true;
        $config->save();
    }

    private function intencion(float $monto): IntencionCobro
    {
        return IntencionCobro::create([
            'matricula_oferta_id' => $this->alumnoInscrito()['matricula'],
            'pasarela' => 'openpay',
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'monto' => $monto,
        ]);
    }

    private function adeudo(int $matricula, float $monto): int
    {
        return $this->fila('adeudos', [
            'matricula_oferta_id' => $matricula,
            'concepto_id' => $this->deCatalogo('conceptos_pago'),
            'monto' => $monto,
            'monto_total' => $monto,
            'fecha_generacion' => '2026-01-01',
            'fecha_vencimiento' => '2026-03-01',
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);
    }
}
