<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * horarios_asignatura_grupo (TENANT) — bloques de horario de una materia en un
 * grupo. Necesario para validar choques en la inscripción autogestiva y para
 * alimentar el motor de horarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios_asignatura_grupo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo')->cascadeOnDelete();
            $table->smallInteger('dia_semana'); // 1..7
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->foreignId('aula_id')->nullable()->constrained('aulas');
            $table->auditoria();

            $table->index(['aula_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios_asignatura_grupo');
    }
};
