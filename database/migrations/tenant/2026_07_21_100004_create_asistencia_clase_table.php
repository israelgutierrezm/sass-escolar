<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * asistencia_clase (TENANT) — presencia académica del alumno a UNA materia.
 *
 * Cuelga de `inscripcion` (no de la checada) porque es lo que afecta faltas y
 * calificación de esa materia concreta. Invierte el modelo del legacy IMEP
 * (tr_inasistencia_clase): se registra presencia Y ausencia explícita, no solo
 * faltas — así "no hay registro" se distingue de "faltó".
 *
 * `registrada_por` es el docente que pasó lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_clase', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('inscripcion')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('estatus', 20); // presente / ausente / justificada / retardo
            $table->foreignId('registrada_por')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('observacion', 255)->nullable();
            $table->auditoria();

            $table->unique(['inscripcion_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_clase');
    }
};
