<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga los planes y sus materias con la plantilla de evaluación que usan.
 *
 * En `planes_estudio` es el criterio POR DEFECTO del plan: lo que se aplica a
 * sus materias cuando no se dice otra cosa.
 *
 * En `plan_materias` registra de qué plantilla salió el esquema materializado
 * de ESA materia. Sirve para dos cosas concretas:
 *  - saber a quién re-propagar cuando la plantilla cambia;
 *  - distinguir la materia que sigue el criterio general de la que se desvió.
 *    NULL significa esquema propio, armado a mano, y esas nunca se pisan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->foreignId('plantilla_evaluacion_id')
                ->nullable()
                ->after('calificacion_minima_aprobatoria')
                ->constrained('plantillas_evaluacion')
                ->nullOnDelete();
        });

        Schema::table('plan_materias', function (Blueprint $table) {
            $table->foreignId('plantilla_evaluacion_id')
                ->nullable()
                ->after('creditos_en_plan')
                ->constrained('plantillas_evaluacion')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plan_materias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plantilla_evaluacion_id');
        });

        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plantilla_evaluacion_id');
        });
    }
};
