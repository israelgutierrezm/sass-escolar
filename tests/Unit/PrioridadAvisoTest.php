<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PrioridadAviso;
use PHPUnit\Framework\TestCase;

/**
 * El contrato del que cuelga toda la presentación de un aviso.
 *
 * Que sólo el crítico exija confirmación no es un detalle de estilo: es lo que
 * decide qué bloquea la pantalla y qué deja constancia. Si alguien marcara
 * también el importante, todos los avisos empezarían a interrumpir y la
 * interrupción dejaría de significar algo.
 */
class PrioridadAvisoTest extends TestCase
{
    public function test_solo_el_critico_exige_confirmacion(): void
    {
        $this->assertTrue(PrioridadAviso::Critico->exigeConfirmacion());
        $this->assertFalse(PrioridadAviso::Importante->exigeConfirmacion());
        $this->assertFalse(PrioridadAviso::Informativo->exigeConfirmacion());
    }

    public function test_son_tres_y_cada_una_se_explica(): void
    {
        $casos = PrioridadAviso::cases();

        $this->assertCount(3, $casos, 'Con más niveles, quien publica deja de saber cuál elegir.');

        foreach ($casos as $prioridad) {
            $this->assertNotSame('', $prioridad->etiqueta());
            // La descripción es lo que se lee al elegir: sin ella, el nombre
            // solo no dice qué le hace el aviso a quien lo recibe.
            $this->assertNotSame('', $prioridad->descripcion());
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $prioridad->color());
        }
    }

    public function test_el_selector_lleva_todo_lo_que_la_pantalla_necesita(): void
    {
        foreach (PrioridadAviso::paraSelector() as $fila) {
            $this->assertSame(['valor', 'texto', 'descripcion', 'color'], array_keys($fila));
        }
    }
}
