<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calificaciones_componente (TENANT) — lo que el docente captura.
 *
 * Hueco de la spec: `esquema_evaluacion` define CÓMO se compone la
 * calificación (parcial_1 30%, final 40%...) y `inscripcion.calificacion_final`
 * guarda el resultado, pero no había dónde vivieran los valores capturados.
 * Aquí viven: una fila por alumno-en-materia × componente.
 *
 * Se resuelve relacional y no como JSON en `inscripcion` por la misma razón
 * por la que `esquema_evaluacion` reemplazó el `ponderacion_config` jsonb del
 * legacy: los componentes se consultan, se promedian y se auditan uno por uno.
 *
 * `calificacion` es nullable a propósito: capturar "aún no" es distinto de
 * capturar cero, y la calculadora necesita distinguirlos para no dar por final
 * una ponderación incompleta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calificaciones_componente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('inscripcion')->cascadeOnDelete();
            $table->foreignId('esquema_evaluacion_id')->constrained('esquema_evaluacion');
            $table->decimal('calificacion', 5, 2)->nullable();

            // Quién puso el número y cuándo. `created_by` de la auditoría
            // guarda el USUARIO; esto guarda a la PERSONA que califica, que es
            // el dato que se defiende ante una aclaración del alumno.
            $table->unsignedBigInteger('capturado_por')->nullable();
            $table->timestamp('capturado_en')->nullable();

            $table->auditoria();

            $table->unique(['inscripcion_id', 'esquema_evaluacion_id'], 'calif_componente_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calificaciones_componente');
    }
};
