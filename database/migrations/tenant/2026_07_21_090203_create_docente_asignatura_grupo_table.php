<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docente_asignatura_grupo (TENANT) — docente(s) por materia, tipado.
 *
 * Una materia puede tener 1..N docentes: el TITULAR es quien firma el acta;
 * los ADJUNTOS acompañan. Regla de negocio: a lo más un titular por
 * asignatura_grupo — se valida en la aplicación (MySQL no permite un índice
 * único parcial "solo donde tipo='titular'").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docente_asignatura_grupo', function (Blueprint $table) {
            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('docentes', 'persona_id')->cascadeOnDelete();
            $table->string('tipo', 20); // titular / adjunto
            $table->auditoria();

            $table->primary(['asignatura_grupo_id', 'persona_id']);
            $table->index(['asignatura_grupo_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docente_asignatura_grupo');
    }
};
