<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ComprobantePagoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Usuario;
use App\Services\RevisorDeComprobantes;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Pagar por transferencia, sin pasarela.
 *
 * ── La diferencia con la pasarela ──────────────────────────────────────────
 * Allí el banco confirma y el cargo se liquida solo. Aquí no hay quien
 * confirme: hay una imagen que alguien subió, y el cargo sigue abierto hasta
 * que una persona de la escuela la valida. Lo que estas pruebas sostienen es
 * que ese «hasta que» se respete, porque darlo por bueno antes es dar por
 * cobrado dinero que quizá no llegó.
 */
class ComprobanteDeTransferenciaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        Session::start();
        Storage::fake('local');
    }

    /** Subirlo NO liquida nada. */
    public function test_subir_el_comprobante_no_paga_el_cargo(): void
    {
        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 1500);

        $this->subir($escuela['matricula'], 1500, [$adeudo]);

        $this->assertSame(ComprobantePago::PENDIENTE, ComprobantePago::firstOrFail()->estado);
        $this->assertSame(0, Pago::count(), 'Todavía no hay dinero: nadie lo ha validado.');
        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, Adeudo::find($adeudo)->estatus);
    }

    /** Y el archivo queda en disco privado. */
    public function test_el_archivo_se_guarda(): void
    {
        $escuela = $this->alumnoInscrito();

        $this->subir($escuela['matricula'], 500, []);

        Storage::disk('local')->assertExists(ComprobantePago::firstOrFail()->archivo);
    }

    /** Al aprobarlo sí: nace el pago y el cargo queda liquidado. */
    public function test_aprobar_registra_el_pago_y_liquida(): void
    {
        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 1500);

        $this->subir($escuela['matricula'], 1500, [$adeudo]);
        $comprobante = ComprobantePago::firstOrFail();

        app(RevisorDeComprobantes::class)->aprobar($comprobante, $this->usuarioConAlcance());

        $this->assertSame(ComprobantePago::APROBADO, $comprobante->fresh()->estado);
        $this->assertSame(Adeudo::ESTATUS_PAGADO, Adeudo::find($adeudo)->estatus);

        $pago = Pago::firstOrFail();
        $this->assertSame(Pago::ESTATUS_COMPLETADO, $pago->estatus);
        $this->assertSame(1500.0, (float) $pago->monto);
        $this->assertSame($pago->id, $comprobante->fresh()->pago_id);
    }

    /**
     * Al revisar se puede corregir el monto.
     *
     * El comprobante dice una cosa y el banco otra más veces de las que
     * parece: se registra lo que de verdad entró.
     */
    public function test_se_puede_corregir_el_monto_al_aprobar(): void
    {
        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 1500);

        $this->subir($escuela['matricula'], 1500, [$adeudo]);

        app(RevisorDeComprobantes::class)->aprobar(
            ComprobantePago::firstOrFail(),
            $this->usuarioConAlcance(),
            monto: 900,
        );

        $this->assertSame(900.0, (float) Pago::firstOrFail()->monto);
        $this->assertSame(Adeudo::ESTATUS_PARCIAL, Adeudo::find($adeudo)->estatus);
    }

    /** Rechazarlo no toca ningún cargo, y guarda el motivo. */
    public function test_rechazar_no_toca_los_cargos(): void
    {
        $escuela = $this->alumnoInscrito();
        $adeudo = $this->adeudo($escuela['matricula'], 1500);

        $this->subir($escuela['matricula'], 1500, [$adeudo]);

        app(RevisorDeComprobantes::class)->rechazar(
            ComprobantePago::firstOrFail(),
            $this->usuarioConAlcance(),
            'El comprobante es de otro banco.',
        );

        $comprobante = ComprobantePago::firstOrFail();
        $this->assertSame(ComprobantePago::RECHAZADO, $comprobante->estado);
        $this->assertSame('El comprobante es de otro banco.', $comprobante->motivo_rechazo);
        $this->assertSame(0, Pago::count());
        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, Adeudo::find($adeudo)->estatus);
    }

    /**
     * Dos personas no pueden aprobar el mismo comprobante.
     *
     * Es la cola abierta en dos pantallas a la vez: sin la comprobación serían
     * dos pagos por un solo depósito.
     */
    public function test_no_se_aprueba_dos_veces(): void
    {
        $escuela = $this->alumnoInscrito();
        $this->adeudo($escuela['matricula'], 1500);
        $this->subir($escuela['matricula'], 1500, []);

        $comprobante = ComprobantePago::firstOrFail();
        $revisor = $this->usuarioConAlcance();

        app(RevisorDeComprobantes::class)->aprobar($comprobante, $revisor);

        $this->expectException(AvisoParaElUsuario::class);

        app(RevisorDeComprobantes::class)->aprobar($comprobante->fresh(), $revisor);
    }

    /** Ni rechazar lo que ya se aprobó. */
    public function test_no_se_rechaza_lo_ya_aprobado(): void
    {
        $escuela = $this->alumnoInscrito();
        $this->subir($escuela['matricula'], 500, []);

        $comprobante = ComprobantePago::firstOrFail();
        $revisor = $this->usuarioConAlcance();

        app(RevisorDeComprobantes::class)->aprobar($comprobante, $revisor);

        $this->expectException(AvisoParaElUsuario::class);

        app(RevisorDeComprobantes::class)->rechazar($comprobante->fresh(), $revisor, 'Ya no.');
    }

    /**
     * No se pueden declarar cargos de otro alumno.
     *
     * Los ids vienen del navegador: sin filtrar, un comprobante podría decir
     * que paga la deuda de cualquiera.
     */
    public function test_no_se_pueden_declarar_cargos_ajenos(): void
    {
        $mio = $this->alumnoInscrito();
        $ajeno = $this->alumnoInscrito();
        $delOtro = $this->adeudo($ajeno['matricula'], 900);

        $this->subir($mio['matricula'], 900, [$delOtro]);

        $this->assertSame([], ComprobantePago::firstOrFail()->adeudo_ids);
    }

    // ── Cuentas bancarias ──────────────────────────────────────────────────

    /** Una cuenta sin programas académicos marcados vale para todas. */
    public function test_una_cuenta_sin_programas_academicos_vale_para_todas(): void
    {
        $cuenta = CuentaBancaria::create([
            'nombre' => 'General', 'banco' => 'BBVA', 'titular' => 'Escuela',
            'clabe' => '012345678901234567', 'activa' => true,
        ]);

        $this->assertTrue($cuenta->aplicaA(1));
        $this->assertTrue($cuenta->aplicaA(999));
        $this->assertTrue($cuenta->aplicaA(null));
    }

    /** Con programas académicos marcados, sólo para ésas. */
    public function test_una_cuenta_con_programas_academicos_solo_vale_para_esas(): void
    {
        $escuela = $this->alumnoInscrito();
        $programaAcademico = MatriculaOferta::findOrFail($escuela['matricula'])->oferta?->programa_academico_id;

        $cuenta = CuentaBancaria::create([
            'nombre' => 'Posgrado', 'banco' => 'Santander', 'titular' => 'Escuela',
            'clabe' => '012345678901234567', 'activa' => true,
        ]);
        $cuenta->programasAcademicos()->attach($programaAcademico);

        $this->assertTrue($cuenta->fresh()->aplicaA($programaAcademico));
        $this->assertFalse($cuenta->fresh()->aplicaA($programaAcademico + 999));
    }

    /**
     * Sin CLABE ni número de cuenta no se puede recibir nada.
     *
     * Lo que vería quien va a pagar sería un banco y un nombre.
     */
    public function test_una_cuenta_sin_datos_no_puede_recibir(): void
    {
        $cuenta = CuentaBancaria::create([
            'nombre' => 'Incompleta', 'banco' => 'BBVA', 'titular' => 'Escuela', 'activa' => true,
        ]);

        $this->assertFalse($cuenta->puedeRecibir());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, int>  $adeudos */
    private function subir(int $matricula, float $monto, array $adeudos): void
    {
        $peticion = Request::create("/comprobantes/{$matricula}", 'POST', [
            'monto' => $monto,
            'fecha_transferencia' => now()->subDay()->toDateString(),
            'referencia' => 'REF-123',
            'adeudo_ids' => $adeudos,
        ], [], [
            'archivo' => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

        $peticion->setUserResolver(fn (): Usuario => $this->usuarioConAlcance());

        app(ComprobantePagoController::class)
            ->guardar($peticion, MatriculaOferta::findOrFail($matricula));
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
