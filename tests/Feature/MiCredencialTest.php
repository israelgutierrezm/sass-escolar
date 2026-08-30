<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\MiCredencialController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Credencial;
use App\Models\Identidad\CredencialRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * La credencial de quien está en sesión.
 *
 * ── Qué se está protegiendo ────────────────────────────────────────────────
 * Que nadie vea la credencial de otro. La ruta no lleva id de persona, así que
 * lo único que un curioso puede escribir es la CLAVE de credencial — y esa se
 * busca dentro de la lista que ya se calculó para él. Aquí se comprueba que ese
 * respaldo no sea teoría: se pide la clave de la credencial de OTRA persona y
 * lo que vuelve es la propia.
 *
 * ── Por qué se llama al controlador y no por HTTP ──────────────────────────
 * Porque en phpunit no existe el dominio de la escuela: `routes/tenant.php` se
 * resuelve por dominio y `PreventAccessFromCentralDomains` rechaza `localhost`,
 * así que una petición HTTP a `/mi-credencial` devuelve 404 sin haber llegado
 * nunca al controlador — un 404 que no prueba nada y que se confunde con el
 * 404 legítimo de «este rol no emite». Es el mismo camino que sigue
 * `MiHistorialTest`.
 */
class MiCredencialTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** Sin configuración del rol no hay pantalla: 404, no una credencial en blanco. */
    public function test_un_rol_que_no_emite_no_tiene_pantalla(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->pantalla($this->alumno());
    }

    /** Configurada pero apagada tampoco: apagarla es lo que la retira. */
    public function test_una_credencial_apagada_no_se_puede_ver(): void
    {
        $usuario = $this->alumno();
        $this->configurar($usuario, ['activa' => false]);

        $this->expectException(NotFoundHttpException::class);

        $this->imagen($usuario, 'anverso');
    }

    public function test_encendida_se_ve_y_se_descarga(): void
    {
        $usuario = $this->alumno();
        $this->configurar($usuario);

        $props = $this->pantalla($usuario);

        $this->assertCount(1, $props['credenciales']);
        $this->assertTrue($props['tiene_reverso']);

        $respuesta = $this->imagen($usuario, 'anverso');

        $this->assertSame('image/png', $respuesta->headers->get('content-type'));
        $this->assertStringStartsWith("\x89PNG", $respuesta->getContent());

        // Sin caché: la credencial se compone con los datos de HOY, así que una
        // copia guardada seguiría enseñando el apellido que ya se corrigió.
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('cache-control'));

        $this->assertStringContainsString(
            'attachment',
            (string) $this->imagen($usuario, 'anverso', ['descargar' => 1])->headers->get('content-disposition'),
        );
    }

    /**
     * Pedir la clave de otra persona devuelve la PROPIA.
     *
     * No es un detalle: es la única entrada por la que se podría intentar, y el
     * modo en que está escrita —buscar dentro de las suyas— hace que fallar
     * signifique caer en la propia, no en la de nadie más.
     */
    public function test_la_clave_de_otro_cae_en_la_propia(): void
    {
        $usuario = $this->alumno();
        $this->configurar($usuario);

        // Otra persona inscrita, que es de quien se va a intentar la credencial.
        // La escuela de prueba nace con una sola matrícula, así que hay que
        // crear la segunda: sin ella la aserción compararía contra la nada.
        $ajena = MatriculaOferta::findOrFail($this->alumnoInscrito()['matricula']);

        $this->assertNotSame($usuario->persona_id, $ajena->persona_id);

        $propia = $this->imagen($usuario, 'anverso')->getContent();
        $intento = $this->imagen($usuario, 'anverso', ['credencial' => 'matricula-'.$ajena->id])->getContent();

        $this->assertSame(
            md5($propia),
            md5($intento),
            'Pedir la credencial de otra matrícula devolvió algo distinto de la propia.',
        );
    }

    /** Una cara inventada no existe. */
    public function test_una_cara_inventada_da_404(): void
    {
        $usuario = $this->alumno();
        $this->configurar($usuario);

        $this->expectException(NotFoundHttpException::class);

        $this->imagen($usuario, 'lateral');
    }

    /** Un reverso sin campos no se genera: sería una tarjeta en blanco. */
    public function test_un_reverso_vacio_no_se_genera(): void
    {
        $usuario = $this->alumno();
        $this->configurar($usuario, ['campos_reverso' => []]);

        $this->expectException(NotFoundHttpException::class);

        $this->imagen($usuario, 'reverso');
    }

    /**
     * La emisión se registra sola, y una sola vez.
     *
     * Es lo que le da al QR una dirección estable: si cada visita creara otra
     * fila, la credencial impresa ayer apuntaría a un uuid que hoy ya no es el
     * suyo.
     */
    public function test_mirar_la_credencial_emite_una_sola_vez(): void
    {
        $usuario = $this->alumno();
        $this->configurar($usuario, ['qr_activo' => true]);

        $this->imagen($usuario, 'reverso');
        $uuid = $this->emisionesDe($usuario)->value('uuid');

        $this->imagen($usuario, 'reverso');

        $this->assertSame(1, $this->emisionesDe($usuario)->count());
        $this->assertSame($uuid, $this->emisionesDe($usuario)->value('uuid'));
    }

    /** Sin QR encendido no se emite nada: no hay a dónde apuntar. */
    public function test_sin_qr_no_se_registra_emision(): void
    {
        $usuario = $this->alumno();
        $this->configurar($usuario, ['qr_activo' => false]);

        $this->imagen($usuario, 'anverso');

        $this->assertSame(0, $this->emisionesDe($usuario)->count());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function pantalla(Usuario $usuario): array
    {
        $peticion = $this->peticionDe($usuario, '/mi-credencial');

        return app(MiCredencialController::class)->index($peticion)
            ->toResponse($peticion)
            ->getData(true)['props'];
    }

    /** @param array<string, mixed> $parametros */
    private function imagen(Usuario $usuario, string $cara, array $parametros = [])
    {
        $peticion = $this->peticionDe($usuario, "/mi-credencial/{$cara}.png", $parametros);

        // El controlador lee `$peticion->user()`, y el resolutor lo pone; pero
        // `auth()` global sigue vacío, así que se fija también.
        $this->actingAs($usuario);

        return app(MiCredencialController::class)->imagen($peticion, $cara);
    }

    private function emisionesDe(Usuario $usuario)
    {
        return Credencial::query()->where('persona_id', $usuario->persona_id);
    }

    private function alumno(): Usuario
    {
        $matricula = MatriculaOferta::findOrFail($this->alumnoInscrito()['matricula']);

        $usuario = $this->usuarioConAlcance(rol: 'alumno');
        $usuario->persona_id = $matricula->persona_id;
        $usuario->rol_activo_id = Rol::query()->where('name', 'alumno')->value('id');
        $usuario->save();

        return $usuario->fresh();
    }

    /** @param array<string, mixed> $cambios */
    private function configurar(Usuario $usuario, array $cambios = []): CredencialRol
    {
        return CredencialRol::query()->create(array_merge([
            'rol_id' => $usuario->rol_activo_id,
            'nivel_estudios_id' => null,
            'activa' => true,
            'diseno' => 'clasico',
            /*
             * La MATRÍCULA y la CARRERA van aquí a propósito.
             *
             * El nombre no sirve para esta prueba: sale de la persona de la
             * sesión, así que se dibuja igual pasara lo que pasara con la
             * matrícula. Lo que de verdad se filtraría al colar la clave de
             * otro es su número y su programa, y son los únicos campos que
             * hacen que la imagen cambie. Comprobado mutando la salvaguarda:
             * con sólo el nombre, la prueba pasaba igual.
             */
            'campos_anverso' => [
                ['clave' => 'nombre', 'x' => 10, 'y' => 30, 'ancho' => 80, 'alto' => 10, 'tamano' => 24],
                ['clave' => 'matricula', 'x' => 10, 'y' => 50, 'ancho' => 80, 'alto' => 10, 'tamano' => 24],
                ['clave' => 'programa_academico', 'x' => 10, 'y' => 70, 'ancho' => 80, 'alto' => 10, 'tamano' => 20],
            ],
            'campos_reverso' => [
                ['clave' => 'qr', 'x' => 30, 'y' => 20, 'ancho' => 40, 'alto' => 30],
            ],
        ], $cambios));
    }
}
