<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * asignaturas (TENANT) — catálogo puro de materias. La misma asignatura se
 * reutiliza entre planes; su vida dentro de un plan es plan_materias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaturas', function (Blueprint $table) {
            $table->id();
            $table->string('identificador', 50);
            $table->string('clave', 50); // clave "de catálogo"
            $table->string('nombre');
            $table->float('creditos');
            $table->foreignId('tipo_asignatura_id')->constrained('tipos_asignatura');
            $table->foreignId('clasificacion_id')->nullable()->constrained('clasificaciones_asignatura');
            $table->foreignId('area_id')->nullable()->constrained('areas');
            $table->integer('horas_teoria')->nullable();
            $table->integer('horas_practica')->nullable();
            $table->integer('horas_acompanamiento')->nullable();
            $table->integer('horas_independientes')->nullable();
            $table->text('objetivos_desc')->nullable();
            $table->text('bibliografia_desc')->nullable();
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaturas');
    }
};
