<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inscripcion (TENANT) — NIVEL ÚNICO canónico.
 *
 * Un alumno (vía su matricula_oferta) inscrito a UNA asignatura_grupo.
 * "Inscribir a todo el grupo" = crear N filas. "Materia suelta" = 1 fila.
 * "Recursador" = tipo = recursamiento. Esto colapsa la doble inscripción del
 * legacy IMEP (inter_alumno_grupo + inter_alumno_asignatura_grupo).
 *
 * `ciclo_id` va denormalizado a propósito: permite consultar por periodo sin
 * pasar por asignatura_grupo → grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscripcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo');
            $table->foreignId('ciclo_id')->constrained('ciclos'); // denormalizado
            $table->string('tipo', 20); // ordinaria / recursamiento
            $table->string('forma_inscripcion', 20); // autogestiva / administrativa
            $table->foreignId('situacion_id')->constrained('situaciones_inscripcion');
            $table->decimal('calificacion_final', 4, 2)->nullable(); // se calcula al cierre
            $table->auditoria();

            $table->unique(['matricula_oferta_id', 'asignatura_grupo_id']);
            $table->index(['ciclo_id', 'matricula_oferta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscripcion');
    }
};
