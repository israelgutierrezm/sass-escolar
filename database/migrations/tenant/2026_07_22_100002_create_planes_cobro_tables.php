<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El motor configurable de cobro.
 *
 * Principio rector de la spec: NO se modela "colegiatura" ni "pago semanal"
 * como casos del código. Se modelan conceptos + reglas + planes asignables, de
 * modo que "semanal sin inscripción", "mensual con inscripción" y "pago único
 * de titulación" sean DATOS. Es lo que permite que una sola base sirva a
 * escuelas con esquemas de cobro distintos, que es el punto del SaaS.
 *
 * `aplica_a_id` es polimórfico y va sin FK, igual que `formulario_asignacion`:
 * apunta a carrera, plan u oferta según el tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes_cobro', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('moneda', 3)->default('MXN');
            $table->string('aplica_a_tipo', 30); // carrera / plan / oferta / global
            $table->unsignedBigInteger('aplica_a_id')->nullable(); // NULL si global
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->auditoria();

            $table->index(['aplica_a_tipo', 'aplica_a_id']);
        });

        Schema::create('reglas_generacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_cobro_id')->constrained('planes_cobro')->cascadeOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos_pago');
            $table->string('periodicidad', 20); // unico/semanal/quincenal/mensual/por_ciclo/por_materia
            $table->decimal('monto_base', 10, 2);
            $table->smallInteger('dia_generacion')->nullable();
            $table->smallInteger('dia_limite')->nullable();
            $table->boolean('obligatorio')->default(true);
            $table->smallInteger('num_parcialidades')->nullable();
            // Prorratear al ingresar a media periodicidad: quien entra el día 20
            // no debería pagar el mes completo.
            $table->boolean('prorratea')->default(false);
            $table->foreignId('concepto_prerequisito_id')->nullable()->constrained('conceptos_pago');
            $table->auditoria();

            $table->index(['plan_cobro_id', 'periodicidad']);
        });

        Schema::create('recargos_descuentos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);   // recargo / descuento / beca
            $table->string('nombre', 150);
            $table->string('modo', 20);   // porcentaje / monto_fijo
            $table->decimal('valor', 10, 4);
            $table->smallInteger('dias_gracia')->nullable(); // mora antes de aplicar
            $table->decimal('tope_monto', 12, 2)->nullable();
            $table->boolean('requiere_beca')->default(false);
            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        Schema::create('becas_alumno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('recargo_descuento_id')->constrained('recargos_descuentos');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            // Quién la autorizó: una beca es una decisión con costo y alguien
            // tiene que responder por ella.
            $table->foreignId('autorizado_por')->nullable()->constrained('personas');
            $table->string('motivo', 255)->nullable();
            $table->auditoria();

            $table->index(['matricula_oferta_id', 'vigente_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('becas_alumno');
        Schema::dropIfExists('recargos_descuentos');
        Schema::dropIfExists('reglas_generacion');
        Schema::dropIfExists('planes_cobro');
    }
};
