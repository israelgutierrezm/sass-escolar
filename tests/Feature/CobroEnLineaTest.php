<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\Pago;
use App\Services\Pagos\CobroEnLinea;
use App\Services\Pagos\EstadoCobro;
use App\Services\Pagos\ResultadoCobro;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Cobrar en línea, que es donde el dinero entra sin que nadie mire.
 *
 * El flujo tiene cuatro saltos —iniciar, pagar fuera, volver, recibir el aviso—
 * y los errores viven en las costuras, no en cada paso: el aviso que llega dos
 * veces, el que llega antes que el retorno, el importe que no es el que se pidió.
 * Ninguno de esos casos falla ruidosamente: acaban en un alumno con saldo a
 * favor que nadie depositó, o en un cargo liquidado sin dinero detrás.
 */
class CobroEnLineaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private CobroEnLinea $cobro;

    protected function setUp(): void
    {
        parent::setUp();

        // La pasarela de mentira: recorre el mismo código sin salir a internet.
        config()->set('pagos.modo', 'fake');

        /*
         * Con sesión iniciada, y no es un detalle.
         *
         * El trait de auditoría sólo escribe `created_by` cuando HAY usuario, y
         * una prueba anónima nunca toca esas columnas: la tabla se creó sin
         * ellas y la suite pasó en verde mientras la pantalla reventaba con un
         * 500 al primer clic. Se descubrió en el navegador, que es donde
         * siempre hay alguien autenticado.
         */
        $this->actingAs($this->usuarioConAlcance());

        $this->cobro = app(CobroEnLinea::class);
    }

    /** Empezar a pagar NO es haber pagado. */
    public function test_iniciar_no_registra_dinero(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $adeudo = $this->adeudo($matricula, 1500);

        $intencion = $this->iniciar($matricula, [$adeudo]);

        $this->assertSame(IntencionCobro::PENDIENTE, $intencion->estado);
        $this->assertSame(1500.0, (float) $intencion->monto);
        $this->assertSame(0, Pago::count(), 'Todavía no hay dinero: nadie ha pagado.');
        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, Adeudo::find($adeudo)->estatus);
    }

    /** Cuando la pasarela aprueba, el cargo queda liquidado. */
    public function test_un_cobro_aprobado_liquida_el_cargo(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $adeudo = $this->adeudo($matricula, 1500);

        $intencion = $this->iniciar($matricula, [$adeudo]);
        $this->cobro->conciliar($this->aprobado($intencion, 1500));

        $this->assertSame(IntencionCobro::PAGADA, $intencion->fresh()->estado);
        $this->assertSame(Adeudo::ESTATUS_PAGADO, Adeudo::find($adeudo)->estatus);

        $pago = Pago::firstOrFail();
        $this->assertSame(Pago::ESTATUS_COMPLETADO, $pago->estatus, 'El aviso de la pasarela ES la confirmación.');
        $this->assertSame('mercadopago', $pago->pasarela);
        $this->assertNotNull($pago->pasarela_txn_id, 'Sin la transacción no se puede rastrear en el panel.');
    }

    /**
     * El mismo aviso dos veces registra UN pago.
     *
     * Los webhooks se reintentan —es su diseño, no una falla— y encima el
     * alumno vuelve por el retorno, que también concilia. Sin protección, el
     * alumno acaba con un saldo a favor que nadie depositó.
     */
    public function test_dos_avisos_del_mismo_cobro_no_pagan_dos_veces(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $adeudo = $this->adeudo($matricula, 1000);

        $intencion = $this->iniciar($matricula, [$adeudo]);

        $this->cobro->conciliar($this->aprobado($intencion, 1000));
        $this->cobro->conciliar($this->aprobado($intencion, 1000));
        $this->cobro->conciliar($this->aprobado($intencion, 1000));

        $this->assertSame(1, Pago::count(), 'Tres avisos del mismo cobro: un solo pago.');
        $this->assertSame(1000.0, (float) Pago::sum('monto'));
    }

    /**
     * Se registra lo que la PASARELA dice que se cobró, no lo que se pidió.
     *
     * Si entró otra cantidad, lo que hay que asentar es el dinero real; cuadrar
     * la diferencia es un problema de caja, y para verlo hace falta el número
     * verdadero.
     */
    public function test_manda_el_monto_de_la_pasarela(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $adeudo = $this->adeudo($matricula, 1000);

        $intencion = $this->iniciar($matricula, [$adeudo]);
        $this->cobro->conciliar($this->aprobado($intencion, 600));

        $this->assertSame(600.0, (float) Pago::firstOrFail()->monto);
        $this->assertSame(Adeudo::ESTATUS_PARCIAL, Adeudo::find($adeudo)->estatus, 'Con 600 de 1000, el cargo queda parcial.');
    }

    /** Un cobro rechazado no toca el cargo. */
    public function test_un_rechazo_deja_el_cargo_como_estaba(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $adeudo = $this->adeudo($matricula, 800);

        $intencion = $this->iniciar($matricula, [$adeudo]);

        $this->cobro->conciliar(new ResultadoCobro($intencion->id, EstadoCobro::RECHAZADO));

        $this->assertSame(IntencionCobro::FALLIDA, $intencion->fresh()->estado);
        $this->assertSame(0, Pago::count());
        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, Adeudo::find($adeudo)->estatus);
    }

    /**
     * Un cobro en proceso deja la intención abierta.
     *
     * El SPEI tarda y el efectivo en tienda tarda más. Cerrar el intento porque
     * todavía no hay respuesta obligaría a pagar otra vez algo quizá ya pagado.
     */
    public function test_un_cobro_en_proceso_sigue_vivo(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $intencion = $this->iniciar($matricula, [$this->adeudo($matricula, 500)]);

        $this->cobro->conciliar(new ResultadoCobro($intencion->id, EstadoCobro::PENDIENTE));

        $this->assertSame(IntencionCobro::PENDIENTE, $intencion->fresh()->estado);
        $this->assertSame(0, Pago::count());
    }

    /**
     * Y uno que no se pudo verificar TAMPOCO se cierra.
     *
     * «No sé» no es «no pagó»: si la pasarela no contesta y se diera por
     * fallido, un pago que sí entró quedaría sin aplicar y el alumno pagando
     * dos veces.
     */
    public function test_lo_que_no_se_pudo_verificar_queda_abierto(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $intencion = $this->iniciar($matricula, [$this->adeudo($matricula, 500)]);

        $this->cobro->conciliar(new ResultadoCobro($intencion->id, EstadoCobro::DESCONOCIDO));

        $this->assertSame(IntencionCobro::PENDIENTE, $intencion->fresh()->estado);
        $this->assertSame(0, Pago::count());
    }

    /**
     * No se pueden pagar los cargos de otro alumno.
     *
     * Los ids llegan del navegador: sin filtrar por titular, cualquiera manda
     * los cargos ajenos que quiera. El daño no es sólo pagarle a otro —es que su
     * propio pago liquide deuda que no le corresponde.
     */
    public function test_no_se_pueden_pagar_cargos_ajenos(): void
    {
        $mio = $this->alumnoInscrito()['matricula'];
        $ajeno = $this->alumnoInscrito()['matricula'];
        $delOtro = $this->adeudo($ajeno, 900);

        $this->expectException(AvisoParaElUsuario::class);

        $this->iniciar($mio, [$delOtro]);
    }

    /** Y un aviso que no se puede atribuir a nadie no mueve nada. */
    public function test_un_aviso_huerfano_no_hace_nada(): void
    {
        $this->assertNull($this->cobro->conciliar(new ResultadoCobro(null, EstadoCobro::APROBADO, 5000.0)));
        $this->assertSame(0, Pago::count());
    }

    /** Tampoco se cobra un cargo que ya se había liquidado. */
    public function test_no_se_cobra_lo_que_ya_no_se_debe(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $adeudo = $this->adeudo($matricula, 700);

        Adeudo::whereKey($adeudo)->update(['estatus' => Adeudo::ESTATUS_PAGADO]);

        $this->expectException(AvisoParaElUsuario::class);

        $this->iniciar($matricula, [$adeudo]);
    }

    /**
     * Varios cargos se juntan en un solo cobro.
     *
     * Es lo normal: se deben tres mensualidades y se pagan de una.
     */
    public function test_se_pueden_pagar_varios_cargos_de_una(): void
    {
        $matricula = $this->alumnoInscrito()['matricula'];
        $uno = $this->adeudo($matricula, 1000, '2026-01-15');
        $dos = $this->adeudo($matricula, 500, '2026-02-15');

        $intencion = $this->iniciar($matricula, [$uno, $dos]);

        $this->assertSame(1500.0, (float) $intencion->monto);

        $this->cobro->conciliar($this->aprobado($intencion, 1500));

        $this->assertSame(Adeudo::ESTATUS_PAGADO, Adeudo::find($uno)->estatus);
        $this->assertSame(Adeudo::ESTATUS_PAGADO, Adeudo::find($dos)->estatus);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, int>  $adeudos */
    private function iniciar(int $matricula, array $adeudos): IntencionCobro
    {
        return $this->cobro->iniciar(
            \App\Models\Admisiones\MatriculaOferta::findOrFail($matricula),
            'mercadopago',
            $adeudos,
            'http://demo.test/pagos/retorno',
            'http://demo.test/pagos/aviso/mercadopago',
        );
    }

    private function aprobado(IntencionCobro $intencion, float $monto): ResultadoCobro
    {
        return new ResultadoCobro(
            intencionId: $intencion->id,
            estado: EstadoCobro::APROBADO,
            monto: $monto,
            transaccionId: 'txn-'.$intencion->id,
        );
    }

    private function adeudo(int $matricula, float $monto, string $vence = '2026-03-01'): int
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
