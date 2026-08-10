<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ServicioController;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Servicio;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El catálogo de productos y servicios.
 *
 * ── Lo que estas pruebas cuidan ────────────────────────────────────────────
 * Un servicio con precio y SIN concepto de pago se captura sin protestar, se
 * ofrece al alumno, se cobra… y revienta semanas después, al ir a facturar lo
 * que alguien ya pagó: el concepto es lo que lleva la clave del SAT y la tasa de
 * IVA. Entre el error y su consecuencia hay tanto camino que nadie los
 * relaciona, así que la regla se fija aquí.
 *
 * Y el reparto de campos entre las dos áreas: Finanzas pone el precio, Control
 * Escolar decide si el alumno lo puede pedir. Si el guardado de Finanzas tocara
 * `solicitable`, cada cambio de precio apagaría en silencio lo que la otra área
 * acabara de configurar.
 */
class CatalogoServiciosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_un_servicio_con_precio_exige_su_concepto(): void
    {
        try {
            $this->guardar(['precio' => 250, 'concepto_id' => null]);
            $this->fail('Se aceptó un servicio con costo y sin concepto de pago.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('concepto_id', $e->errors());
        }
    }

    /** Y uno gratuito no lo necesita: nunca llega a una factura. */
    public function test_un_servicio_sin_costo_no_necesita_concepto(): void
    {
        $this->guardar(['precio' => 0, 'concepto_id' => null]);

        $servicio = Servicio::query()->firstOrFail();

        $this->assertFalse($servicio->tieneCosto());
        $this->assertNull($servicio->concepto_id);
    }

    /**
     * El costo lo dice el IMPORTE, no la presencia del concepto.
     *
     * Un servicio puede tener concepto y precio cero —se dejó configurado para
     * cuando se cobre— y mientras tanto sigue siendo gratuito.
     */
    public function test_con_concepto_pero_sin_importe_sigue_siendo_gratuito(): void
    {
        $this->guardar(['precio' => 0, 'concepto_id' => $this->concepto()]);

        $this->assertFalse(Servicio::query()->firstOrFail()->tieneCosto());
    }

    /** Guardar desde Finanzas no toca lo que administra Control Escolar. */
    public function test_guardar_el_precio_no_apaga_la_solicitud(): void
    {
        $this->guardar(['precio' => 100, 'concepto_id' => $this->concepto()]);

        $servicio = Servicio::query()->firstOrFail();
        $servicio->update(['solicitable' => true, 'instrucciones' => 'Trae tu credencial.']);

        /*
         * La petición manda `solicitable` en falso a propósito.
         *
         * Es el caso que hace daño: no que Finanzas se olvide del campo, sino
         * que lo MANDE —porque alguien lo agregó al formulario, o porque el
         * navegador reenvía lo que recibió—. Sin mandarlo, la prueba pasaría
         * igual aunque el controlador lo aceptara, y no estaría comprobando
         * nada. Se descubrió mutando el código.
         */
        $this->guardar(
            ['precio' => 180, 'concepto_id' => $this->concepto(), 'solicitable' => false, 'instrucciones' => 'otra cosa'],
            $servicio,
        );

        $servicio->refresh();

        $this->assertSame('180.00', $servicio->precio);
        $this->assertTrue($servicio->solicitable, 'Finanzas no administra esto.');
        $this->assertSame('Trae tu credencial.', $servicio->instrucciones);
    }

    /**
     * Retirar apaga, no borra.
     *
     * Un servicio ya cobrado tiene adeudos colgando y su nombre es lo que
     * explica de qué era el cargo; borrarlo dejaría estados de cuenta con
     * renglones ilegibles.
     */
    public function test_retirar_deja_de_ofrecerlo_pero_no_lo_borra(): void
    {
        $this->guardar(['precio' => 0, 'concepto_id' => null]);
        $servicio = Servicio::query()->firstOrFail();
        $servicio->update(['solicitable' => true]);

        app(ServicioController::class)->destroy($servicio);
        $servicio->refresh();

        $this->assertFalse($servicio->activo);
        $this->assertFalse($servicio->solicitable, 'Retirado del catálogo, y por tanto tampoco pedible.');
        $this->assertNotNull(Servicio::query()->find($servicio->id), 'Sigue existiendo.');
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $cambios */
    private function guardar(array $cambios, ?Servicio $servicio = null): void
    {
        $peticion = Request::create('/finanzas/servicios', 'POST', array_merge([
            'clave' => 'constancia',
            'nombre' => 'Constancia de estudios',
            'descripcion' => null,
            'concepto_id' => null,
            'precio' => 0,
            'activo' => true,
        ], $cambios));

        $controlador = app(ServicioController::class);

        $servicio === null
            ? $controlador->store($peticion)
            : $controlador->update($peticion, $servicio);
    }

    private function concepto(): int
    {
        return ConceptoPago::query()->firstOrCreate(
            ['clave' => 'servicios'],
            ['nombre' => 'Servicios escolares'],
        )->id;
    }
}
