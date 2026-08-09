<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Emision\ClienteTitulosSep;
use Tests\TenantTestCase;

/**
 * El cliente del web service se tiene que poder pedir por firma.
 *
 * Tres controladores lo reciben como parámetro —enviar el lote, reenviar un
 * título, probar la conexión— y su constructor pide modo y WSDL, que el
 * contenedor no sabe inventar. Sin el enlace, las tres rutas devuelven 500 en
 * cuanto alguien las pulsa, y no hay prueba de unidad que lo note: el servicio
 * se construye a mano en todas ellas.
 */
class ClienteTitulosSepResolubleTest extends TenantTestCase
{
    public function test_el_contenedor_sabe_armar_el_cliente(): void
    {
        $cliente = app(ClienteTitulosSep::class);

        $this->assertInstanceOf(ClienteTitulosSep::class, $cliente);
    }

    /** Y lo arma con lo que dice la configuración, no con valores sueltos. */
    public function test_el_cliente_respeta_el_modo_configurado(): void
    {
        config(['services.titulos_sep.modo' => 'off']);
        $this->assertFalse(app(ClienteTitulosSep::class)->habilitado());

        config(['services.titulos_sep.modo' => 'fake']);
        $this->assertTrue(app(ClienteTitulosSep::class)->habilitado());
    }
}
