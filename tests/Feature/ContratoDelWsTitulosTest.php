<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Emision\TitulacionWsConfig;
use App\Services\Emision\ClienteTitulosSep;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TenantTestCase;

/**
 * «Probar conexión» tiene que decir si el WSDL ofrece lo que llamamos.
 *
 * ── Por qué ────────────────────────────────────────────────────────────────
 * Los nombres de las dos operaciones —`cargaTituloElectronico` y
 * `consultaProcesoTituloElectronico`— se escribieron contra la documentación de
 * la SEP, no contra el contrato en vivo. Si alguno no coincide, antes se
 * descubría al enviar un título de verdad: el lote quedaba firmado y marcado
 * como enviado, y volvía un SOAP fault que no dice cuál es el problema.
 *
 * Que el WSDL «cargue» no basta como prueba de nada: carga igual aunque ofrezca
 * operaciones con otro nombre. Estas pruebas usan WSDL locales para no depender
 * de la red ni de credenciales.
 *
 * ── Por qué los casos que tocan SOAP corren aparte ─────────────────────────
 * La extensión SOAP de PHP deja algo vivo al construir un `SoapClient` que hace
 * crecer la memoria de TODA la suite que corre después: medido, el pico pasa de
 * 122 MB a ~500 MB, y con el límite de 128 MB por omisión la suite muere en un
 * archivo cualquiera, sin relación con éste. No es algo que se pueda arreglar
 * desde aquí, así que se encierra: cada caso que construye un cliente corre en
 * su propio proceso y se lleva la fuga consigo.
 */
class ContratoDelWsTitulosTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La prueba de conexión mira la etapa activa y exige credenciales antes
        // de tocar la red.
        TitulacionWsConfig::actual()->forceFill([
            'etapa_activa' => TitulacionWsConfig::ETAPA_PRUEBAS,
            'usuario_pruebas' => 'usuario',
            'password_pruebas' => 'secreto',
        ])->save();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_un_wsdl_con_las_dos_operaciones_pasa(): void
    {
        $resultado = $this->clienteCon('titulos-ok.wsdl')->probarConexion();

        $this->assertTrue($resultado['ok'], $resultado['mensaje']);
        $this->assertStringContainsString('las dos operaciones', $resultado['mensaje']);
    }

    /**
     * Y si la SEP renombró una operación, se dice cuál falta y qué sí hay.
     *
     * Lo segundo importa tanto como lo primero: sin la lista, quien lee el error
     * sabe que algo no cuadra pero no con qué reemplazarlo.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_un_wsdl_sin_la_operacion_lo_dice_con_nombres(): void
    {
        $resultado = $this->clienteCon('titulos-renombrado.wsdl')->probarConexion();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('cargaTituloElectronico', $resultado['mensaje'], 'Falta decir cuál no está.');
        $this->assertStringContainsString('cargarTituloElectronicoV2', $resultado['mensaje'], 'Falta decir qué sí ofrece.');
    }

    /** Un WSDL que no existe se reporta como problema de contacto, no de contrato. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_un_wsdl_inalcanzable_se_distingue(): void
    {
        $resultado = $this->clienteCon('no-existe.wsdl')->probarConexion();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('No se pudo contactar', $resultado['mensaje']);
    }

    /** Sin credenciales no se sale a la red: primero lo que se puede arreglar solo. */
    public function test_sin_credenciales_no_se_intenta_la_conexion(): void
    {
        TitulacionWsConfig::actual()->forceFill([
            'usuario_pruebas' => null,
            'password_pruebas' => null,
        ])->save();

        $resultado = $this->clienteCon('titulos-ok.wsdl')->probarConexion();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('Faltan usuario', $resultado['mensaje']);
    }

    private function clienteCon(string $archivo): ClienteTitulosSep
    {
        return new ClienteTitulosSep(
            modo: 'real',
            wsdlPruebas: base_path('tests/fixtures/wsdl/'.$archivo),
            wsdlProduccion: null,
            timeout: 5,
        );
    }
}
