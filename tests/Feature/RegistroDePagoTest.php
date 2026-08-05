<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\FinanzasController;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Un pago tiene que liquidar cargos, no sólo quedar registrado.
 *
 * El formulario de cobro manda siempre la lista de cargos marcados, vacía cuando
 * no se marcó ninguno —que es el caso normal: se cobra y que el sistema decida
 * a qué se aplica—. El servidor la tomaba al pie de la letra: «cubre exactamente
 * estos cero cargos». El dinero se registraba, ningún adeudo bajaba, todo quedaba
 * a favor, y la pantalla decía «Pago registrado y aplicado» mientras el saldo no
 * se movía. Se descubrió cobrando en el navegador.
 */
class RegistroDePagoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private FinanzasController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(FinanzasController::class);

        Session::start();
    }

    /** Sin marcar cargos: se cubren los más vencidos primero. */
    public function test_un_pago_sin_cargos_marcados_liquida_los_mas_vencidos(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $viejo = $this->adeudo($matricula, 1000, '2026-01-15');
        $nuevo = $this->adeudo($matricula, 1000, '2026-06-15');

        $this->cobrar($matricula, 1000, []);

        $this->assertSame(Adeudo::ESTATUS_PAGADO, Adeudo::find($viejo)->estatus, 'El más vencido primero.');
        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, Adeudo::find($nuevo)->estatus);
    }

    /** Lo que sobra después de cubrir todo sí queda a favor. */
    public function test_lo_que_sobra_queda_a_favor(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $unico = $this->adeudo($matricula, 400, '2026-03-01');

        $this->cobrar($matricula, 1000, []);

        $this->assertSame(Adeudo::ESTATUS_PAGADO, Adeudo::find($unico)->estatus);
        $this->assertSame(400.0, (float) DB::table('pago_adeudo')->sum('monto_aplicado'), 'Sólo se aplica lo que cabía.');
    }

    /**
     * Marcando cargos se respeta la elección: si el alumno viene a pagar su
     * titulación, no se le aplica a la colegiatura de marzo porque esté más
     * vencida.
     */
    public function test_marcando_un_cargo_se_respeta_esa_eleccion(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $viejo = $this->adeudo($matricula, 1000, '2026-01-15');
        $elegido = $this->adeudo($matricula, 1000, '2026-06-15');

        $this->cobrar($matricula, 1000, [$elegido]);

        $this->assertSame(Adeudo::ESTATUS_PAGADO, Adeudo::find($elegido)->estatus);
        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, Adeudo::find($viejo)->estatus, 'El más vencido NO se toca.');
    }

    /** Un pago menor deja el cargo a medias, no lo da por saldado. */
    public function test_un_pago_insuficiente_deja_el_cargo_parcial(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $adeudo = $this->adeudo($matricula, 1000, '2026-03-01');

        $this->cobrar($matricula, 400, []);

        $this->assertSame(Adeudo::ESTATUS_PARCIAL, Adeudo::find($adeudo)->estatus);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, int>  $adeudoIds */
    private function cobrar(int $matricula, float $monto, array $adeudoIds): void
    {
        $peticion = Request::create("/finanzas/cuentas/{$matricula}/pagos", 'POST', [
            'metodo_pago_id' => $this->metodoDeContado(),
            'monto' => $monto,
            // Tal cual lo manda el formulario: la lista siempre viaja.
            'adeudo_ids' => $adeudoIds,
        ]);

        $this->controlador->registrarPago($peticion, \App\Models\Admisiones\MatriculaOferta::findOrFail($matricula));
    }

    /** Un método que cobra en el acto; los que requieren confirmación no liquidan. */
    private function metodoDeContado(): int
    {
        $id = MetodoPago::query()->where('requiere_confirmacion', false)->value('id');

        return $id ?? $this->fila('metodos_pago', [
            'clave' => 'efectivo',
            'nombre' => 'Efectivo',
            'requiere_confirmacion' => false,
            'activo' => true,
        ]);
    }

    private function adeudo(int $matricula, float $monto, string $vence): int
    {
        return $this->fila('adeudos', [
            'matricula_oferta_id' => $matricula,
            'concepto_id' => $this->deCatalogo('conceptos_pago'),
            'monto' => $monto,
            'monto_total' => $monto,
            'fecha_generacion' => '2026-01-01',
            'fecha_vencimiento' => $vence,
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);
    }
}
