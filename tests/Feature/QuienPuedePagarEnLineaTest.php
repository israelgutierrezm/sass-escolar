<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\CobroEnLineaController;
use App\Http\Controllers\FinanzasController;
use App\Http\Controllers\PadreController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\PasarelaPago;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Quién puede pagar en línea, y con qué.
 *
 * El cobro en línea existe sobre todo para que el alumno y su familia paguen
 * SIN pasar por ventanilla: si sólo lo pudiera usar el personal de la escuela,
 * sería una forma más lenta de hacer lo que ya se hacía.
 *
 * La regla es la misma que decide quién ve un estado de cuenta —el alumno el
 * suyo, el padre el de sus hijos con permiso financiero—, y aquí se comprueba
 * que de verdad alcanza al pago, que es una acción más delicada que mirar.
 */
class QuienPuedePagarEnLineaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pagos.modo', 'fake');

        $this->activar('mercadopago');
    }

    /** El alumno paga su propia cuenta. */
    public function test_el_alumno_puede_pagar_lo_suyo(): void
    {
        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 1200);
        $usuario = $this->usuarioDe($escuela['persona'], 'alumno');

        $respuesta = $this->iniciar($usuario, $escuela['matricula'], [$adeudo]);

        $this->assertNotEmpty($respuesta['url'], 'Debe devolver a dónde ir a pagar.');
    }

    /** Y ve las pasarelas en su estado de cuenta, que es de donde sale el botón. */
    public function test_el_alumno_ve_con_que_pagar(): void
    {
        $escuela = $this->alumnoInscrito();
        $this->adeudo($escuela['matricula'], 1200);
        $usuario = $this->usuarioDe($escuela['persona'], 'alumno');

        $this->assertSame(
            ['mercadopago'],
            collect($this->pasarelasQueVe($usuario, $escuela['matricula']))->pluck('clave')->all(),
        );
    }

    /**
     * Con dos pasarelas encendidas, se puede ELEGIR.
     *
     * No se decide por él: cada una cobra distinto —una da meses sin intereses,
     * otra acepta OXXO— y esa elección es de quien paga.
     */
    public function test_con_dos_pasarelas_el_alumno_elige(): void
    {
        $this->activar('conekta', ['private_key' => 'key_test', 'public_key' => 'pub_test']);

        $escuela = $this->alumnoInscrito();
        $this->adeudo($escuela['matricula'], 1200);
        $usuario = $this->usuarioDe($escuela['persona'], 'alumno');

        $vistas = collect($this->pasarelasQueVe($usuario, $escuela['matricula']))->pluck('clave')->all();

        sort($vistas);
        $this->assertSame(['conekta', 'mercadopago'], $vistas);
    }

    /** El padre paga la cuenta de su hijo. */
    public function test_el_padre_puede_pagar_la_cuenta_de_su_hijo(): void
    {
        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 900);
        $padre = $this->padreDe($escuela['persona'], finanzas: true);

        $respuesta = $this->iniciar($padre, $escuela['matricula'], [$adeudo]);

        $this->assertNotEmpty($respuesta['url']);
    }

    /**
     * Y puede hacerlo DESDE SU PORTAL, sin ir a buscar la cuenta al menú de
     * Finanzas —que además está escrito para quien cobra, no para quien paga—.
     *
     * Se comprueban las dos cosas que el panel necesita: con qué pagar y el id
     * de la matrícula. Sin el id el botón existe y no puede decir qué cuenta se
     * está pagando.
     */
    public function test_el_padre_paga_desde_su_portal(): void
    {
        $escuela = $this->alumnoInscrito();
        $this->adeudo($escuela['matricula'], 900);
        $padre = $this->padreDe($escuela['persona'], finanzas: true);

        $props = $this->portalDelPadre($padre, $escuela['persona']);

        $this->assertSame(['mercadopago'], collect($props['pasarelas'])->pluck('clave')->all());
        $this->assertSame(
            $escuela['matricula'],
            $props['finanzas'][0]['matricula_id'],
            'Sin el id no se puede decir qué cuenta se paga.',
        );
    }

    /**
     * Al padre sin permiso financiero no se le ofrecen pasarelas.
     *
     * No basta con esconder los saldos: un botón de pagar es una forma de
     * preguntar cuánto se debe.
     */
    public function test_sin_permiso_financiero_no_hay_con_que_pagar_en_el_portal(): void
    {
        $escuela = $this->alumnoInscrito();
        $this->adeudo($escuela['matricula'], 900);
        $padre = $this->padreDe($escuela['persona'], finanzas: false);

        $props = $this->portalDelPadre($padre, $escuela['persona']);

        $this->assertSame([], $props['pasarelas']);
        $this->assertNull($props['finanzas']);
    }

    /**
     * Pero sólo si su vínculo trae permiso financiero.
     *
     * A un padre se le puede dar acceso académico sin darle el de dinero. Ese
     * flag ya decidía qué ve; tiene que decidir también qué puede pagar, o el
     * pago sería la puerta de atrás para ver saldos.
     */
    public function test_un_padre_sin_permiso_financiero_no_paga(): void
    {
        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 900);
        $padre = $this->padreDe($escuela['persona'], finanzas: false);

        $this->expectException(AvisoParaElUsuario::class);

        $this->iniciar($padre, $escuela['matricula'], [$adeudo]);
    }

    /**
     * Y nadie paga la cuenta de un alumno ajeno.
     *
     * Suena a favor, pero no lo es: quien puede pagar una cuenta puede verla, y
     * ahí se cuelan los saldos de toda la escuela.
     */
    public function test_un_alumno_no_toca_la_cuenta_de_otro(): void
    {
        $mio = $this->alumnoInscrito();
        $ajeno = $this->alumnoInscrito();
        $adeudo = $this->adeudo($ajeno['matricula'], 700);

        $usuario = $this->usuarioDe($mio['persona'], 'alumno');

        $this->expectException(AvisoParaElUsuario::class);

        $this->iniciar($usuario, $ajeno['matricula'], [$adeudo]);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, int>  $adeudos */
    private function iniciar(Usuario $usuario, int $matricula, array $adeudos): array
    {
        $peticion = Request::create("/pagos/iniciar/{$matricula}", 'POST', [
            'pasarela' => 'mercadopago',
            'adeudo_ids' => $adeudos,
        ]);

        $peticion->setUserResolver(fn () => $usuario);

        $respuesta = app(CobroEnLineaController::class)
            ->iniciar($peticion, MatriculaOferta::findOrFail($matricula));

        return json_decode($respuesta->getContent(), true);
    }

    /** @return array<int, array<string, mixed>> */
    private function pasarelasQueVe(Usuario $usuario, int $matricula): array
    {
        $peticion = $this->peticionDe($usuario, "/finanzas/cuentas/{$matricula}");

        $props = $this->propsDe(
            app(FinanzasController::class)->cuenta($peticion, MatriculaOferta::findOrFail($matricula)),
            $peticion,
        );

        return $props['pasarelas'] ?? [];
    }

    /** @return array<string, mixed> */
    private function portalDelPadre(Usuario $padre, int $hijoPersonaId): array
    {
        $peticion = $this->peticionDe($padre, "/mis-hijos/{$hijoPersonaId}");

        return $this->propsDe(
            app(PadreController::class)->hijo($peticion, Persona::findOrFail($hijoPersonaId)),
            $peticion,
        );
    }

    /** Un usuario cuya persona es la que se indica (no un «staff» cualquiera). */
    private function usuarioDe(int $personaId, string $rol): Usuario
    {
        $usuario = $this->usuarioConAlcance([], $rol);

        // El alcance PROPIO se resuelve por `persona_id`: sin esto el usuario
        // sería de otra persona y no vería su propia cuenta.
        $usuario->update(['persona_id' => $personaId]);

        return $usuario->fresh();
    }

    private function padreDe(int $hijoPersonaId, bool $finanzas): Usuario
    {
        $padre = $this->usuarioConAlcance([], 'padre_familia');

        $this->fila('tutores_alumno', [
            'tutor_persona_id' => $padre->persona_id,
            'alumno_persona_id' => $hijoPersonaId,
            'puede_ver_academico' => true,
            'puede_ver_finanzas' => $finanzas,
        ]);

        return $padre->fresh();
    }

    /** @param  array<string, string>  $credenciales */
    private function activar(string $clave, array $credenciales = []): void
    {
        PasarelaPago::para($clave)->fill([
            'activa' => true,
            'ambiente' => PasarelaPago::AMBIENTE_PRUEBAS,
            'credenciales_pruebas' => $credenciales ?: ['access_token' => 'TEST-1', 'public_key' => 'PUB-1'],
        ])->save();
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
