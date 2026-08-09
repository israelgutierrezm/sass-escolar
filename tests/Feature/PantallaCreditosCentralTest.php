<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Landlord\CompraCreditos;
use App\Models\Landlord\SaldoEmision;
use App\Models\Landlord\SuperAdmin;
use Tests\TenantTestCase;

/**
 * La pantalla desde la que se valida lo que las escuelas pagan.
 *
 * Lo que se prueba aquí no es que la tabla se pinte, sino QUIÉN puede tocarla:
 * la escuela paga y la casa cobra, así que acreditar créditos o poner a alguien
 * en «ilimitado» es mover dinero. Un rol que no lleva las cuentas —comercial,
 * soporte— no debe poder hacerlo desde el navegador aunque le llegue el enlace.
 */
class PantallaCreditosCentralTest extends TenantTestCase
{
    private function superAdmin(string $rol): SuperAdmin
    {
        return SuperAdmin::create([
            'nombre' => 'Quien sea',
            'email' => $rol.'@acadion.mx',
            'password' => 'secreto123',
            'rol' => $rol,
        ]);
    }

    private function compraPendiente(int $creditos = 50): CompraCreditos
    {
        return CompraCreditos::create([
            'tenant_id' => 'demo',
            'creditos' => $creditos,
            'monto' => 1500,
            'estado' => CompraCreditos::PENDIENTE,
        ]);
    }

    public function test_sin_sesion_no_se_ve_la_cola(): void
    {
        $this->get('http://localhost/creditos')->assertRedirect();
    }

    public function test_finanzas_ve_la_compra_pendiente(): void
    {
        $this->compraPendiente();

        $this->actingAs($this->superAdmin('finanzas'), 'central')
            ->get('http://localhost/creditos')
            ->assertOk()
            ->assertViewIs('central.creditos.index')
            ->assertViewHas('puedeValidar', true)
            ->assertSee('demo');
    }

    /**
     * Comercial entra pero no ve los botones.
     *
     * Se comprueba con `puedeValidar` porque de ahí cuelga toda la UI de acción:
     * si llegara en `true`, la vista pintaría «Aprobar» a quien no debe.
     */
    public function test_comercial_entra_pero_no_puede_validar(): void
    {
        $this->actingAs($this->superAdmin('comercial'), 'central')
            ->get('http://localhost/creditos')
            ->assertOk()
            ->assertViewHas('puedeValidar', false);
    }

    /** Y no le basta con mandar el POST a mano. */
    public function test_comercial_no_puede_aprobar_por_su_cuenta(): void
    {
        $compra = $this->compraPendiente();

        $this->actingAs($this->superAdmin('comercial'), 'central')
            ->post("http://localhost/creditos/{$compra->id}/aprobar")
            ->assertForbidden();

        $this->assertSame(CompraCreditos::PENDIENTE, $compra->fresh()->estado);
        $this->assertSame(0, SaldoEmision::de('demo')->creditos);
    }

    public function test_aprobar_acredita_los_creditos(): void
    {
        $saldo = SaldoEmision::de('demo');
        $saldo->update(['modalidad' => SaldoEmision::PREPAGO, 'creditos' => 10]);
        $compra = $this->compraPendiente(50);

        $this->actingAs($this->superAdmin('finanzas'), 'central')
            ->post("http://localhost/creditos/{$compra->id}/aprobar")
            ->assertRedirect();

        $this->assertSame(CompraCreditos::APROBADA, $compra->fresh()->estado);
        $this->assertSame(60, SaldoEmision::de('demo')->creditos);
    }

    /**
     * Rechazar sin motivo no pasa.
     *
     * Sin él la escuela ve «rechazada» y no sabe qué corregir, así que vuelve a
     * subir el mismo comprobante y la cola no avanza.
     */
    public function test_rechazar_exige_motivo(): void
    {
        $compra = $this->compraPendiente();

        $this->actingAs($this->superAdmin('superadmin'), 'central')
            ->post("http://localhost/creditos/{$compra->id}/rechazar", ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(CompraCreditos::PENDIENTE, $compra->fresh()->estado);
    }

    public function test_rechazar_con_motivo_lo_guarda(): void
    {
        $compra = $this->compraPendiente();

        $this->actingAs($this->superAdmin('superadmin'), 'central')
            ->post("http://localhost/creditos/{$compra->id}/rechazar", [
                'motivo' => 'El comprobante es de otra cuenta.',
            ])
            ->assertRedirect();

        $compra->refresh();
        $this->assertSame(CompraCreditos::RECHAZADA, $compra->estado);
        $this->assertSame('El comprobante es de otra cuenta.', $compra->motivo_rechazo);
        $this->assertSame(0, SaldoEmision::de('demo')->creditos);
    }

    /**
     * Cambiar de modalidad no borra el saldo.
     *
     * Es el descuido caro: la escuela pagó 40 créditos, alguien la pasa a
     * postpago por un mes y al volver a prepago no tendría con qué emitir.
     */
    public function test_cambiar_modalidad_deja_el_saldo_intacto(): void
    {
        SaldoEmision::de('demo')->update(['modalidad' => SaldoEmision::PREPAGO, 'creditos' => 40]);

        $this->actingAs($this->superAdmin('finanzas'), 'central')
            ->put('http://localhost/creditos/escuelas/demo', ['modalidad' => SaldoEmision::POSTPAGO])
            ->assertRedirect();

        $saldo = SaldoEmision::de('demo');
        $this->assertSame(SaldoEmision::POSTPAGO, $saldo->modalidad);
        $this->assertSame(40, $saldo->creditos);
    }

    public function test_el_saldo_si_se_fija_cuando_se_dice(): void
    {
        SaldoEmision::de('demo')->update(['modalidad' => SaldoEmision::PREPAGO, 'creditos' => 40]);

        $this->actingAs($this->superAdmin('finanzas'), 'central')
            ->put('http://localhost/creditos/escuelas/demo', [
                'modalidad' => SaldoEmision::PREPAGO,
                'creditos' => 0,
            ])
            ->assertRedirect();

        $this->assertSame(0, SaldoEmision::de('demo')->creditos);
    }

    public function test_soporte_no_cambia_la_modalidad(): void
    {
        SaldoEmision::de('demo')->update(['modalidad' => SaldoEmision::PREPAGO, 'creditos' => 5]);

        $this->actingAs($this->superAdmin('soporte'), 'central')
            ->put('http://localhost/creditos/escuelas/demo', ['modalidad' => SaldoEmision::ILIMITADO])
            ->assertForbidden();

        $this->assertSame(SaldoEmision::PREPAGO, SaldoEmision::de('demo')->modalidad);
    }
}
