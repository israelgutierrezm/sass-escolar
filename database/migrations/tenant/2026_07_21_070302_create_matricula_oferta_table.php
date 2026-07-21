<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matricula_oferta (TENANT) — "el alumno" real, por oferta.
 *
 * La unidad matriculable NO es la persona: es la inscripción a una oferta. Una
 * persona con doctorado + diplomado online son dos filas aquí. De esta tabla
 * cuelgan historial, inscripciones, adeudos y respuestas de formulario —
 * nunca del alumno "a secas".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matricula_oferta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas');
            $table->foreignId('oferta_id')->constrained('oferta'); // trae carrera+plan+campus
            $table->string('matricula', 50);
            $table->string('generacion', 100)->nullable();
            $table->date('fecha_ingreso');
            $table->foreignId('situacion_id')->constrained('situaciones_alumno');
            $table->string('estatus', 30); // activo / egresado / baja
            $table->auditoria();

            $table->unique(['persona_id', 'oferta_id']);
            $table->unique('matricula');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_oferta');
    }
};
