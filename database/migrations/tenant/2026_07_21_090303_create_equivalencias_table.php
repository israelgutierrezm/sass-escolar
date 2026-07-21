<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * equivalencias (TENANT) — materias reconocidas de otra institución. Forman
 * parte del kárdex pero su origen es externo, por eso van aparte del historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equivalencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('plan_materia_id')->constrained('plan_materias');
            $table->string('institucion_procedencia');
            $table->decimal('calificacion', 4, 2)->nullable();
            $table->text('documento_ruta')->nullable(); // dictamen de equivalencia
            $table->auditoria();

            $table->unique(['matricula_oferta_id', 'plan_materia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equivalencias');
    }
};
