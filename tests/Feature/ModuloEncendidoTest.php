<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ModuloEncendido;
use App\Models\Plataforma\Modulo;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TenantTestCase;

/**
 * Apagar una sección cierra la puerta, no esconde el letrero.
 *
 * ── Por qué esto se prueba y no se mira ────────────────────────────────────
 * El fallo que se busca evitar es invisible en pantalla: si el interruptor sólo
 * ocultara el botón del menú, la escuela vería exactamente lo que espera —la
 * sección desaparecida— mientras cualquiera con la dirección guardada sigue
 * entrando. Se ve bien y está abierto. Sólo se detecta pidiendo la ruta.
 *
 * Y el otro extremo importa igual: que un módulo sin fila en `modulos_activos`
 * cuente como APAGADO. Esa tabla se llena cuando alguien enciende algo, así que
 * suponer lo contrario haría que cada módulo nuevo se encendiera solo en todas
 * las escuelas el día que se despliega.
 */
class ModuloEncendidoTest extends TenantTestCase
{
    public function test_una_seccion_apagada_no_deja_pasar(): void
    {
        $this->modulos()->cambiar('recursos_digitales', false);

        $this->expectException(NotFoundHttpException::class);

        $this->pasarPor('recursos_digitales');
    }

    public function test_una_seccion_encendida_deja_pasar(): void
    {
        $this->modulos()->cambiar('recursos_digitales', true);

        $this->assertSame('siguió', $this->pasarPor('recursos_digitales')->getContent());
    }

    /** Sin fila en `modulos_activos` no hay paso: se falla cerrado. */
    public function test_un_modulo_que_nadie_encendio_esta_apagado(): void
    {
        Modulo::query()->create(['clave' => 'inventado', 'nombre' => 'Módulo recién agregado']);

        $this->expectException(NotFoundHttpException::class);

        $this->pasarPor('inventado');
    }

    /** Una clave que no existe tampoco abre nada. */
    public function test_una_clave_desconocida_no_deja_pasar(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->pasarPor('esto-no-existe');
    }

    /**
     * Los dos módulos nuevos llegan ENCENDIDOS.
     *
     * Su migración los prende a propósito: la regla general es fallar cerrado,
     * pero una sección que la escuela pidió no puede llegar invisible y esperar
     * a que alguien adivine que hay que ir a prenderla.
     */
    public function test_biblioteca_y_servicios_nacen_encendidos(): void
    {
        $this->assertTrue($this->modulos()->activo('recursos_digitales'));
        $this->assertTrue($this->modulos()->activo('servicios'));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** Corre el middleware; la respuesta dice «siguió» si dejó pasar. */
    private function pasarPor(string $clave): Response
    {
        return app(ModuloEncendido::class)->handle(
            Request::create('/lo-que-sea'),
            fn () => new Response('siguió'),
            $clave,
        );
    }

    /**
     * Instancia nueva en cada llamada.
     *
     * El servicio recuerda el mapa durante la petición —para eso es singleton—,
     * y aquí se enciende y se apaga dentro del mismo proceso: reutilizar la
     * instancia haría que la prueba leyera el mapa de antes del cambio.
     */
    private function modulos(): ModulosDeLaEscuela
    {
        app()->forgetInstance(ModulosDeLaEscuela::class);

        return app(ModulosDeLaEscuela::class);
    }
}
