<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\Servicio;
use App\Models\Finanzas\SolicitudServicio;
use App\Services\SolicitudDeServicios;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Pedir un servicio y cobrarlo.
 *
 * ── Lo que estas pruebas cuidan ────────────────────────────────────────────
 * «Esperando pago» NO se guarda: se le pregunta al adeudo cada vez. Guardarlo
 * crearía un segundo sitio donde vive la verdad sobre lo cobrado, y los dos se
 * separarían en cuanto un pago se aplique desde otra pantalla —o se condone, o
 * se revierta—. La solicitud diría «falta pagar» de algo ya cobrado, o al revés,
 * y nada lo avisaría: las dos pantallas se ven perfectamente bien.
 *
 * Y el precio se COPIA al pedir. Si se leyera del catálogo al mostrarlo, subir
 * la tarifa el martes le cambiaría el cargo a quien pidió el lunes, sin que nada
 * quedara registrado.
 */
class SolicitudDeServiciosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_un_servicio_con_costo_genera_su_cargo(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 250));

        $this->assertNotNull($solicitud->adeudo_id, 'Debió generar el cargo.');
        $this->assertSame('250.00', $solicitud->adeudo->monto_total);
        $this->assertSame($this->matricula()->id, $solicitud->adeudo->matricula_oferta_id);
    }

    public function test_un_servicio_sin_costo_no_genera_cargo(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 0));

        $this->assertNull($solicitud->adeudo_id);
        $this->assertFalse($solicitud->esperandoPago());
        $this->assertTrue($solicitud->enProceso(), 'Sin costo, entra directo a la fila.');
    }

    /**
     * El estado de pago sale del adeudo, no de una copia.
     *
     * Se paga el adeudo por fuera —como lo haría la pasarela o la aprobación de
     * un comprobante, que no pasan por esta solicitud— y la solicitud tiene que
     * enterarse sola.
     */
    public function test_esperando_pago_se_resuelve_contra_el_adeudo(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 250));

        $this->assertTrue($solicitud->esperandoPago());
        $this->assertFalse($solicitud->enProceso());

        $solicitud->adeudo->update(['estatus' => Adeudo::ESTATUS_PAGADO]);
        $solicitud->refresh()->load('adeudo');

        $this->assertFalse($solicitud->esperandoPago(), 'El adeudo manda.');
        $this->assertTrue($solicitud->enProceso());
    }

    /**
     * Pagado del todo, aunque el `estatus` se haya quedado atrás.
     *
     * Es el caso que obliga a mirar el SALDO y no sólo el estatus. El estatus lo
     * mueve quien registra el pago; el saldo sale de los pagos aplicados, que es
     * el dato duro. Si una pasarela aplica el pago y algo deja el estatus en
     * «pendiente», el alumno vería «falta tu pago» de algo que ya pagó, y en la
     * bandeja de la escuela su trámite no aparecería como trabajable.
     *
     * Se descubrió mutando el código: sin este caso, quitar la comprobación del
     * saldo no hacía fallar nada.
     */
    public function test_un_cargo_cubierto_no_detiene_aunque_el_estatus_no_se_haya_movido(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 250));
        $adeudo = $solicitud->adeudo;

        $this->aplicarPago($adeudo, 250);

        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, $adeudo->refresh()->estatus, 'El estatus se quedó atrás a propósito.');
        $this->assertSame(0.0, $adeudo->saldo());

        $solicitud->refresh()->load('adeudo');

        $this->assertFalse($solicitud->esperandoPago(), 'Ya está cubierto: manda el saldo.');
        $this->assertTrue($solicitud->enProceso());
    }

    /** Y a medio pagar sigue deteniendo: un abono no es el pago. */
    public function test_un_abono_parcial_sigue_deteniendo_el_tramite(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 250));

        $this->aplicarPago($solicitud->adeudo, 100);

        $solicitud->refresh()->load('adeudo');

        $this->assertTrue($solicitud->esperandoPago());
    }

    /**
     * Condonar el cargo desbloquea el trámite.
     *
     * Es el mismo criterio que usa `Adeudo::porCobrar` en todo el sistema: lo
     * condonado y lo cancelado ya no se le cobran a nadie. Si aquí siguiera
     * pesando, regalarle la constancia a un alumno lo dejaría esperándola para
     * siempre —y en pantalla se vería «falta tu pago» de algo que ya nadie cobra.
     */
    public function test_un_cargo_condonado_deja_de_detener_el_tramite(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 250));

        $solicitud->adeudo->update(['estatus' => Adeudo::ESTATUS_CONDONADO]);
        $solicitud->refresh()->load('adeudo');

        $this->assertFalse($solicitud->esperandoPago());
        $this->assertTrue($solicitud->enProceso());
    }

    /**
     * El precio se congela al pedir: subir la tarifa no recobra lo de ayer.
     *
     * Nota honesta sobre su alcance: hoy el cargo guarda un IMPORTE, así que
     * esto no puede romperse desde el servicio —se comprobó mutándolo y el caso
     * seguía pasando—. Se deja como guardia de lo que sí puede pasar más
     * adelante: que alguien haga que la pantalla, o el estado de cuenta, lean el
     * precio vigente del catálogo en vez del que se cobró.
     */
    public function test_el_precio_se_copia_al_pedir(): void
    {
        $servicio = $this->servicio(precio: 250);
        $solicitud = $this->pedir($servicio);

        $servicio->update(['precio' => 900]);
        $solicitud->refresh()->load('adeudo');

        $this->assertSame('250.00', $solicitud->adeudo->monto_total);
    }

    /** Lo que no se ofrece no se pide, aunque llegue el id en la petición. */
    public function test_un_servicio_que_no_se_ofrece_no_se_puede_pedir(): void
    {
        $servicio = $this->servicio(precio: 0);
        $servicio->update(['solicitable' => false]);

        $this->expectException(RuntimeException::class);

        $this->pedir($servicio);
    }

    public function test_un_servicio_retirado_no_se_puede_pedir(): void
    {
        $servicio = $this->servicio(precio: 0);
        $servicio->update(['activo' => false]);

        $this->expectException(RuntimeException::class);

        $this->pedir($servicio);
    }

    /**
     * Rechazar no toca el adeudo.
     *
     * Devolver dinero es un acto de finanzas —condonar, aplicar a otra cosa o
     * reembolsar—, con su permiso y su bitácora. Hacerlo de paso desde el
     * mostrador escondería un movimiento de dinero dentro de una acción que
     * parece administrativa.
     */
    public function test_rechazar_no_cancela_el_cargo(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 250));

        app(SolicitudDeServicios::class)->cerrar(
            $solicitud,
            SolicitudServicio::ESTADO_RECHAZADA,
            'No aplica en este ciclo.',
            null,
        );

        $this->assertSame(Adeudo::ESTATUS_PENDIENTE, $solicitud->adeudo->refresh()->estatus);
    }

    /** Una solicitud cerrada no se vuelve a cerrar ni se cancela. */
    public function test_una_solicitud_cerrada_ya_no_se_toca(): void
    {
        $solicitud = $this->pedir($this->servicio(precio: 0));
        $servicio = app(SolicitudDeServicios::class);

        $servicio->cerrar($solicitud, SolicitudServicio::ESTADO_ATENDIDA, null, null);

        $this->assertFalse($solicitud->esCancelable());

        $this->expectException(RuntimeException::class);
        $servicio->cerrar($solicitud, SolicitudServicio::ESTADO_ATENDIDA, null, null);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function pedir(Servicio $servicio): SolicitudServicio
    {
        return app(SolicitudDeServicios::class)->pedir($servicio, $this->matricula());
    }

    /**
     * Un pago completado aplicado al adeudo, SIN tocar su `estatus`.
     *
     * Deja a propósito la inconsistencia que se quiere probar: el dinero entró
     * pero el estatus del adeudo no se movió.
     */
    private function aplicarPago(Adeudo $adeudo, float $monto): void
    {
        $pago = Pago::create([
            'matricula_oferta_id' => $adeudo->matricula_oferta_id,
            'metodo_pago_id' => $this->deCatalogo('metodos_pago'),
            'monto' => $monto,
            'estatus' => Pago::ESTATUS_COMPLETADO,
            'momento' => now(),
        ]);

        DB::table('pago_adeudo')->insert([
            'pago_id' => $pago->id,
            'adeudo_id' => $adeudo->id,
            'monto_aplicado' => $monto,
        ]);
    }

    private function servicio(float $precio): Servicio
    {
        return Servicio::create([
            'clave' => 'constancia-'.uniqid(),
            'nombre' => 'Constancia de estudios',
            'concepto_id' => $precio > 0 ? $this->concepto() : null,
            'precio' => $precio,
            'solicitable' => true,
            'activo' => true,
        ]);
    }

    private ?MatriculaOferta $matricula = null;

    private function matricula(): MatriculaOferta
    {
        return $this->matricula ??= MatriculaOferta::findOrFail($this->alumnoInscrito()['matricula']);
    }

    private function concepto(): int
    {
        return ConceptoPago::query()->firstOrCreate(
            ['clave' => 'servicios'],
            ['nombre' => 'Servicios escolares'],
        )->id;
    }
}
