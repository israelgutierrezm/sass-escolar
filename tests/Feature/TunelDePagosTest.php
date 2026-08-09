<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Registrar el dominio del túnel para que los avisos encuentren la escuela.
 *
 * ── Por qué hace falta un comando ──────────────────────────────────────────
 * Levantar ngrok no basta. Las escuelas se identifican POR DOMINIO, y el host
 * que entrega un túnel no está registrado en ninguna parte: el aviso de la
 * pasarela llega, no encuentra escuela y muere. Desde fuera no se ve nada roto
 * —el cobro se abre, la liga funciona, alguien paga— y el pago simplemente no
 * se aplica.
 */
class TunelDePagosTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->escuela('escuela-tunel');
        config(['services.pagos.url_publica' => 'https://abc123.ngrok-free.app']);
    }

    /**
     * Una escuela en el registro, SIN aprovisionarla.
     *
     * `Tenant::create` levanta su base y le corre doscientas migraciones: cada
     * caso tardaba minuto y medio y, peor, el DDL provoca un commit implícito
     * que cierra la transacción de aislamiento —lo mismo que ya documenta
     * `TenantTestCase`—, así que un caso se llevaba sus filas al siguiente y
     * las pruebas fallaban por el estado de la anterior.
     *
     * Aquí sólo hace falta que el registro exista: el comando no entra a la
     * base de la escuela, sólo apunta un dominio hacia ella.
     */
    private function escuela(string $id): void
    {
        DB::connection('central')->table('tenants')->insertOrIgnore([
            'id' => $id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_registra_el_dominio_del_tunel(): void
    {
        $this->artisan('pagos:tunel', ['escuela' => 'escuela-tunel'])
            ->assertSuccessful();

        $this->assertTrue($this->existe('abc123.ngrok-free.app', 'escuela-tunel'));
    }

    /**
     * Correrlo dos veces no duplica.
     *
     * Se corre cada vez que el túnel cambia de dirección, y a veces sin que
     * haya cambiado: duplicar filas dejaría la tabla de dominios sucia.
     */
    public function test_correrlo_dos_veces_no_duplica(): void
    {
        $this->artisan('pagos:tunel', ['escuela' => 'escuela-tunel'])->assertSuccessful();
        $this->artisan('pagos:tunel', ['escuela' => 'escuela-tunel'])->assertSuccessful();

        $this->assertSame(1, DB::connection('central')->table('domains')
            ->where('domain', 'abc123.ngrok-free.app')->count());
    }

    /**
     * No le roba el dominio a otra escuela.
     *
     * Dos escuelas apuntando al mismo host es un aviso que se aplica en la
     * contabilidad equivocada, y eso no se descubre pronto.
     */
    public function test_no_le_quita_el_dominio_a_otra_escuela(): void
    {
        $this->escuela('otra-escuela');
        $this->artisan('pagos:tunel', ['escuela' => 'otra-escuela'])->assertSuccessful();

        $this->artisan('pagos:tunel', ['escuela' => 'escuela-tunel'])
            ->assertFailed();

        $this->assertTrue($this->existe('abc123.ngrok-free.app', 'otra-escuela'));
        $this->assertFalse($this->existe('abc123.ngrok-free.app', 'escuela-tunel'));
    }

    /** Y se puede quitar, para no dejar un dominio muerto apuntando a nada. */
    public function test_quita_el_dominio(): void
    {
        $this->artisan('pagos:tunel', ['escuela' => 'escuela-tunel'])->assertSuccessful();

        $this->artisan('pagos:tunel', ['escuela' => 'escuela-tunel', '--quitar' => true])
            ->assertSuccessful();

        $this->assertFalse($this->existe('abc123.ngrok-free.app', 'escuela-tunel'));
    }

    /** Sin la dirección configurada no hay nada que registrar, y se dice. */
    public function test_sin_url_publica_no_hace_nada(): void
    {
        config(['services.pagos.url_publica' => null]);

        $this->artisan('pagos:tunel', ['escuela' => 'escuela-tunel'])
            ->expectsOutputToContain('Falta PAGOS_URL_PUBLICA')
            ->assertFailed();

        $this->assertSame(0, DB::connection('central')->table('domains')
            ->where('tenant_id', 'escuela-tunel')->count());
    }

    /** Una escuela que no existe no se inventa. */
    public function test_una_escuela_inexistente_falla(): void
    {
        $this->artisan('pagos:tunel', ['escuela' => 'no-existe'])
            ->assertFailed();
    }

    private function existe(string $dominio, string $tenant): bool
    {
        return DB::connection('central')->table('domains')
            ->where('domain', $dominio)
            ->where('tenant_id', $tenant)
            ->exists();
    }
}
