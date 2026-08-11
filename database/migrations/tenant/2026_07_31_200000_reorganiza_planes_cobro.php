<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reorganización de Finanzas (planes de cobro).
 *
 * Se rediseña libremente (decisión del usuario: la demo no tiene cobros que
 * preservar). El plan deja de ser polimórfico (carrera/plan/oferta/global) y
 * pasa a acotarse por CICLO + CAMPUS[] + CARRERAS[] (las ofertadas en esos
 * campus, filtrables por nivel). El motor abstracto de "periodicidad + día"
 * (`reglas_generacion`) se reemplaza por LÍNEAS FECHADAS explícitas
 * (`conceptos_plan`): cada cargo con su periodo (mes/año) y su fecha límite
 * concreta; una colegiatura por rango se expande en N líneas. Los recargos por
 * mora se modelan en `reglas_recargo` (default por plan + override por concepto).
 * El estatus "deudor" se decide por un toggle del plan.
 *
 * `recargos_descuentos` y `becas_alumno` (becas/descuentos) NO se tocan: son
 * otra cosa que los recargos por mora.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        // 1) adeudos deja de apuntar a reglas_generacion; apuntará a conceptos_plan.
        if (Schema::hasColumn('adeudos', 'regla_id')) {
            Schema::table('adeudos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('regla_id');
            });
        }

        // 2) Fuera el motor viejo de reglas.
        Schema::dropIfExists('reglas_generacion');

        // 3) planes_cobro: nuevo alcance por ciclo/campus/carreras + config.
        Schema::table('planes_cobro', function (Blueprint $table) {
            $table->foreignId('ciclo_id')->nullable()->after('nombre')->constrained('ciclos')->nullOnDelete();
            // ¿Los cargos llevan fecha límite? y si sí, ¿la mora empieza el mismo
            // día marcado o al día siguiente?
            $table->boolean('tiene_fecha_limite')->default(true)->after('ciclo_id');
            $table->string('fecha_limite_modo', 20)->default('exacta')->after('tiene_fecha_limite'); // exacta | dia_siguiente
            // ¿El plan permite recargos por mora? (bloquea el checkbox por concepto)
            $table->boolean('aplica_recargos')->default(false)->after('fecha_limite_modo');
            // ¿Un cargo vencido de este plan mueve al alumno a estatus deudor?
            $table->boolean('afecta_estatus_deudor')->default(false)->after('aplica_recargos');
        });

        // Se retira el alcance polimórfico anterior.
        if (Schema::hasColumn('planes_cobro', 'aplica_a_tipo')) {
            if ($mysql) {
                // El índice compuesto impide soltar las columnas directamente.
                try {
                    DB::statement('ALTER TABLE planes_cobro DROP INDEX planes_cobro_aplica_a_tipo_aplica_a_id_index');
                } catch (Throwable $e) {
                    // el nombre del índice puede variar; se ignora si no existe.
                }
            }
            Schema::table('planes_cobro', function (Blueprint $table) {
                $table->dropColumn(['aplica_a_tipo', 'aplica_a_id']);
            });
        }

        // 4) Campus a los que aplica el plan (dentro del ciclo).
        Schema::create('plan_cobro_campus', function (Blueprint $table) {
            $table->foreignId('plan_cobro_id')->constrained('planes_cobro')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campus')->cascadeOnDelete();
            $table->primary(['plan_cobro_id', 'campus_id']);
        });

        // 5) Carreras a las que aplica (las ofertadas en esos campus). Se guarda
        //    el nivel por conveniencia de reporte; la verdad son las carreras.
        Schema::create('plan_cobro_carrera', function (Blueprint $table) {
            $table->foreignId('plan_cobro_id')->constrained('planes_cobro')->cascadeOnDelete();
            $table->foreignId('carrera_id')->constrained('carreras')->cascadeOnDelete();
            $table->unsignedBigInteger('nivel_estudios_id')->nullable(); // landlord, sin FK
            $table->primary(['plan_cobro_id', 'carrera_id']);
        });

        // 6) Líneas fechadas del plan (reemplazo de reglas_generacion).
        Schema::create('conceptos_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_cobro_id')->constrained('planes_cobro')->cascadeOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos_pago');
            // Comportamiento: inscripcion (único, suele ser prerequisito) /
            // colegiatura (se captura por rango) / concepto (cargo suelto:
            // credencial, gastos administrativos, libros...).
            $table->string('tipo_pago', 20)->default('concepto');
            $table->string('descripcion', 255)->nullable();
            $table->decimal('monto', 10, 2);
            // Periodo al que hace referencia el cargo (para colegiaturas y demás).
            $table->smallInteger('mes_referencia')->nullable();   // 1..12
            $table->smallInteger('anio_referencia')->nullable();
            $table->date('fecha_limite')->nullable();
            // ¿Este concepto genera recargos por mora? (solo si el plan los permite)
            $table->boolean('aplica_recargos')->default(false);
            $table->boolean('obligatorio')->default(true);
            // Agrupa las líneas creadas juntas por un rango de colegiatura, para
            // poder editarlas/borrarlas como bloque.
            $table->string('grupo_colegiatura', 40)->nullable();
            $table->smallInteger('orden')->default(0);
            $table->auditoria();

            $table->index(['plan_cobro_id', 'tipo_pago']);
            $table->index('grupo_colegiatura');
        });

        // 7) adeudos ahora sale de una línea del plan.
        Schema::table('adeudos', function (Blueprint $table) {
            $table->foreignId('concepto_plan_id')->nullable()->after('concepto_id')
                ->constrained('conceptos_plan')->nullOnDelete();
        });

        // 8) Motor de recargos por mora: default por plan (concepto_plan_id NULL)
        //    + override por concepto (concepto_plan_id presente).
        Schema::create('reglas_recargo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_cobro_id')->constrained('planes_cobro')->cascadeOnDelete();
            $table->foreignId('concepto_plan_id')->nullable()->constrained('conceptos_plan')->cascadeOnDelete();
            $table->string('modo', 20)->default('monto_fijo');       // monto_fijo | porcentaje
            $table->decimal('valor', 10, 4);
            // unica: se aplica una vez al vencer. mensual_acumulativa: se suma
            // cada mes (o periodo) de atraso.
            $table->string('frecuencia', 30)->default('unica');
            $table->smallInteger('dias_gracia')->default(0);
            $table->decimal('tope_monto', 12, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->auditoria();

            $table->index(['plan_cobro_id', 'concepto_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_recargo');

        if (Schema::hasColumn('adeudos', 'concepto_plan_id')) {
            Schema::table('adeudos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('concepto_plan_id');
            });
        }

        Schema::dropIfExists('conceptos_plan');
        Schema::dropIfExists('plan_cobro_carrera');
        Schema::dropIfExists('plan_cobro_campus');

        Schema::table('planes_cobro', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ciclo_id');
            $table->dropColumn(['tiene_fecha_limite', 'fecha_limite_modo', 'aplica_recargos', 'afecta_estatus_deudor']);
            $table->string('aplica_a_tipo', 30)->default('global');
            $table->unsignedBigInteger('aplica_a_id')->nullable();
        });
    }
};
