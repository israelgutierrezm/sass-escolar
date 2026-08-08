<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ModoRedondeo;
use App\Models\Academico\PlanEstudio;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Qué se hace con lo que no cabe en la precisión del plan.
 *
 * Que la captura exija enteros no basta: el promedio se seguía enseñando como
 * 8.33 en un plan sin decimales. Y al redondearlo hay que decidir qué es un
 * 8.5, que no es un detalle de presentación —decide quién se titula con mención
 * y quién conserva una beca—, así que se configura.
 */
class RedondeoDeCalificacionTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** El modo de siempre: la mitad sube. */
    public function test_medio_arriba_sube_desde_el_cinco(): void
    {
        $modo = ModoRedondeo::MEDIO_ARRIBA;

        $this->assertSame(9.0, $modo->aplicar(8.5, 0));
        $this->assertSame(8.0, $modo->aplicar(8.49, 0));
        $this->assertSame(9.0, $modo->aplicar(8.9, 0));
    }

    /** Con seis-arriba, el 8.5 se queda en 8. */
    public function test_seis_arriba_no_sube_la_mitad(): void
    {
        $modo = ModoRedondeo::SEIS_ARRIBA;

        $this->assertSame(8.0, $modo->aplicar(8.5, 0));
        $this->assertSame(9.0, $modo->aplicar(8.6, 0));
        $this->assertSame(8.0, $modo->aplicar(8.59, 0));
    }

    /** Y hacia abajo no sube nunca, por alto que sea el sobrante. */
    public function test_abajo_nunca_sube(): void
    {
        $modo = ModoRedondeo::ABAJO;

        $this->assertSame(8.0, $modo->aplicar(8.99, 0));
        $this->assertSame(8.0, $modo->aplicar(8.5, 0));
        $this->assertSame(8.0, $modo->aplicar(8.0, 0));
    }

    /**
     * El redondeo respeta la precisión configurada, no sólo los enteros.
     *
     * Con un decimal, el umbral se aplica a la cifra que se descarta: 8.55 es
     * «ocho coma cinco y media décima».
     */
    public function test_el_umbral_se_aplica_en_la_cifra_que_se_corta(): void
    {
        $this->assertSame(8.6, ModoRedondeo::MEDIO_ARRIBA->aplicar(8.55, 1));
        $this->assertSame(8.5, ModoRedondeo::SEIS_ARRIBA->aplicar(8.55, 1));
        $this->assertSame(8.6, ModoRedondeo::SEIS_ARRIBA->aplicar(8.56, 1));
        $this->assertSame(8.5, ModoRedondeo::ABAJO->aplicar(8.59, 1));
    }

    /**
     * Un valor que ya cabe no se toca.
     *
     * Suena obvio y es justo lo que rompe un redondeo mal escrito: sumar o
     * restar una unidad a lo que ya estaba exacto.
     */
    public function test_lo_que_ya_cabe_se_queda_igual(): void
    {
        foreach (ModoRedondeo::cases() as $modo) {
            $this->assertSame(8.0, $modo->aplicar(8.0, 0), $modo->value);
            $this->assertSame(8.5, $modo->aplicar(8.5, 1), $modo->value);
            $this->assertSame(10.0, $modo->aplicar(10.0, 0), $modo->value);
        }
    }

    /**
     * El 8.5 sube aunque en coma flotante no valga 8.5.
     *
     * Escalado puede valer 8.4999999999, y sin tolerancia el promedio se
     * quedaría en 8: un punto entero perdido por el último bit de un float.
     * Estos son los valores que de verdad se tuercen al multiplicar.
     */
    public function test_la_coma_flotante_no_baja_un_promedio(): void
    {
        $this->assertSame(2.68, ModoRedondeo::MEDIO_ARRIBA->aplicar(2.675, 2));
        $this->assertSame(1.02, ModoRedondeo::MEDIO_ARRIBA->aplicar(1.015, 2));
        $this->assertSame(8.35, ModoRedondeo::MEDIO_ARRIBA->aplicar(8.345, 2));
    }

    /** Por omisión se redondea como siempre se hizo: medio arriba. */
    public function test_el_plan_redondea_medio_arriba_si_no_se_configura(): void
    {
        $plan = $this->plan(decimales: 0);

        $this->assertSame(ModoRedondeo::MEDIO_ARRIBA, $plan->modoRedondeo());
        $this->assertSame(9.0, $plan->redondear(8.5));
    }

    /** Y el plan aplica su propia precisión y su propio modo. */
    public function test_el_plan_manda_en_precision_y_en_modo(): void
    {
        $plan = $this->plan(decimales: 0, modo: ModoRedondeo::SEIS_ARRIBA);

        $this->assertSame(8.0, $plan->redondear(8.5));
        $this->assertSame(9.0, $plan->redondear(8.6));
    }

    /**
     * Sin promedio, sigue sin haberlo.
     *
     * Convertir el `null` en 0.0 le inventaría un reprobado a quien todavía no
     * ha cursado nada, y ese cero se vería igual que uno real.
     */
    public function test_sin_promedio_no_se_inventa_un_cero(): void
    {
        $plan = $this->plan(decimales: 0);

        $this->assertNull($plan->redondear(null));
        $this->assertNull(PlanEstudio::redondearCon(null, null));
        $this->assertNull(PlanEstudio::redondearCon($plan, null));
    }

    /**
     * Sin plan se conserva lo que el sistema hacía antes.
     *
     * Que falte una relación no debe cambiarle el promedio a nadie.
     */
    public function test_sin_plan_se_redondea_como_antes(): void
    {
        $this->assertSame(8.33, PlanEstudio::redondearCon(null, 8.333));
        $this->assertSame(8.34, PlanEstudio::redondearCon(null, 8.335));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function plan(int $decimales, ?ModoRedondeo $modo = null): PlanEstudio
    {
        $escuela = $this->alumnoInscrito();

        $plan = PlanEstudio::findOrFail($escuela['plan']);

        $plan->update(array_filter([
            'decimales_calificacion' => $decimales,
            'redondeo_calificacion' => $modo?->value,
        ], fn ($v) => $v !== null));

        return $plan->fresh();
    }
}
