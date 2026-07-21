<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan_materias (TENANT) — núcleo del modelo curricular: la asignatura DENTRO
 * de un plan. Resuelve el tronco común: la misma asignatura_id puede aparecer
 * en planes distintos con clave_en_plan distinta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_materias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('planes_estudio');
            $table->foreignId('asignatura_id')->constrained('asignaturas');
            $table->string('clave_en_plan', 50); // la clave que sale en el acta de ESTE plan
            $table->integer('periodo')->nullable(); // semestre/cuatrimestre sugerido
            $table->string('tipo', 30); // obligatoria / optativa / tronco_comun
            $table->float('creditos_en_plan')->nullable(); // override si difiere del catálogo
            $table->auditoria();

            $table->unique(['plan_id', 'clave_en_plan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_materias');
    }
};
