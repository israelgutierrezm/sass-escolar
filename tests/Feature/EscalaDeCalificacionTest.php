<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\PlanEstudio;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Con qué precisión se califica.
 *
 * La escala del plan decía de cuánto a cuánto pero no con cuántos decimales, y
 * `numeric` acepta un 8.756. Un acta con eso no la rechaza nadie: se guarda, se
 * imprime y aparece en el historial académico.
 *
 * Las reglas viven en el plan porque son DOS los sitios que capturan
 * calificaciones —la del docente y el historial académico a mano— y a los dos se les había
 * escapado lo mismo. Esta prueba es sobre la regla, no sobre cada pantalla:
 * mientras las dos la pidan al plan, no pueden discrepar.
 */
class EscalaDeCalificacionTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** Con cero decimales, un 8.5 no pasa. */
    public function test_un_plan_de_enteros_rechaza_los_decimales(): void
    {
        $plan = $this->plan(decimales: 0);

        $this->assertFalse($this->pasa($plan, 8.5));
        $this->assertTrue($this->pasa($plan, 8));
    }

    /** Con un decimal, 8.5 sí y 8.75 no. */
    public function test_un_plan_de_un_decimal_rechaza_dos(): void
    {
        $plan = $this->plan(decimales: 1);

        $this->assertTrue($this->pasa($plan, 8.5));
        $this->assertFalse($this->pasa($plan, 8.75));
    }

    /** Y con dos, se acepta 8.75 pero no 8.756. */
    public function test_dos_decimales_es_el_limite(): void
    {
        $plan = $this->plan(decimales: 2);

        $this->assertTrue($this->pasa($plan, 8.75));
        $this->assertFalse($this->pasa($plan, 8.756));
    }

    /** Y tres, que es el máximo que se puede configurar. */
    public function test_tres_decimales_es_el_maximo(): void
    {
        $plan = $this->plan(decimales: 3);

        $this->assertTrue($this->pasa($plan, 8.756));
        $this->assertFalse($this->pasa($plan, 8.7561));
    }

    /**
     * La escala se sigue respetando, no sólo la precisión.
     *
     * Es lo que ya funcionaba y no debe romperse al agregar los decimales.
     */
    public function test_la_escala_sigue_acotando(): void
    {
        $plan = $this->plan(decimales: 1, minima: 5, maxima: 10);

        $this->assertFalse($this->pasa($plan, 4.5), 'Por debajo de la mínima.');
        $this->assertFalse($this->pasa($plan, 10.5), 'Por encima de la máxima.');
        $this->assertTrue($this->pasa($plan, 7.5));
    }

    /**
     * Sin plan se cae a lo más permisivo.
     *
     * Una materia sin plan no debería existir, pero el código que la lee ya
     * contemplaba el caso: rechazar una captura porque falta una relación sería
     * castigar a quien califica por un problema de catálogo.
     */
    public function test_sin_plan_no_se_bloquea_la_captura(): void
    {
        $reglas = PlanEstudio::reglasPara(null);

        $this->assertTrue($this->conReglas($reglas, 8.756));
    }

    /** Y el texto que se le enseña a quien captura dice la precisión real. */
    public function test_dice_como_se_califica(): void
    {
        $this->assertStringContainsString('enteros', $this->plan(decimales: 0)->comoSeCalifica());
        $this->assertStringContainsString('un decimal', $this->plan(decimales: 1)->comoSeCalifica());
        $this->assertStringContainsString('2 decimales', $this->plan(decimales: 2)->comoSeCalifica());
        $this->assertStringContainsString('3 decimales', $this->plan(decimales: 3)->comoSeCalifica());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function pasa(PlanEstudio $plan, float|int $calificacion): bool
    {
        return $this->conReglas($plan->reglasDeCalificacion(), $calificacion);
    }

    /** @param  array<int, string>  $reglas */
    private function conReglas(array $reglas, float|int $calificacion): bool
    {
        return ! Validator::make(
            // Como string: es lo que llega de un formulario, y `decimal` mira
            // los decimales que se ESCRIBIERON, no los que sobreviven al float.
            ['calificacion' => (string) $calificacion],
            ['calificacion' => $reglas],
        )->fails();
    }

    private function plan(int $decimales, float $minima = 0, float $maxima = 10): PlanEstudio
    {
        $escuela = $this->alumnoInscrito();

        $plan = PlanEstudio::findOrFail($escuela['plan']);

        $plan->update([
            'calificacion_minima' => $minima,
            'calificacion_maxima' => $maxima,
            'calificacion_minima_aprobatoria' => $minima + 6 > $maxima ? $maxima : $minima + 6,
            'decimales_calificacion' => $decimales,
        ]);

        return $plan->fresh();
    }
}
