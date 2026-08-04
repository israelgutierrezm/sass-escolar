<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\BecaAlumnoMovimiento;
use App\Services\EvaluadorBecas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Cuándo se cae una beca.
 *
 * Es dinero de una familia y se decide en un proceso automático: nadie revisa
 * caso por caso antes de que el descuento desaparezca. Las dos reglas que se
 * comprueban aquí son las que separan «suspender un cargo» de «perder la beca»,
 * y la que impide castigar a quien no debía.
 */
class EvaluadorBecasTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private EvaluadorBecas $evaluador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluador = app(EvaluadorBecas::class);
    }

    // ── Atrasos ────────────────────────────────────────────────────────────

    public function test_dentro_de_la_tolerancia_no_se_castiga(): void
    {
        [$becaAlumno, $adeudo] = $this->conBeca(['dias_tolerancia' => 5, 'efecto_atraso' => Beca::ATRASO_SUSPENDE_PERIODO]);

        $resultado = $this->evaluador->evaluarAtrasos(CarbonImmutable::parse('2026-08-14'));

        $this->assertSame(['suspendidas' => 0, 'perdidas' => 0], $resultado);
        $this->assertSame(BecaAlumno::ACTIVA, $becaAlumno->fresh()->estatus);
        $this->assertSame('700.00', $adeudo->fresh()->monto_total, 'El descuento sigue aplicado.');
    }

    /**
     * Suspender es de un cargo, no de la beca: el alumno paga completo ESE mes y
     * conserva el descuento para los siguientes. Confundirlo con perderla sería
     * castigar un retraso como si fuera una falta grave.
     */
    public function test_el_atraso_que_suspende_cobra_completo_ese_cargo_y_conserva_la_beca(): void
    {
        [$becaAlumno, $adeudo] = $this->conBeca(['dias_tolerancia' => 3, 'efecto_atraso' => Beca::ATRASO_SUSPENDE_PERIODO]);

        $resultado = $this->evaluador->evaluarAtrasos(CarbonImmutable::parse('2026-08-20'));

        $this->assertSame(1, $resultado['suspendidas']);
        $this->assertSame(0, $resultado['perdidas']);

        $tras = $adeudo->fresh();
        $this->assertSame('0.00', $tras->monto_descuentos);
        $this->assertSame('1000.00', $tras->monto_total, 'Se cobra completo.');
        $this->assertSame(BecaAlumno::ACTIVA, $becaAlumno->fresh()->estatus, 'La beca sigue viva.');

        $this->assertSame(
            BecaAlumnoMovimiento::SUSPENDIDA,
            BecaAlumnoMovimiento::where('beca_alumno_id', $becaAlumno->id)->value('accion'),
        );
    }

    public function test_el_atraso_que_hace_perder_la_beca_la_da_de_baja(): void
    {
        [$becaAlumno] = $this->conBeca(['dias_tolerancia' => 0, 'efecto_atraso' => Beca::ATRASO_PIERDE]);

        $resultado = $this->evaluador->evaluarAtrasos(CarbonImmutable::parse('2026-08-20'));

        $this->assertSame(1, $resultado['perdidas']);

        $tras = $becaAlumno->fresh();
        $this->assertSame(BecaAlumno::PERDIDA, $tras->estatus);
        $this->assertNotNull($tras->vigente_hasta, 'Queda la fecha en que dejó de valer.');
    }

    /** Una beca que no pide puntualidad no se toca por un retraso. */
    public function test_la_beca_que_no_exige_pago_puntual_sobrevive_al_atraso(): void
    {
        [$becaAlumno, $adeudo] = $this->conBeca([
            'requiere_pago_puntual' => false,
            'efecto_atraso' => Beca::ATRASO_PIERDE,
        ]);

        $resultado = $this->evaluador->evaluarAtrasos(CarbonImmutable::parse('2026-09-30'));

        $this->assertSame(['suspendidas' => 0, 'perdidas' => 0], $resultado);
        $this->assertSame(BecaAlumno::ACTIVA, $becaAlumno->fresh()->estatus);
        $this->assertSame('700.00', $adeudo->fresh()->monto_total);
    }

    /** Un cargo pagado, aunque se pagara tarde, ya no está vencido. */
    public function test_un_cargo_ya_pagado_no_castiga_la_beca(): void
    {
        [$becaAlumno, $adeudo] = $this->conBeca(['dias_tolerancia' => 0, 'efecto_atraso' => Beca::ATRASO_PIERDE]);

        $adeudo->update(['estatus' => Adeudo::ESTATUS_PAGADO]);

        $this->evaluador->evaluarAtrasos(CarbonImmutable::parse('2026-09-30'));

        $this->assertSame(BecaAlumno::ACTIVA, $becaAlumno->fresh()->estatus);
    }

    // ── Renovación por promedio ────────────────────────────────────────────

    /**
     * Renovar sola una beca sin que nadie la autorice sería regalar dinero de la
     * escuela: quien cumple queda «por renovar», esperando el visto bueno.
     */
    public function test_quien_alcanza_el_promedio_queda_por_renovar(): void
    {
        [$becaAlumno, , $ciclo, $matricula] = $this->conBecaDeCiclo(['promedio_minimo' => 8.0]);

        $resultado = $this->evaluador->evaluarRenovacion($ciclo, [$matricula => 9.1]);

        $this->assertSame(1, $resultado['por_renovar']);

        $tras = $becaAlumno->fresh();
        $this->assertSame(BecaAlumno::POR_RENOVAR, $tras->estatus);
        $this->assertSame('9.10', $tras->promedio_evaluado);

        // Como POR_RENOVAR y no como suspendida: el alumno cumplió y está
        // esperando trámite, no cumpliendo un castigo.
        $this->assertSame(
            BecaAlumnoMovimiento::POR_RENOVAR,
            BecaAlumnoMovimiento::where('beca_alumno_id', $becaAlumno->id)->value('accion'),
        );
    }

    public function test_quien_no_alcanza_el_promedio_no_se_renueva(): void
    {
        [$becaAlumno, , $ciclo, $matricula] = $this->conBecaDeCiclo([
            'promedio_minimo' => 8.0,
            'efecto_promedio' => Beca::PROMEDIO_NO_RENUEVA,
        ]);

        $resultado = $this->evaluador->evaluarRenovacion($ciclo, [$matricula => 7.4]);

        $this->assertSame(1, $resultado['no_renovadas']);
        $this->assertSame(BecaAlumno::PERDIDA, $becaAlumno->fresh()->estatus);
        $this->assertSame(
            BecaAlumnoMovimiento::NO_RENOVADA,
            BecaAlumnoMovimiento::where('beca_alumno_id', $becaAlumno->id)->value('accion'),
        );
    }

    /**
     * Al perderla se guarda el promedio ANTES de darla de baja: es la evidencia
     * de por qué se le quitó, y después nadie podría reconstruirla.
     */
    public function test_al_perderla_por_promedio_queda_el_numero_que_lo_motivo(): void
    {
        [$becaAlumno, , $ciclo, $matricula] = $this->conBecaDeCiclo([
            'promedio_minimo' => 8.0,
            'efecto_promedio' => Beca::PROMEDIO_PIERDE,
        ]);

        $this->evaluador->evaluarRenovacion($ciclo, [$matricula => 6.5]);

        $tras = $becaAlumno->fresh();
        $this->assertSame(BecaAlumno::PERDIDA, $tras->estatus);
        $this->assertSame('6.50', $tras->promedio_evaluado);
    }

    /** Sin promedio no se castiga a nadie: no hay con qué juzgarlo. */
    public function test_sin_promedio_del_ciclo_no_se_pierde_la_beca(): void
    {
        [$becaAlumno, , $ciclo] = $this->conBecaDeCiclo([
            'promedio_minimo' => 8.0,
            'efecto_promedio' => Beca::PROMEDIO_PIERDE,
        ]);

        $resultado = $this->evaluador->evaluarRenovacion($ciclo, []);

        $this->assertSame(1, $resultado['por_renovar']);
        $this->assertSame(BecaAlumno::POR_RENOVAR, $becaAlumno->fresh()->estatus);
    }

    public function test_la_beca_que_no_requiere_renovacion_no_se_evalua(): void
    {
        [$becaAlumno, , $ciclo, $matricula] = $this->conBecaDeCiclo([
            'requiere_renovacion' => false,
            'promedio_minimo' => 8.0,
            'efecto_promedio' => Beca::PROMEDIO_PIERDE,
        ]);

        $resultado = $this->evaluador->evaluarRenovacion($ciclo, [$matricula => 5.0]);

        $this->assertSame(['por_renovar' => 0, 'no_renovadas' => 0, 'perdidas' => 0], $resultado);
        $this->assertSame(BecaAlumno::ACTIVA, $becaAlumno->fresh()->estatus);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Una beca activa con un cargo vencido el 2026-08-10 que ya trae su
     * descuento aplicado (1000 con 300 de beca = 700 a pagar).
     *
     * @return array{0: BecaAlumno, 1: Adeudo}
     */
    private function conBeca(array $reglas = []): array
    {
        [$becaAlumno, , , $matricula] = $this->conBecaDeCiclo($reglas);

        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula,
            'concepto_id' => $this->conceptoDePago(),
            'monto' => 1000,
            'monto_descuentos' => 300,
            'monto_recargos' => 0,
            'monto_total' => 700,
            'fecha_generacion' => '2026-08-01',
            'fecha_vencimiento' => '2026-08-10',
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);

        // El ajuste es lo que ata el descuento a ESTA beca: sin él, el evaluador
        // no sabría a quién castigar.
        AdeudoAjuste::create([
            'adeudo_id' => $adeudo->id,
            'tipo' => AdeudoAjuste::TIPO_BECA,
            'origen_id' => $becaAlumno->id,
            'etiqueta' => 'Beca',
            'monto' => -300,
        ]);

        return [$becaAlumno, $adeudo->load('ajustes')];
    }

    /**
     * @return array{0: BecaAlumno, 1: Beca, 2: Ciclo, 3: int}
     */
    private function conBecaDeCiclo(array $reglas = []): array
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = Ciclo::findOrFail($this->cicloDePrueba());

        $beca = Beca::create([
            'clave' => 'BEC-'.uniqid(),
            'nombre' => 'Beca de prueba',
            'modo' => Beca::MODO_PORCENTAJE,
            'valor' => 0.30,
            'requiere_pago_puntual' => true,
            'dias_tolerancia' => 0,
            'efecto_atraso' => Beca::ATRASO_NINGUNO,
            'requiere_renovacion' => true,
            'efecto_promedio' => Beca::PROMEDIO_NINGUNO,
            'activo' => true,
            ...$reglas,
        ]);

        $becaAlumno = BecaAlumno::create([
            'matricula_oferta_id' => $escuela['matricula'],
            'beca_id' => $beca->id,
            'ciclo_id' => $ciclo->id,
            'estatus' => BecaAlumno::ACTIVA,
            'vigente_desde' => '2026-01-01',
        ]);

        return [$becaAlumno, $beca, $ciclo, $escuela['matricula']];
    }

    private function conceptoDePago(): int
    {
        return (int) DB::table('conceptos_pago')->insertGetId([
            'clave' => 'CON-'.uniqid(),
            'nombre' => 'Colegiatura',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
