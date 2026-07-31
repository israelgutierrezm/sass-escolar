<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Becas, descuentos, asignación de planes y desglose de ajustes.
 *
 * Tres cosas que antes vivían revueltas en `recargos_descuentos` se separan
 * porque son decisiones distintas con reglas distintas:
 *
 *  - **Recargo por mora** → `reglas_recargo` (migración anterior).
 *  - **Beca** → se le otorga a UN alumno, se conserva o se pierde según su
 *    conducta de pago y su promedio, y normalmente se renueva cada ciclo.
 *  - **Descuento** → comercial (pago anticipado, campaña); no depende del
 *    alumno sino de cuándo/cómo paga.
 *
 * La beca cuelga de `matricula_oferta` (persona + oferta), que es la unidad
 * matriculable real: alguien con dos carreras puede tener beca en una y no en
 * la otra.
 *
 * `adeudo_ajustes` es el desglose que explica POR QUÉ un cargo cuesta lo que
 * cuesta: un renglón por cada beca, descuento o recargo que lo movió. El
 * `monto` va con signo (recargo +, beca/descuento −), así el total es
 * `monto + SUM(ajustes)` y el estado de cuenta se puede auditar renglón a
 * renglón en vez de mostrar un número que nadie sabe de dónde salió.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El modelo viejo (una tabla para recargos, descuentos y becas a la vez)
        // queda superado por reglas_recargo + descuentos + becas.
        Schema::dropIfExists('becas_alumno');
        Schema::dropIfExists('recargos_descuentos');

        // ---------- BECAS ----------

        Schema::create('becas', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->string('descripcion', 255)->nullable();

            $table->string('modo', 20)->default('porcentaje'); // porcentaje | monto_fijo
            $table->decimal('valor', 10, 4);
            $table->decimal('tope_monto', 12, 2)->nullable(); // tope del descuento por cargo

            // Vigencia y renovación. Lo normal es que la beca sea POR CICLO y
            // haya que renovarla: al cerrar el ciclo se revisa si sigue siendo
            // candidato.
            $table->boolean('por_ciclo')->default(true);
            $table->boolean('requiere_renovacion')->default(true);

            // Conservación por conducta de pago. `efecto_atraso`:
            //   ninguno           → el atraso no afecta la beca
            //   suspende_periodo  → ese cargo se cobra completo; los siguientes
            //                       vuelven a llevar descuento
            //   pierde_beca       → la pierde definitivamente
            $table->boolean('requiere_pago_puntual')->default(false);
            $table->smallInteger('dias_tolerancia')->default(0);
            $table->string('efecto_atraso', 20)->default('ninguno');

            // Conservación por desempeño. Se evalúa contra el promedio del
            // CICLO ANTERIOR (decisión del usuario). `efecto_promedio`:
            //   ninguno | no_renueva | pierde_beca
            $table->decimal('promedio_minimo', 4, 2)->nullable();
            $table->string('efecto_promedio', 20)->default('no_renueva');

            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        // A qué conceptos aplica la beca. Sin filas = aplica a todos.
        Schema::create('beca_concepto', function (Blueprint $table) {
            $table->foreignId('beca_id')->constrained('becas')->cascadeOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos_pago')->cascadeOnDelete();
            $table->primary(['beca_id', 'concepto_id']);
        });

        // La beca otorgada a un alumno concreto.
        Schema::create('becas_alumno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('beca_id')->constrained('becas');
            // Ciclo al que corresponde (NULL si la beca es indefinida).
            $table->foreignId('ciclo_id')->nullable()->constrained('ciclos')->nullOnDelete();

            // activa | suspendida | perdida | por_renovar
            $table->string('estatus', 20)->default('activa');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();

            // Con qué promedio se otorgó/renovó: deja ver después por qué se
            // aprobó, aunque el kárdex haya cambiado.
            $table->decimal('promedio_evaluado', 4, 2)->nullable();

            // Una beca es una decisión con costo: alguien responde por ella.
            $table->foreignId('autorizado_por')->nullable()->constrained('personas');
            $table->string('motivo', 255)->nullable();
            $table->auditoria();

            $table->unique(['matricula_oferta_id', 'beca_id', 'ciclo_id'], 'becas_alumno_unica');
            $table->index(['matricula_oferta_id', 'estatus']);
        });

        // Bitácora append-only: otorgada, renovada, suspendida, perdida…
        Schema::create('beca_alumno_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beca_alumno_id')->constrained('becas_alumno')->cascadeOnDelete();
            $table->string('accion', 30); // otorgada|renovada|suspendida|reactivada|perdida|cancelada|no_renovada
            $table->string('detalle', 500)->nullable();
            $table->foreignId('realizado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('realizado_por_nombre', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['beca_alumno_id', 'id']);
        });

        // ---------- DESCUENTOS (lo contrario al recargo) ----------

        Schema::create('descuentos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->string('descripcion', 255)->nullable();

            // pago_anticipado | campana | manual
            $table->string('tipo', 20)->default('pago_anticipado');
            $table->string('modo', 20)->default('porcentaje'); // porcentaje | monto_fijo
            $table->decimal('valor', 10, 4);
            $table->decimal('tope_monto', 12, 2)->nullable();

            // Pago anticipado: cuántos días antes del límite hay que pagar.
            $table->smallInteger('dias_anticipacion')->nullable();
            // Campaña: ventana en la que existe el descuento.
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();

            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        Schema::create('descuento_concepto', function (Blueprint $table) {
            $table->foreignId('descuento_id')->constrained('descuentos')->cascadeOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos_pago')->cascadeOnDelete();
            $table->primary(['descuento_id', 'concepto_id']);
        });

        // ---------- ASIGNACIÓN DEL PLAN AL ALUMNO ----------

        // Vincular el plan a un alumno es lo que dispara la generación masiva de
        // sus cargos; no depende de que ya esté inscrito al ciclo.
        Schema::create('plan_cobro_alumno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_cobro_id')->constrained('planes_cobro')->cascadeOnDelete();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->string('estatus', 20)->default('activo'); // activo | cancelado
            $table->timestamp('asignado_en')->useCurrent();
            $table->foreignId('asignado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->auditoria();

            $table->unique(['plan_cobro_id', 'matricula_oferta_id'], 'plan_alumno_unico');
            $table->index(['matricula_oferta_id', 'estatus']);
        });

        // ---------- DESGLOSE DE AJUSTES ----------

        Schema::create('adeudo_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adeudo_id')->constrained('adeudos')->cascadeOnDelete();
            $table->string('tipo', 20); // beca | descuento | recargo
            // Polimórfico sin FK: beca_alumno_id / descuento_id / regla_recargo_id.
            $table->unsignedBigInteger('origen_id')->nullable();
            // Snapshot del nombre: la beca puede renombrarse o borrarse después y
            // el estado de cuenta de un cargo viejo debe seguir explicándose.
            $table->string('etiqueta', 150);
            // Con signo: recargo +, beca/descuento −.
            $table->decimal('monto', 10, 2);
            // Para recargos mensuales acumulativos: a qué periodo corresponde.
            $table->string('periodo_aplicado', 30)->nullable();
            $table->auditoria();

            $table->index(['adeudo_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adeudo_ajustes');
        Schema::dropIfExists('plan_cobro_alumno');
        Schema::dropIfExists('descuento_concepto');
        Schema::dropIfExists('descuentos');
        Schema::dropIfExists('beca_alumno_movimientos');
        Schema::dropIfExists('becas_alumno');
        Schema::dropIfExists('beca_concepto');
        Schema::dropIfExists('becas');

        // Se restituye el modelo anterior para poder revertir limpio.
        Schema::create('recargos_descuentos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);
            $table->string('nombre', 150);
            $table->string('modo', 20);
            $table->decimal('valor', 10, 4);
            $table->smallInteger('dias_gracia')->nullable();
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
            $table->foreignId('autorizado_por')->nullable()->constrained('personas');
            $table->string('motivo', 255)->nullable();
            $table->auditoria();

            $table->index(['matricula_oferta_id', 'vigente_desde']);
        });
    }
};
