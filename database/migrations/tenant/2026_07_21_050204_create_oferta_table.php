<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * oferta (TENANT) — qué se imparte dónde: combinación carrera+plan+campus
 * (+ modalidad y turno) que la escuela ofrece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oferta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrera_id')->constrained('carreras');
            $table->foreignId('plan_id')->constrained('planes_estudio');
            $table->foreignId('campus_id')->constrained('campus');
            $table->string('modalidad', 30); // presencial / online / mixta
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->string('estatus', 30); // abierta / cerrada
            $table->auditoria();

            $table->unique(['carrera_id', 'plan_id', 'campus_id', 'turno_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oferta');
    }
};
