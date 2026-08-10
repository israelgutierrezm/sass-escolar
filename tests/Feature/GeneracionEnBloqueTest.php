<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\PlanCobroAlumno;
use App\Services\GeneradorAdeudos;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * La generación de cargos de toda la escuela.
 *
 * ── El caso que obligó a reparar el índice ─────────────────────────────────
 * `adeudos_generacion_unique` había quedado sobre
 * `(matricula_oferta_id, periodo_etiqueta)` —perdió `regla_id` cuando esa
 * columna se soltó, y MySQL la sacó del índice sin avisar—. Con eso, una
 * matrícula admitía UN solo cargo por periodo: un plan con «Inscripción agosto»
 * y «Colegiatura agosto» reventaba al emitir el segundo con `Duplicate entry`.
 *
 * No se veía porque nadie generaba en bloque y ningún plan del demo tenía dos
 * líneas del mismo mes. Un barrido nocturno que recorre todos los planes de
 * todas las escuelas es exactamente lo que lo destapa, así que la primera
 * prueba de aquí es ésa.
 */
class GeneracionEnBloqueTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** Dos conceptos del mismo mes conviven: es configuración normal. */
    public function test_dos_lineas_del_mismo_periodo_generan_sus_dos_cargos(): void
    {
        $matricula = $this->matricula();
        $plan = $this->planCon([
            ['descripcion' => 'Inscripción', 'mes' => 8, 'anio' => 2026],
            ['descripcion' => 'Colegiatura', 'mes' => 8, 'anio' => 2026],
        ]);
        $this->asignar($plan, $matricula);

        $resultado = app(GeneradorAdeudos::class)->generarParaTodas();

        $this->assertSame(2, $resultado['cargos'], 'Las dos líneas del mismo mes deben emitirse.');
        $this->assertSame(2, Adeudo::query()->where('matricula_oferta_id', $matricula)->count());
    }

    /** Correrlo dos veces no le cobra a nadie dos veces. */
    public function test_correrlo_de_nuevo_no_duplica(): void
    {
        $matricula = $this->matricula();
        $this->asignar($this->planCon([['descripcion' => 'Colegiatura', 'mes' => 9, 'anio' => 2026]]), $matricula);

        $generador = app(GeneradorAdeudos::class);

        $this->assertSame(1, $generador->generarParaTodas()['cargos']);
        $this->assertSame(0, $generador->generarParaTodas()['cargos'], 'La segunda corrida no crea nada.');
        $this->assertSame(1, Adeudo::query()->where('matricula_oferta_id', $matricula)->count());
    }

    /**
     * Una línea agregada después llega sola a quien ya tenía el plan.
     *
     * Es lo que justifica que esto corra a diario: sin el barrido, agregar un
     * concepto a mitad del ciclo obligaba a pasar matrícula por matrícula.
     */
    public function test_una_linea_nueva_del_plan_alcanza_a_los_ya_asignados(): void
    {
        $matricula = $this->matricula();
        $plan = $this->planCon([['descripcion' => 'Colegiatura', 'mes' => 10, 'anio' => 2026]]);
        $this->asignar($plan, $matricula);

        $generador = app(GeneradorAdeudos::class);
        $generador->generarParaTodas();

        $this->agregarLinea($plan, ['descripcion' => 'Laboratorio', 'mes' => 10, 'anio' => 2026]);

        $this->assertSame(1, $generador->generarParaTodas()['cargos']);
        $this->assertSame(2, Adeudo::query()->where('matricula_oferta_id', $matricula)->count());
    }

    /** A quien canceló su plan no se le sigue cobrando. */
    public function test_un_plan_cancelado_no_genera(): void
    {
        $matricula = $this->matricula();
        $plan = $this->planCon([['descripcion' => 'Colegiatura', 'mes' => 11, 'anio' => 2026]]);
        $this->asignar($plan, $matricula, PlanCobroAlumno::CANCELADO);

        $this->assertSame(0, app(GeneradorAdeudos::class)->generarParaTodas()['cargos']);
    }

    /**
     * Un plan roto no deja sin cargos al resto de la escuela.
     *
     * Salió probando contra la escuela de ejemplo: sus dos planes apuntan a un
     * `ciclo_id` que ya no existe y el primer cargo revienta con una violación
     * de llave foránea. Sin aislar cada plan, esa sola fila mal configurada
     * dejaría a la escuela ENTERA sin emitir —de madrugada y sin nadie mirando—,
     * y el reporte diría «ok» con los cargos de menos.
     */
    public function test_un_plan_roto_no_cancela_a_los_demas(): void
    {
        $matricula = $this->matricula();

        $roto = $this->planCon([['descripcion' => 'Colegiatura', 'mes' => 1, 'anio' => 2027]]);

        /*
         * Se apagan las comprobaciones de foránea para dejar el plan apuntando a
         * un ciclo que no existe.
         *
         * No es hacer trampa: es reproducir un estado que EXISTE. La escuela de
         * ejemplo tiene sus dos planes así —`ciclo_id` 200 y 309, ninguno en
         * `ciclos`—, y la foránea está puesta en las dos bases, así que esas
         * filas sólo pudieron quedar de una resiembra con las comprobaciones
         * apagadas. La base ya no lo permitiría; los datos ya lo tienen.
         */
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('planes_cobro')->where('id', $roto->id)->update(['ciclo_id' => 999999]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->asignar($roto, $matricula);

        $sano = $this->planCon([['descripcion' => 'Laboratorio', 'mes' => 2, 'anio' => 2027]]);
        $this->asignar($sano, $matricula);

        $resultado = app(GeneradorAdeudos::class)->generarParaTodas();

        $this->assertCount(1, $resultado['fallidos'], 'El plan roto se reporta.');
        $this->assertSame($roto->id, $resultado['fallidos'][0]['plan']);
        $this->assertSame(1, $resultado['cargos'], 'Y el plan sano sí emitió lo suyo.');
    }

    /** Un plan sin nadie asignado no aparece siquiera en el reporte. */
    public function test_un_plan_sin_asignados_no_se_cuenta(): void
    {
        $this->planCon([['descripcion' => 'Colegiatura', 'mes' => 12, 'anio' => 2026]]);

        $resultado = app(GeneradorAdeudos::class)->generarParaTodas();

        $this->assertSame(0, $resultado['planes']);
        $this->assertSame(0, $resultado['matriculas']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function matricula(): int
    {
        return $this->alumnoInscrito()['matricula'];
    }

    /** @param array<int, array<string, mixed>> $lineas */
    private function planCon(array $lineas): PlanCobro
    {
        $plan = PlanCobro::query()->create([
            'nombre' => 'Plan '.uniqid(),
            // `vigente_desde` no tiene default en la tabla: un plan sin fecha de
            // arranque no significa nada.
            'vigente_desde' => now()->toDateString(),
        ]);

        foreach ($lineas as $linea) {
            $this->agregarLinea($plan, $linea);
        }

        return $plan->load('conceptos');
    }

    /** @param array<string, mixed> $linea */
    private function agregarLinea(PlanCobro $plan, array $linea): ConceptoPlan
    {
        $concepto = ConceptoPlan::query()->create([
            'plan_cobro_id' => $plan->id,
            'concepto_id' => $this->deCatalogo('conceptos_pago'),
            'descripcion' => $linea['descripcion'],
            'monto' => 1000,
            'mes_referencia' => $linea['mes'],
            'anio_referencia' => $linea['anio'],
            'tipo_pago' => 'unico',
        ]);

        $plan->unsetRelation('conceptos');

        return $concepto;
    }

    private function asignar(PlanCobro $plan, int $matricula, string $estatus = PlanCobroAlumno::ACTIVO): void
    {
        DB::table('plan_cobro_alumno')->insert([
            'plan_cobro_id' => $plan->id,
            'matricula_oferta_id' => $matricula,
            'estatus' => $estatus,
            'asignado_en' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
