<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plantillas_evaluacion (TENANT) — criterios de evaluación reutilizables.
 *
 * `esquema_evaluacion` cuelga de `plan_materias`, así que configurar cómo se
 * califica obligaba a repetir los mismos porcentajes en cada una de las 50
 * materias de un plan. Una plantilla es ese criterio nombrado y guardado una
 * sola vez ("3 parciales con asistencia", "Directo al curso"), que luego se
 * aplica al plan completo.
 *
 * Los componentes NO se leen en vivo desde aquí: al aplicar la plantilla se
 * MATERIALIZAN como filas de `esquema_evaluacion` en cada materia. Es lo que
 * mantiene intacta la cadena que ya existe —`calificaciones_componente` apunta
 * a `esquema_evaluacion_id`— y permite que una materia se desvíe del criterio
 * general sin inventar un segundo camino de resolución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 120);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activa')->default(true);
            $table->auditoria();
        });

        Schema::create('plantilla_componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_id')->constrained('plantillas_evaluacion')->cascadeOnDelete();
            $table->string('componente', 60);   // asistencia / examen_parcial / final...
            $table->smallInteger('parcial')->nullable(); // NULL = va directo al curso
            $table->decimal('porcentaje', 5, 2);
            $table->smallInteger('orden');
            $table->auditoria();

            $table->index(['plantilla_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_componentes');
        Schema::dropIfExists('plantillas_evaluacion');
    }
};
