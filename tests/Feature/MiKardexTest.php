<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\MiKardexController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use App\Services\KardexDelAlumno;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El kárdex que ve el alumno.
 *
 * ── Lo que estas pruebas cuidan ────────────────────────────────────────────
 * Es el MISMO historial académico que consulta control escolar por otra puerta.
 * Si el promedio que ve el alumno no fuera el de la ventanilla, alguien
 * reclamaría con razón y nadie sabría cuál de los dos está mal —y no lo avisaría
 * nada: las dos pantallas se ven perfectamente bien—. Por eso las dos salen del
 * mismo servicio y aquí se comprueba que coinciden.
 *
 * El caso concreto que ya mordió: la pantalla del alumno cargaba el plan con
 * `plan:id,nombre`. Las columnas que NO se piden llegan en null, así que
 * `total_creditos` desaparecía y los créditos salían «148» sin el «de 336», con
 * el promedio redondeado por la regla por omisión en vez de la del plan. No
 * falla, no avisa: sólo dice otro número.
 */
class MiKardexTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_el_alumno_ve_el_mismo_kardex_que_control_escolar(): void
    {
        [$usuario, $matricula] = $this->alumnoConMatricula();

        $props = $this->abrir($usuario);
        $servicio = app(KardexDelAlumno::class);

        /*
         * Se compara el resumen del servicio DESPUÉS del mismo viaje por JSON
         * que hacen las props. Sin eso, un 0.0 del servicio contra el 0 que
         * llega a la pantalla hace fallar la prueba por un cambio de tipo que no
         * le importa a nadie —y obligaría a aflojar la comparación, con lo que
         * dejaría de detectar una diferencia de verdad.
         */
        $delExpediente = json_decode(json_encode($servicio->resumen($matricula)), true);

        $this->assertSame(
            $delExpediente,
            $props['resumen'],
            'El resumen del portal tiene que ser el mismo que el del expediente.',
        );
        $this->assertCount(count($servicio->renglones($matricula)), $props['renglones']);
    }

    /**
     * Los denominadores del plan llegan.
     *
     * Es lo que se pierde con una carga parcial de columnas, y se pierde en
     * silencio: la pantalla se dibuja igual, sólo que sin el «de 336».
     */
    public function test_el_resumen_trae_los_totales_del_plan(): void
    {
        [$usuario, $matricula] = $this->alumnoConMatricula();

        /*
         * Se le ponen créditos al plan de la escuela de prueba, que no los trae.
         * Sin esto la aserción compara null contra null y pasa siempre —incluso
         * con la carga parcial puesta—, o sea que no comprueba nada. Se descubrió
         * mutando el controlador: la prueba seguía en verde.
         */
        $matricula->oferta->plan->update(['total_creditos' => 336]);

        $props = $this->abrir($usuario);

        $this->assertSame(336, (int) $props['resumen']['creditos_del_plan']);
    }

    /**
     * Pedir la matrícula de otro no la muestra.
     *
     * La elección se busca entre las SUYAS; un id ajeno no encuentra pareja y
     * cae a la primera propia. No hay 403 porque no hay nada que negar: el
     * parámetro simplemente no puede nombrar algo de otra persona.
     */
    public function test_pedir_una_matricula_ajena_devuelve_la_propia(): void
    {
        [$usuario, $matricula] = $this->alumnoConMatricula();
        [, $ajena] = $this->alumnoConMatricula();

        $props = $this->abrir($usuario, ['matricula' => $ajena->id]);

        $this->assertSame($matricula->id, $props['matricula']['id']);
    }

    /** Quien tiene el permiso pero no es alumno no ve una tabla vacía sin explicación. */
    public function test_sin_matricula_lo_dice_en_vez_de_pintar_un_kardex_vacio(): void
    {
        $props = $this->abrir($this->usuarioConAlcance());

        $this->assertNull($props['matricula']);
        $this->assertSame([], $props['renglones']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function abrir(Usuario $usuario, array $parametros = []): array
    {
        $peticion = $this->peticionDe($usuario, '/mi-kardex', $parametros);

        return app(MiKardexController::class)($peticion)
            ->toResponse($peticion)
            ->getData(true)['props'];
    }

    /** @return array{0: Usuario, 1: MatriculaOferta} */
    private function alumnoConMatricula(): array
    {
        $escuela = $this->alumnoInscrito();
        $matricula = MatriculaOferta::findOrFail($escuela['matricula']);

        $usuario = $this->usuarioConAlcance();
        $usuario->persona_id = $matricula->persona_id;
        $usuario->save();

        return [$usuario->fresh(), $matricula];
    }
}
