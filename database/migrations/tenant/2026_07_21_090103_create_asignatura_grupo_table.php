<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * asignatura_grupo (TENANT) — la materia concreta abierta en un grupo.
 *
 * La materia se referencia por `plan_materia_id` (la materia-en-plan, con su
 * clave de acta), no por la asignatura de catálogo. Eso resuelve el TRONCO
 * COMÚN: dos ofertas con planes distintos abren cada una su asignatura_grupo
 * apuntando a la misma asignatura de catálogo pero a distinta plan_materia, y
 * pueden compartir el mismo grupo (misma aula, mismo docente) conservando cada
 * una su clave de acta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignatura_grupo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->foreignId('plan_materia_id')->constrained('plan_materias');
            $table->dateTime('fecha_inicio')->nullable();
            $table->dateTime('fecha_fin')->nullable();
            $table->foreignId('situacion_id')->constrained('situaciones_asignatura_grupo');
            $table->auditoria();

            $table->unique(['grupo_id', 'plan_materia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignatura_grupo');
    }
};
