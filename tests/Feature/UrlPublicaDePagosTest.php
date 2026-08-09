<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\UrlPublica;
use Tests\TestCase;

/**
 * La dirección por la que una pasarela nos alcanza.
 *
 * ── Por qué existe esto ────────────────────────────────────────────────────
 * La URL del aviso no la abre quien navega: la abre un servidor de la pasarela
 * desde internet. Armarla con el host de la petición —`localhost`, una IP
 * interna, el nombre de la máquina en la red— produce una dirección que no
 * existe fuera, y el fallo es MUDO: el cobro se abre, la liga de pago funciona,
 * alguien paga, y el pago no se aplica nunca. Nadie ve un error.
 *
 * Se prueba con cuidado la conservación de la RUTA, porque es donde viaja qué
 * pasarela avisa: perderla al cambiar el host convierte el arreglo en el mismo
 * fallo mudo por otra causa.
 */
class UrlPublicaDePagosTest extends TestCase
{
    /**
     * Sin configurar, nada cambia.
     *
     * Es el caso de producción con dominio propio, y el que no debe romperse
     * por agregar esta pieza.
     */
    public function test_sin_url_publica_la_direccion_queda_igual(): void
    {
        config(['services.pagos.url_publica' => null]);

        $original = 'https://demo.acadion.mx/pagos/aviso/mercadopago';

        $this->assertSame($original, UrlPublica::paraAfuera($original));
        $this->assertNull(UrlPublica::base());
        $this->assertNull(UrlPublica::host());
    }

    /** Con el túnel configurado, cambia el origen y NO la ruta. */
    public function test_cambia_el_origen_y_conserva_la_ruta(): void
    {
        config(['services.pagos.url_publica' => 'https://abc123.ngrok-free.app']);

        $this->assertSame(
            'https://abc123.ngrok-free.app/pagos/aviso/mercadopago',
            UrlPublica::paraAfuera('http://demo.localhost:8000/pagos/aviso/mercadopago'),
        );
    }

    /** La cadena de consulta también viaja: ahí va de qué cobro se trata. */
    public function test_conserva_la_cadena_de_consulta(): void
    {
        config(['services.pagos.url_publica' => 'https://abc123.ngrok-free.app']);

        $this->assertSame(
            'https://abc123.ngrok-free.app/pagos/aviso/conekta?intencion=42',
            UrlPublica::paraAfuera('http://demo.localhost:8000/pagos/aviso/conekta?intencion=42'),
        );
    }

    /**
     * Pegar el host sin esquema funciona.
     *
     * Es lo que uno tiene a mano al copiar del panel del túnel, y sin esto la
     * configuración falla sin decir por qué. Se asume HTTPS porque ninguna
     * pasarela seria entrega un aviso en claro.
     */
    public function test_un_host_pelado_se_completa_con_https(): void
    {
        config(['services.pagos.url_publica' => 'abc123.ngrok-free.app']);

        $this->assertSame('https://abc123.ngrok-free.app', UrlPublica::base());
        $this->assertSame(
            'https://abc123.ngrok-free.app/pagos/aviso/stripe',
            UrlPublica::paraAfuera('http://demo.localhost:8000/pagos/aviso/stripe'),
        );
    }

    /** Una barra de más al final no duplica la de la ruta. */
    public function test_la_barra_final_no_se_duplica(): void
    {
        config(['services.pagos.url_publica' => 'https://abc123.ngrok-free.app/']);

        $this->assertSame(
            'https://abc123.ngrok-free.app/pagos/aviso/openpay',
            UrlPublica::paraAfuera('http://demo.localhost:8000/pagos/aviso/openpay'),
        );
    }

    /** El host suelto, que es lo que hay que registrar como dominio del tenant. */
    public function test_da_el_host_para_registrarlo_como_dominio(): void
    {
        config(['services.pagos.url_publica' => 'https://abc123.ngrok-free.app']);

        $this->assertSame('abc123.ngrok-free.app', UrlPublica::host());
    }

    /** Configurada en blanco es como no configurarla. */
    public function test_en_blanco_cuenta_como_no_configurada(): void
    {
        config(['services.pagos.url_publica' => '   ']);

        $this->assertNull(UrlPublica::base());
        $this->assertSame(
            'http://demo.localhost:8000/pagos/aviso/paypal',
            UrlPublica::paraAfuera('http://demo.localhost:8000/pagos/aviso/paypal'),
        );
    }
}
