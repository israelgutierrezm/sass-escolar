<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\RecursosDigitalesController;
use App\Models\ControlEscolar\RecursoDigital;
use App\Models\Identidad\Usuario;
use App\Panel\RegistroTarjetas;
use App\Panel\Tarjetas\RecursosDigitales;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * La recursos digitales.
 *
 * ── El caso que justifica la prueba de la dirección ────────────────────────
 * Lo que se captura aquí lo publica la escuela a TODOS sus alumnos y sale como
 * un enlace en el que se hace clic, así que el esquema importa.
 *
 * Medido en esta versión de Laravel: la regla `url` a secas ya rechaza
 * `javascript:`, `data:`, `file:`, `mailto:` y `vbscript:` —exige la forma
 * `esquema://servidor`—. Lo que NO rechaza es `ftp://` ni `ws://`, y ésos son
 * los que fija la lista de esquemas. Por eso la prueba los incluye: sin ellos
 * pasaría igual con la regla sin acotar y no estaría comprobando nada.
 */
class RecursosDigitalesTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_una_direccion_que_no_es_http_se_rechaza(): void
    {
        $fuera = [
            // Los que ejecutarían algo en el navegador de quien haga clic.
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'file:///etc/passwd',
            // Y los que la regla `url` sin acotar SÍ dejaría pasar. Son los que
            // hacen que esta prueba distinga una cosa de la otra.
            'ftp://archivos.example.mx/a',
            'ws://example.mx/socket',
        ];

        foreach ($fuera as $peligrosa) {
            try {
                $this->publicar(['url' => $peligrosa]);
                $this->fail("Se aceptó una dirección que no debía: {$peligrosa}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('url', $e->errors());
            }
        }
    }

    public function test_una_direccion_normal_se_acepta(): void
    {
        $this->publicar(['url' => 'https://recursos-digitales.example.mx/revistas']);

        $this->assertSame(1, RecursoDigital::query()->count());
    }

    /**
     * Con portada se pinta como tarjeta; sin portada, como enlace suelto.
     *
     * El reparto lo hace el servidor porque son dos bloques distintos en
     * pantalla. Si se mezclaran, un recurso sin imagen saldría como tarjeta con
     * un hueco gris, que se lee como una portada que no cargó.
     */
    public function test_los_que_traen_portada_van_aparte_de_los_directos(): void
    {
        $this->publicar(['titulo' => 'Con portada', 'imagen_url' => 'https://cdn.example.mx/a.png']);
        $this->publicar(['titulo' => 'Sin portada', 'imagen_url' => null]);

        $props = app(RecursosDigitalesController::class)->index()->toResponse($this->peticionDe($this->usuarioConAlcance()))->getData(true)['props'];

        $this->assertSame(['Con portada'], array_column($props['tarjetas'], 'titulo'));
        $this->assertSame(['Sin portada'], array_column($props['directos'], 'titulo'));
    }

    /** Lo despublicado no se le enseña al alumno, pero sigue guardado. */
    public function test_un_recurso_sin_publicar_no_sale(): void
    {
        $this->publicar(['titulo' => 'Retirado', 'activo' => false]);

        $this->assertSame(0, RecursoDigital::query()->publicados()->count());
        $this->assertSame(1, RecursoDigital::query()->count());
    }

    /**
     * La tarjeta del panel es la ÚNICA puerta: la sección no está en el menú.
     *
     * Así que tiene que desaparecer cuando la escuela cierra la sección —si no,
     * el alumno vería una invitación a un 404— y cuando no hay nada publicado,
     * que sería una invitación a una pantalla vacía.
     */
    /**
     * Con la sección apagada, la tarjeta no llega al panel.
     *
     * ── Se comprueba por `para()` y no por `datos()`, y es el cambio ──────
     * La tarjeta miraba su módulo dentro de `datos()`. Dejó de hacerlo cuando la
     * comprobación se subió a `RegistroTarjetas::para()`: con ella repartida, la
     * tarjeta que se olvide no falla —se pinta—, y eso fue exactamente lo que le
     * pasó a «Postulantes en proceso».
     *
     * Así que esta prueba pasa a mirar lo que de verdad protege a quien entra:
     * que la tarjeta no salga del registro. Preguntarle a `datos()` seguiría
     * comprobando algo cierto y ya no sería la defensa.
     */
    public function test_la_tarjeta_desaparece_con_la_seccion_apagada(): void
    {
        $this->publicar([]);

        /*
         * Con el permiso puesto, porque `para()` filtra por él y la prueba
         * anterior no pasaba por ahí. Sin esto la tarjeta no sale ni con el
         * módulo encendido, y la comprobación se cumpliría por la razón
         * equivocada: «no aparece» sería cierto siempre.
         */
        $usuario = $this->usuarioConAlcance();
        $usuario->rolActivo->givePermissionTo(
            Permission::findOrCreate('ver-recursos-digitales', 'web'),
        );

        $this->assertContains('recursos_digitales', $this->clavesDelPanel($usuario));

        $this->modulos()->cambiar('recursos_digitales', false);

        $this->assertNotContains('recursos_digitales', $this->clavesDelPanel($usuario));

        // Y al reencenderla vuelve: apagar no es borrar.
        $this->modulos()->cambiar('recursos_digitales', true);

        $this->assertContains('recursos_digitales', $this->clavesDelPanel($usuario));
    }

    public function test_la_tarjeta_no_invita_a_una_biblioteca_vacia(): void
    {
        $this->assertNull($this->tarjeta()->datos($this->usuarioConAlcance()));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $cambios */
    private function publicar(array $cambios): void
    {
        $peticion = Request::create('/escolar/recursos-digitales', 'POST', array_merge([
            'titulo' => 'Recurso de prueba',
            'descripcion' => null,
            'url' => 'https://example.mx',
            'imagen_url' => null,
            'activo' => true,
        ], $cambios));

        app(RecursosDigitalesController::class)->store($peticion);
    }

    private function tarjeta(): RecursosDigitales
    {
        return new RecursosDigitales;
    }

    /**
     * Las tarjetas que el panel entrega, por su clave.
     *
     * Con un registro NUEVO en cada llamada: el del contenedor es un singleton
     * y guarda su propio `ModulosDeLaEscuela`, que recuerda el mapa durante la
     * petición — o sea que apagar el módulo no se vería.
     *
     * @return array<int, string>
     */
    private function clavesDelPanel(Usuario $usuario): array
    {
        $registro = new RegistroTarjetas($this->modulos());
        $registro->registrar(RecursosDigitales::class);

        return array_column($registro->para($usuario->fresh(['rolActivo'])), 'clave');
    }

    /** Instancia nueva: el servicio recuerda el mapa durante la petición. */
    private function modulos(): ModulosDeLaEscuela
    {
        app()->forgetInstance(ModulosDeLaEscuela::class);

        return app(ModulosDeLaEscuela::class);
    }
}
