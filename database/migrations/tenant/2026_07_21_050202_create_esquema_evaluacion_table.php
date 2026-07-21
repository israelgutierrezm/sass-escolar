<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * esquema_evaluacion (TENANT) — cómo se compone la calificación de una
 * materia-en-plan (relacional, no JSON). Una fila por componente (parcial 1,
 * final, lms...). Los porcentajes deben sumar 100 (validación en app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esquema_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_materia_id')->constrained('plan_materias');
            $table->string('componente', 60); // parcial_1 / final / lms / practicas
            $table->smallInteger('parcial')->nullable();
            $table->decimal('porcentaje', 5, 2);
            $table->smallInteger('orden');
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esquema_evaluacion');
    }
};
