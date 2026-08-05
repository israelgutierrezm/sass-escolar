<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\BecaController;
use App\Http\Controllers\ConceptoPagoController;
use App\Http\Controllers\DescuentoController;
use App\Models\Finanzas\Descuento;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Buscar y filtrar en los catálogos de finanzas.
 *
 * Conceptos, descuentos, becas, planes y razones sociales se listaban enteros,
 * sin buscador ni filtros. Con diez conceptos se lee; a los tres años de una
 * escuela son cincuenta —colegiatura, inscripción, exámenes, constancias,
 * credenciales, cada uno con sus variantes— y encontrar uno es recorrer la lista
 * con el dedo.
 *
 * El filtrado va en la CONSULTA, no en el navegador: filtrar en el cliente
 * obliga a traerlo todo para esconder la mayoría, y deja de funcionar justo
 * cuando el catálogo es grande, que es cuando hace falta.
 */
class FiltrosDeFinanzasTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_los_conceptos_se_buscan_por_nombre_clave_o_clave_del_sat(): void
    {
        $this->fila('conceptos_pago', ['clave' => 'COLEG', 'nombre' => 'Colegiatura', 'clave_sat' => '86121600']);
        $this->fila('conceptos_pago', ['clave' => 'CRED', 'nombre' => 'Credencial', 'clave_sat' => '60121200']);

        $this->assertSame(['Colegiatura'], $this->conceptos(['busqueda' => 'coleg']));
        $this->assertSame(['Credencial'], $this->conceptos(['busqueda' => '60121200']), 'También por la clave del SAT.');
        $this->assertCount(2, $this->conceptos([]), 'Sin búsqueda salen todos.');
    }

    /** El IVA decide cómo sale el concepto en el CFDI: es lo que se filtra. */
    public function test_los_conceptos_se_acotan_a_los_que_causan_iva(): void
    {
        $this->fila('conceptos_pago', ['clave' => 'COLEG', 'nombre' => 'Colegiatura', 'gravado' => false]);
        $this->fila('conceptos_pago', ['clave' => 'CRED', 'nombre' => 'Credencial', 'gravado' => true]);

        $this->assertSame(['Credencial'], $this->conceptos(['gravado' => 1]));
    }

    public function test_los_descuentos_se_acotan_por_tipo(): void
    {
        $this->descuento('PRONTO', 'Pronto pago', Descuento::TIPO_PAGO_ANTICIPADO);
        $this->descuento('VERANO', 'Campaña de verano', Descuento::TIPO_CAMPANA);

        $nombres = $this->props(app(DescuentoController::class), '/finanzas/descuentos', ['tipo' => Descuento::TIPO_CAMPANA])['descuentos'];

        $this->assertSame(['Campaña de verano'], collect($nombres)->pluck('nombre')->all());
    }

    /**
     * El catálogo acumula las becas de convocatorias pasadas —no se borran, son
     * historia de lo que se otorgó— y entierran a las que siguen vivas.
     */
    public function test_las_becas_se_acotan_a_las_activas(): void
    {
        $this->fila('becas', ['clave' => 'EXC', 'nombre' => 'Excelencia', 'modo' => 'porcentaje', 'valor' => 50, 'activo' => true]);
        $this->fila('becas', ['clave' => 'VIEJA', 'nombre' => 'Convocatoria 2019', 'modo' => 'porcentaje', 'valor' => 30, 'activo' => false]);

        $becas = $this->props(app(BecaController::class), '/finanzas/becas', ['activo' => 1])['becas'];

        $this->assertSame(['Excelencia'], collect($becas)->pluck('nombre')->all());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, string>
     */
    private function conceptos(array $filtros): array
    {
        $props = $this->props(app(ConceptoPagoController::class), '/finanzas/conceptos', $filtros);

        return collect($props['conceptos'])->pluck('nombre')->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function props(object $controlador, string $url, array $filtros): array
    {
        $peticion = $this->peticionDe($this->usuarioConAlcance(), $url, $filtros);

        return $this->propsDe($controlador->index($peticion), $peticion);
    }

    private function descuento(string $clave, string $nombre, string $tipo): void
    {
        $this->fila('descuentos', [
            'clave' => $clave,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'modo' => 'porcentaje',
            'valor' => 10,
            'activo' => true,
        ]);
    }
}
