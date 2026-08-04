<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Finanzas\ReglaRecargo;
use PHPUnit\Framework\TestCase;

/**
 * Cuánto recarga una regla de mora.
 *
 * La aritmética del recargo es de las pocas cosas del sistema que le cuestan
 * dinero real a una familia, y encima se ejecuta sola en un barrido: nadie mira
 * cada cifra antes de que se cobre. Aquí se fija cuánto sale en cada modo, para
 * que un cambio de fórmula no pase inadvertido.
 */
class ReglaRecargoTest extends TestCase
{
    public function test_el_porcentaje_se_calcula_sobre_la_base(): void
    {
        $regla = $this->regla(ReglaRecargo::MODO_PORCENTAJE, 0.10);

        $this->assertSame(100.0, $regla->calcular(1000));
    }

    public function test_el_monto_fijo_no_depende_de_la_base(): void
    {
        $regla = $this->regla(ReglaRecargo::MODO_MONTO_FIJO, 150);

        $this->assertSame(150.0, $regla->calcular(1000));
        $this->assertSame(150.0, $regla->calcular(50));
    }

    /**
     * En aplicación única, los periodos de atraso no multiplican: la regla dice
     * «una vez al vencer» y da igual que lleve seis meses.
     */
    public function test_la_regla_unica_no_se_multiplica_por_los_meses(): void
    {
        $regla = $this->regla(ReglaRecargo::MODO_MONTO_FIJO, 150, ReglaRecargo::FRECUENCIA_UNICA);

        $this->assertSame(150.0, $regla->calcular(1000, periodos: 6));
    }

    public function test_la_mensual_acumulativa_cobra_por_cada_mes(): void
    {
        $regla = $this->regla(ReglaRecargo::MODO_MONTO_FIJO, 150, ReglaRecargo::FRECUENCIA_MENSUAL);

        $this->assertSame(450.0, $regla->calcular(1000, periodos: 3));
    }

    /** Ni con cero ni con números negativos de periodos se deja de cobrar el primero. */
    public function test_siempre_cobra_al_menos_un_periodo(): void
    {
        $regla = $this->regla(ReglaRecargo::MODO_MONTO_FIJO, 150, ReglaRecargo::FRECUENCIA_MENSUAL);

        $this->assertSame(150.0, $regla->calcular(1000, periodos: 0));
    }

    /**
     * El tope es lo que impide que un adeudo olvidado durante dos años acabe
     * costando más de recargos que de colegiatura.
     */
    public function test_el_tope_corta_el_acumulado(): void
    {
        $regla = $this->regla(ReglaRecargo::MODO_MONTO_FIJO, 150, ReglaRecargo::FRECUENCIA_MENSUAL);
        $regla->tope_monto = 400;

        $this->assertSame(400.0, $regla->calcular(1000, periodos: 10));
    }

    public function test_el_tope_no_estorba_cuando_no_se_alcanza(): void
    {
        $regla = $this->regla(ReglaRecargo::MODO_PORCENTAJE, 0.05);
        $regla->tope_monto = 500;

        $this->assertSame(50.0, $regla->calcular(1000));
    }

    private function regla(string $modo, float $valor, string $frecuencia = ReglaRecargo::FRECUENCIA_UNICA): ReglaRecargo
    {
        return new ReglaRecargo([
            'modo' => $modo,
            'valor' => $valor,
            'frecuencia' => $frecuencia,
            'dias_gracia' => 0,
            'activo' => true,
        ]);
    }
}
