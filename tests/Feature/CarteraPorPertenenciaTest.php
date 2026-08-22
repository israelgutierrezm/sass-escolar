<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\FinanzasController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Usuario;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Quién ve qué en la cartera.
 *
 * `ver-adeudos` lo tienen el personal de finanzas, el alumno y el padre de
 * familia: el permiso deja ENTRAR y no dice sobre quién. Eso lo decide la
 * pertenencia, y estaba escrito dos veces —una en el listado y otra en el
 * detalle—, las dos mal y en direcciones opuestas: el padre de familia veía la
 * cartera COMPLETA de la escuela, con nombres y saldos de alumnos ajenos, y al
 * abrir el estado de cuenta de su propio hijo recibía un 403.
 *
 * Por eso estas pruebas miran las dos puertas con los mismos casos: el recorte
 * de la lista no sirve de nada si el detalle no lo respeta, y al revés.
 */
class CarteraPorPertenenciaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    // ── El padre de familia ────────────────────────────────────────────────

    public function test_el_padre_solo_ve_las_matriculas_de_sus_hijos(): void
    {
        $hijo = $this->alumnoInscrito();
        $ajeno = $this->alumnoInscrito();

        $padre = $this->padreDe($hijo['persona']);

        $this->assertSame([$hijo['matricula']], $this->carteraDe($padre));
        $this->assertNotContains($ajeno['matricula'], $this->carteraDe($padre));
    }

    /**
     * Y el vínculo sin acceso financiero no cuenta.
     *
     * `puede_ver_finanzas` ya existía y ya se respetaba en su portal. Un padre
     * al que se le dio lo académico pero no lo financiero no debe llegar a los
     * saldos entrando por otra pantalla.
     */
    public function test_un_hijo_sin_acceso_financiero_no_aparece(): void
    {
        $hijo = $this->alumnoInscrito();
        $padre = $this->padreDe($hijo['persona'], finanzas: false);

        $this->assertSame([], $this->carteraDe($padre));
    }

    /** Y tampoco entra al estado de cuenta de un alumno ajeno. */
    public function test_el_padre_no_entra_a_la_cuenta_de_un_alumno_ajeno(): void
    {
        $hijo = $this->alumnoInscrito();
        $ajeno = $this->alumnoInscrito();
        $padre = $this->padreDe($hijo['persona']);

        $this->expectException(HttpException::class);

        app(FinanzasController::class)->cuenta(
            $this->peticionDe($padre, '/finanzas'),
            MatriculaOferta::findOrFail($ajeno['matricula']),
        );
    }

    /**
     * Pero SÍ entra a la de su hijo.
     *
     * Es la otra mitad del mismo error y la que se nota primero: el detalle
     * exigía que la matrícula fuera de su propia persona, y un padre nunca es
     * el titular de la matrícula de su hijo. Le cerraba justo lo que venía a
     * consultar.
     */
    public function test_el_padre_si_entra_a_la_cuenta_de_su_hijo(): void
    {
        $hijo = $this->alumnoInscrito();
        $padre = $this->padreDe($hijo['persona']);

        $peticion = $this->peticionDe($padre, '/finanzas');
        $props = $this->propsDe(
            app(FinanzasController::class)->cuenta($peticion, MatriculaOferta::findOrFail($hijo['matricula'])),
            $peticion,
        );

        $this->assertSame($hijo['matricula'], $props['matricula']['id']);
    }

    // ── El alumno ──────────────────────────────────────────────────────────

    public function test_el_alumno_solo_ve_la_suya(): void
    {
        $suyo = $this->alumnoInscrito();
        $this->alumnoInscrito();

        $usuario = $this->usuarioDe($suyo['persona'], 'alumno');

        $this->assertSame([$suyo['matricula']], $this->carteraDe($usuario));
    }

    // ── El personal ────────────────────────────────────────────────────────

    public function test_el_administrativo_ve_toda_la_cartera(): void
    {
        $uno = $this->alumnoInscrito();
        $dos = $this->alumnoInscrito();

        $cartera = $this->carteraDe($this->usuarioConAlcance());

        $this->assertContains($uno['matricula'], $cartera);
        $this->assertContains($dos['matricula'], $cartera);
    }

    /**
     * Y un ámbito que no está enumerado no ve nada.
     *
     * Es la diferencia que importa con la versión anterior: aquella fallaba
     * ABIERTA —lo que no reconocía, lo mostraba todo—, que es exactamente cómo
     * se filtró la cartera cuando las etiquetas de faceta dejaron de coincidir.
     */
    public function test_un_ambito_no_contemplado_no_ve_nada(): void
    {
        $this->alumnoInscrito();

        $this->assertSame([], $this->carteraDe($this->usuarioConAlcance(rol: 'docente')));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<int, int> los ids de matrícula que la pantalla le muestra */
    private function carteraDe(Usuario $usuario): array
    {
        $peticion = $this->peticionDe($usuario, '/finanzas');
        $props = $this->propsDe(app(FinanzasController::class)->index($peticion), $peticion);

        // Las props ya vienen serializadas: el paginador es un arreglo con
        // `data`, no el objeto.
        return collect($props['matriculas']['data'] ?? [])->pluck('id')->all();
    }

    private function padreDe(int $alumnoPersonaId, bool $finanzas = true): Usuario
    {
        $usuario = $this->usuarioConAlcance(rol: 'padre_familia');

        $this->fila('tutores_alumno', [
            'tutor_persona_id' => $usuario->persona_id,
            'alumno_persona_id' => $alumnoPersonaId,
            // Del catálogo: el parentesco dejó de ser texto libre.
            'parentesco_id' => Parentesco::query()->where('clave', 'padre')->value('id'),
            'puede_ver_academico' => true,
            'puede_ver_finanzas' => $finanzas,
        ]);

        return $usuario;
    }

    /** Un usuario para una persona que YA existe —el alumno inscrito—. */
    private function usuarioDe(int $personaId, string $rol): Usuario
    {
        $usuario = $this->usuarioConAlcance(rol: $rol);
        $usuario->update(['persona_id' => $personaId]);

        return $usuario->fresh();
    }
}
