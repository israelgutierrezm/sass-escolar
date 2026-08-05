<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ConceptoPagoController;
use App\Http\Controllers\DocumentoRequeridoController;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Que la pantalla abra. Nada más, y no es poco.
 *
 * Dos listados llevaban meses reventando con un 500 y nadie se enteró, porque un
 * `with('carreras')` o un `withCount('reglas')` sólo falla cuando alguien entra:
 * la relación se renombró en un refactor, el controlador se quedó con el nombre
 * viejo y ninguna prueba pasaba por ahí.
 *
 * Estas pruebas no miran QUÉ se muestra —de eso se encargan las suyas—, sólo que
 * el controlador arme la pantalla sin estallar. Es el error que más cuesta
 * descubrir tarde y el más barato de atrapar.
 */
class PantallasQueCarganTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** Rompió con «Call to undefined method ConceptoPago::reglas()». */
    public function test_el_catalogo_de_conceptos_de_pago_abre(): void
    {
        $this->fila('conceptos_pago', ['clave' => 'COLEG', 'nombre' => 'Colegiatura']);

        $peticion = $this->peticionDe($this->usuarioConAlcance(), '/finanzas/conceptos');
        $props = $this->propsDe(app(ConceptoPagoController::class)->index(), $peticion);

        $this->assertNotEmpty($props['conceptos']);
        $this->assertFalse($props['conceptos'][0]['en_uso'], 'Recién creado no lo usa nadie.');
    }

    /** Rompió con «Call to undefined relationship [carreras]». */
    public function test_el_catalogo_de_documentos_requeridos_abre(): void
    {
        // Los ámbitos viven en su propia tabla; aquí basta el documento.
        $this->fila('documentos_requeridos', [
            'nombre' => 'Acta de nacimiento',
            'obligatorio' => true,
        ]);

        $peticion = $this->peticionDe($this->usuarioConAlcance(), '/documentos');
        $props = $this->propsDe(app(DocumentoRequeridoController::class)->index($peticion), $peticion);

        $this->assertNotEmpty($props['documentos']);
    }
}
