<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\ReglaRecargo;
use App\Services\CalculadorRecargos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * El recargo por mora, que se cobra solo.
 *
 * Corre en un barrido sin que nadie mire cifra por cifra, así que un error aquí
 * no lo detecta una persona: lo detecta una familia en su estado de cuenta. Lo
 * que se comprueba es sobre todo lo que NO debe pasar —recargar antes de
 * tiempo, recargar dos veces, componer el recargo sobre sí mismo—.
 */
class CalculadorRecargosTest extends TenantTestCase
{
    private CalculadorRecargos $calculador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculador = app(CalculadorRecargos::class);
    }

    public function test_no_recarga_antes_del_vencimiento(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_PORCENTAJE, 0.10);

        $this->assertSame(0.0, $this->recargo($adeudo, '2026-08-09'));
    }

    public function test_recarga_pasado_el_vencimiento(): void
    {
        $adeudo = $this->adeudo(monto: 2000, vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_PORCENTAJE, 0.10);

        $this->assertSame(200.0, $this->recargo($adeudo, '2026-08-11'));
    }

    /** El día del vencimiento todavía no es mora: se paga ese mismo día. */
    public function test_el_dia_del_vencimiento_no_recarga(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100);

        $this->assertSame(0.0, $this->recargo($adeudo, '2026-08-10'));
    }

    public function test_los_dias_de_gracia_corren_la_mora(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100, diasGracia: 5);

        $this->assertSame(0.0, $this->recargo($adeudo, '2026-08-15'), 'Último día de gracia.');
        $this->assertSame(100.0, $this->recargo($adeudo, '2026-08-16'));
    }

    /**
     * Un plan que no permite recargos no recarga nada, aunque la línea suelta
     * venga marcada: el plan manda.
     */
    public function test_un_plan_sin_recargos_no_recarga_aunque_la_linea_lo_pida(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100);

        $adeudo->conceptoPlan->plan->update(['aplica_recargos' => false]);

        $this->assertSame(0.0, $this->recargo($adeudo->fresh(['conceptoPlan.plan']), '2026-09-01'));
    }

    public function test_una_linea_sin_recargos_no_recarga(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100);

        $adeudo->conceptoPlan->update(['aplica_recargos' => false]);

        $this->assertSame(0.0, $this->recargo($adeudo->fresh(['conceptoPlan.plan']), '2026-09-01'));
    }

    public function test_sin_regla_configurada_no_hay_recargo(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');

        $this->assertSame(0.0, $this->recargo($adeudo, '2026-09-01'));
    }

    public function test_un_cargo_pagado_ya_no_recarga(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100);

        $adeudo->update(['estatus' => Adeudo::ESTATUS_PAGADO]);

        $this->assertSame(0.0, $this->recargo($adeudo->fresh(['conceptoPlan.plan']), '2026-09-01'));
    }

    /**
     * El recargo se calcula sobre lo que se debe, no sobre el monto original:
     * quien abonó la mitad no debe recargar por el total.
     */
    public function test_el_recargo_se_calcula_sobre_el_capital_pendiente(): void
    {
        $adeudo = $this->adeudo(monto: 2000, vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_PORCENTAJE, 0.10);

        // 500 de descuento deja 1500 de capital.
        AdeudoAjuste::create([
            'adeudo_id' => $adeudo->id,
            'tipo' => AdeudoAjuste::TIPO_DESCUENTO,
            'etiqueta' => 'Descuento',
            'monto' => -500,
        ]);
        $adeudo->update(['monto_descuentos' => 500]);

        $this->assertSame(150.0, $this->recargo($adeudo->fresh(['conceptoPlan.plan']), '2026-08-11'));
    }

    /**
     * El error clásico de estos motores: recargar sobre el total, que ya trae el
     * recargo anterior, y hacer crecer la deuda sola con cada barrido.
     */
    public function test_recalcular_dos_veces_no_infla_la_deuda(): void
    {
        $adeudo = $this->adeudo(monto: 1000, vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_PORCENTAJE, 0.10);

        $hoy = CarbonImmutable::parse('2026-08-11');

        $this->assertTrue($this->calculador->recalcular($adeudo->fresh(['conceptoPlan.plan']), $hoy));

        $tras = $adeudo->fresh();
        $this->assertSame('100.00', $tras->monto_recargos);
        $this->assertSame('1100.00', $tras->monto_total);

        // Segundo barrido el mismo día: no cambia nada y no duplica el ajuste.
        $this->assertFalse($this->calculador->recalcular($tras->fresh(['conceptoPlan.plan']), $hoy));

        $this->assertSame('100.00', $adeudo->fresh()->monto_recargos);
        $this->assertSame('1100.00', $adeudo->fresh()->monto_total);
        $this->assertSame(1, AdeudoAjuste::where('adeudo_id', $adeudo->id)->where('tipo', AdeudoAjuste::TIPO_RECARGO)->count());
    }

    /** Si el atraso crece, el recargo se rehace entero; no se suma sobre el anterior. */
    public function test_la_mensual_acumulativa_se_recalcula_entera(): void
    {
        $adeudo = $this->adeudo(monto: 1000, vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100, frecuencia: ReglaRecargo::FRECUENCIA_MENSUAL);

        $this->calculador->recalcular($adeudo->fresh(['conceptoPlan.plan']), CarbonImmutable::parse('2026-08-20'));
        $this->assertSame('100.00', $adeudo->fresh()->monto_recargos, 'Primer mes de atraso.');

        $this->calculador->recalcular($adeudo->fresh(['conceptoPlan.plan']), CarbonImmutable::parse('2026-10-20'));
        $this->assertSame('300.00', $adeudo->fresh()->monto_recargos, 'Tres meses, no 100 + 300.');
        $this->assertSame('1300.00', $adeudo->fresh()->monto_total);
    }

    /** Al ponerse al corriente, el recargo desaparece junto con su ajuste. */
    public function test_si_deja_de_aplicar_el_recargo_se_quita(): void
    {
        $adeudo = $this->adeudo(monto: 1000, vence: '2026-08-10');
        $regla = $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100);

        $this->calculador->recalcular($adeudo->fresh(['conceptoPlan.plan']), CarbonImmutable::parse('2026-08-20'));
        $this->assertSame('100.00', $adeudo->fresh()->monto_recargos);

        $regla->update(['activo' => false]);

        $this->assertTrue($this->calculador->recalcular($adeudo->fresh(['conceptoPlan.plan']), CarbonImmutable::parse('2026-08-20')));
        $this->assertSame('0.00', $adeudo->fresh()->monto_recargos);
        $this->assertSame('1000.00', $adeudo->fresh()->monto_total);
        $this->assertSame(0, AdeudoAjuste::where('adeudo_id', $adeudo->id)->where('tipo', AdeudoAjuste::TIPO_RECARGO)->count());
    }

    /** La regla del concepto gana sobre la general del plan. */
    public function test_el_override_por_concepto_manda_sobre_la_del_plan(): void
    {
        $adeudo = $this->adeudo(monto: 1000, vence: '2026-08-10');

        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 500, general: true);
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 50);

        $this->assertSame(50.0, $this->recargo($adeudo->fresh(['conceptoPlan.plan']), '2026-08-11'));
    }

    /** Con el modo «día siguiente», la mora arranca un día después. */
    public function test_el_modo_de_fecha_limite_corre_el_inicio_de_la_mora(): void
    {
        $adeudo = $this->adeudo(vence: '2026-08-10');
        $this->regla($adeudo, ReglaRecargo::MODO_MONTO_FIJO, 100);

        $adeudo->conceptoPlan->plan->update(['fecha_limite_modo' => PlanCobro::LIMITE_DIA_SIGUIENTE]);

        $this->assertSame(0.0, $this->recargo($adeudo->fresh(['conceptoPlan.plan']), '2026-08-11'));
        $this->assertSame(100.0, $this->recargo($adeudo->fresh(['conceptoPlan.plan']), '2026-08-12'));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function recargo(Adeudo $adeudo, string $hoy): float
    {
        return $this->calculador->recargoPara($adeudo, CarbonImmutable::parse($hoy));
    }

    private function adeudo(float $monto = 1000, string $vence = '2026-08-10'): Adeudo
    {
        $plan = PlanCobro::create([
            'nombre' => 'Plan de pruebas',
            'vigente_desde' => '2026-01-01',
            'aplica_recargos' => true,
            'fecha_limite_modo' => PlanCobro::LIMITE_EXACTA,
        ]);

        // El concepto de pago es sólo el catálogo al que apunta la línea; no
        // tiene reglas propias, así que basta con que exista.
        $conceptoId = DB::table('conceptos_pago')->insertGetId([
            'clave' => 'COL-'.uniqid(),
            'nombre' => 'Colegiatura',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $linea = ConceptoPlan::create([
            'plan_cobro_id' => $plan->id,
            'concepto_id' => $conceptoId,
            'monto' => $monto,
            'aplica_recargos' => true,
        ]);

        $adeudo = Adeudo::create([
            // La tabla exige un titular —alumno o aspirante, exactamente uno—.
            // Se usa un aspirante porque es el más barato de armar: sólo
            // persona y situación, sin oferta ni matrícula de por medio.
            'aspirante_id' => $this->aspirante(),
            'concepto_id' => $conceptoId,
            'concepto_plan_id' => $linea->id,
            'monto' => $monto,
            'monto_descuentos' => 0,
            'monto_recargos' => 0,
            'monto_total' => $monto,
            'fecha_generacion' => '2026-08-01',
            'fecha_vencimiento' => $vence,
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);

        return $adeudo->load('conceptoPlan.plan');
    }

    /** Un titular cualquiera para el cargo; lo que se prueba no depende de quién sea. */
    private function aspirante(): int
    {
        $personaId = DB::table('personas')->insertGetId([
            'nombre' => 'Titular',
            'primer_apellido' => 'De prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $situacionId = DB::table('situaciones_aspirante')->value('id')
            ?? DB::table('situaciones_aspirante')->insertGetId([
                'clave' => 'prueba',
                'nombre' => 'De prueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return DB::table('aspirantes')->insertGetId([
            'persona_id' => $personaId,
            'situacion_id' => $situacionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function regla(
        Adeudo $adeudo,
        string $modo,
        float $valor,
        string $frecuencia = ReglaRecargo::FRECUENCIA_UNICA,
        int $diasGracia = 0,
        bool $general = false,
    ): ReglaRecargo {
        return ReglaRecargo::create([
            'plan_cobro_id' => $adeudo->conceptoPlan->plan_cobro_id,
            // Sin concepto es la regla general del plan; con él, el override.
            'concepto_plan_id' => $general ? null : $adeudo->concepto_plan_id,
            'modo' => $modo,
            'valor' => $valor,
            'frecuencia' => $frecuencia,
            'dias_gracia' => $diasGracia,
            'activo' => true,
        ]);
    }
}
