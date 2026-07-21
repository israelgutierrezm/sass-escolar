<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * historial (TENANT) — el kárdex.
 *
 * Cuelga de `matricula_oferta`, no de un alumno genérico: así el historial de
 * la licenciatura no se mezcla con el de la maestría de la misma persona.
 * Soporta ordinaria / extraordinaria / revalidación / recursamiento y más vía
 * el catálogo `tipos_evaluacion`.
 *
 * `asignatura_grupo_id` es nullable porque una materia puede llegar al kárdex
 * sin haberse cursado en un grupo (revalidación, equivalencia, examen a
 * título). `acta_folio` guarda el folio donde se asentó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('plan_materia_id')->constrained('plan_materias');
            $table->foreignId('ciclo_id')->constrained('ciclos');
            $table->foreignId('asignatura_grupo_id')->nullable()->constrained('asignatura_grupo');
            $table->foreignId('tipo_evaluacion_id')->constrained('tipos_evaluacion');
            $table->foreignId('estatus_id')->constrained('estatus_historial');
            $table->decimal('calificacion', 4, 2)->nullable();
            $table->foreignId('situacion_reprobatoria_id')->nullable()->constrained('situaciones_reprobatoria');
            $table->string('acta_folio', 50)->nullable();
            $table->foreignId('observacion_id')->nullable()->constrained('observaciones_historial');
            $table->auditoria();

            $table->index(['matricula_oferta_id', 'plan_materia_id']);
            $table->index('acta_folio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial');
    }
};
