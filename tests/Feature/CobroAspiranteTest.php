<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CobroAspiranteController;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Persona;
use App\Services\ReligadorFinanzas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El dinero del aspirante: su ficha, su examen.
 *
 * La base admitía desde el principio un adeudo cuyo titular fuera un aspirante,
 * el portal ya se los mostraba y la conversión los pasaba a la matrícula. Lo
 * único que faltaba —y sin lo cual nada de eso servía— era poder CREAR el
 * cargo: hasta ahora sólo se podía insertando la fila a mano en MySQL.
 */
class CobroAspiranteTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_se_le_genera_un_cargo_y_le_queda_saldo(): void
    {
        $aspirante = $this->aspirante();

        $this->generarCargo($aspirante, 1500);

        $cuenta = CobroAspiranteController::estadoDeCuenta($aspirante);

        $this->assertSame(1500.0, $cuenta['saldo']);
        $this->assertCount(1, $cuenta['cargos']);
    }

    public function test_un_pago_en_ventanilla_liquida_el_cargo(): void
    {
        $aspirante = $this->aspirante();
        $cargo = $this->generarCargo($aspirante, 1500);

        $this->registrarPago($aspirante, 1500, 'efectivo');

        $this->assertSame(Adeudo::ESTATUS_PAGADO, $cargo->fresh()->estatus);
        $this->assertSame(0.0, CobroAspiranteController::estadoDeCuenta($aspirante)['saldo']);
    }

    /** Un pago que no alcanza deja el cargo abierto por la diferencia. */
    public function test_un_pago_parcial_deja_el_resto_abierto(): void
    {
        $aspirante = $this->aspirante();
        $cargo = $this->generarCargo($aspirante, 1500);

        $this->registrarPago($aspirante, 500, 'efectivo');

        $this->assertSame(Adeudo::ESTATUS_PARCIAL, $cargo->fresh()->estatus);
        $this->assertSame(1000.0, CobroAspiranteController::estadoDeCuenta($aspirante)['saldo']);
    }

    /**
     * Un método que exige confirmación NO liquida al capturarse.
     *
     * Es la regla de caja del sistema y tenía que valer igual aquí: dar por
     * pagada una ficha con una transferencia que nunca llegó es exactamente el
     * error que `metodos_pago.requiere_confirmacion` existe para impedir.
     */
    public function test_una_transferencia_no_liquida_hasta_confirmarse(): void
    {
        $aspirante = $this->aspirante();
        $cargo = $this->generarCargo($aspirante, 1500);

        $this->registrarPago($aspirante, 1500, 'transferencia', requiereConfirmacion: true);

        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, $cargo->fresh()->estatus);
        $this->assertSame(1500.0, CobroAspiranteController::estadoDeCuenta($aspirante)['saldo']);
    }

    /**
     * El dinero del aspirante NO se mezcla con el de nadie más.
     *
     * `adeudos` guarda a los dos titulares en la misma tabla; una consulta mal
     * filtrada le cobraría a un aspirante lo de otro.
     */
    public function test_el_pago_de_un_aspirante_no_toca_los_cargos_de_otro(): void
    {
        $uno = $this->aspirante();
        $otro = $this->aspirante();

        $cargoDeUno = $this->generarCargo($uno, 1000);
        $cargoDelOtro = $this->generarCargo($otro, 1000);

        $this->registrarPago($uno, 1000, 'efectivo');

        $this->assertSame(Adeudo::ESTATUS_PAGADO, $cargoDeUno->fresh()->estatus);
        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, $cargoDelOtro->fresh()->estatus);
    }

    /**
     * Y al convertirlo, todo pasa a la matrícula.
     *
     * Es la razón de que el aspirante pueda ser titular: si el dinero se
     * quedara colgando de él, el estado de cuenta del alumno nacería partido en
     * dos y el pago de inscripción —el que siempre se reclama después— quedaría
     * del lado invisible.
     */
    public function test_al_convertirlo_sus_cargos_y_pagos_pasan_a_la_matricula(): void
    {
        $escuela = $this->alumnoInscrito();
        $aspirante = $this->aspirante();

        $this->generarCargo($aspirante, 1500);
        $this->registrarPago($aspirante, 1500, 'efectivo');

        $matricula = MatriculaOferta::findOrFail($escuela['matricula']);
        app(ReligadorFinanzas::class)->religar($aspirante, $matricula);

        $this->assertSame(0, Adeudo::deAspirante($aspirante->id)->count(), 'Ya no cuelgan del aspirante.');
        $this->assertSame(1, Adeudo::deMatricula($matricula->id)->count());
        $this->assertSame(1, Pago::where('matricula_oferta_id', $matricula->id)->count());
    }

    /** Un cargo con dinero encima no se cancela: primero se reversa el pago. */
    public function test_no_se_cancela_un_cargo_que_ya_tiene_pagos(): void
    {
        $aspirante = $this->aspirante();
        $cargo = $this->generarCargo($aspirante, 1500);

        $this->registrarPago($aspirante, 500, 'efectivo');

        $respuesta = app(CobroAspiranteController::class)->cancelarCargo(
            $this->peticionDe($this->usuarioConAlcance(), '/aspirantes'),
            $cargo,
        );

        $this->assertSame(Adeudo::ESTATUS_PARCIAL, $cargo->fresh()->estatus, 'Sigue vivo.');
        $this->assertStringContainsString('Reversa el pago', (string) $respuesta->getSession()?->get('error'));
    }

    public function test_un_cargo_sin_pagos_se_cancela(): void
    {
        $aspirante = $this->aspirante();
        $cargo = $this->generarCargo($aspirante, 1500);

        app(CobroAspiranteController::class)->cancelarCargo(
            $this->peticionDe($this->usuarioConAlcance(), '/aspirantes'),
            $cargo,
        );

        $this->assertSame(Adeudo::ESTATUS_CANCELADO, $cargo->fresh()->estatus);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function generarCargo(Aspirante $aspirante, float $monto): Adeudo
    {
        $peticion = Request::create("/aspirantes/{$aspirante->id}/cobro/cargos", 'POST', [
            'concepto_id' => $this->deCatalogo('conceptos_pago'),
            'monto' => $monto,
            'fecha_vencimiento' => now()->addDays(15)->toDateString(),
        ]);
        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(CobroAspiranteController::class)->generarCargo($peticion, $aspirante);

        return Adeudo::deAspirante($aspirante->id)->latest('id')->firstOrFail();
    }

    private function registrarPago(
        Aspirante $aspirante,
        float $monto,
        string $clave,
        bool $requiereConfirmacion = false,
    ): void {
        $metodo = MetodoPago::firstOrCreate(
            ['clave' => $clave],
            ['nombre' => ucfirst($clave), 'requiere_confirmacion' => $requiereConfirmacion],
        );

        // `firstOrCreate` no actualiza si ya existía: el método puede venir del
        // catálogo sembrado con otra bandera, y la prueba depende de ella.
        $metodo->update(['requiere_confirmacion' => $requiereConfirmacion]);

        $peticion = Request::create("/aspirantes/{$aspirante->id}/cobro/pagos", 'POST', [
            'metodo_pago_id' => $metodo->id,
            'monto' => $monto,
        ]);
        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(CobroAspiranteController::class)->registrarPago($peticion, $aspirante);
    }

    private function aspirante(): Aspirante
    {
        $persona = Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'De prueba']);

        return Aspirante::create([
            'persona_id' => $persona->id,
            'situacion_id' => $this->deCatalogo('situaciones_aspirante'),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // El catálogo de conceptos no viene sembrado en pruebas.
        if (DB::table('conceptos_pago')->count() === 0) {
            $this->fila('conceptos_pago', ['clave' => 'ficha', 'nombre' => 'Ficha de admisión']);
        }
    }
}
