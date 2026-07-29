<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * titulos_docente (TENANT) — los grados/títulos del docente, tipo CV. Un docente
 * puede tener varios (licenciatura, maestría, doctorado…), cada uno con su
 * cédula, institución, año y opcionalmente el documento escaneado. Cuelga de la
 * PERSONA (no del registro docente), porque son credenciales de la persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulos_docente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('grado', 60);            // Licenciatura, Maestría, Doctorado…
            $table->string('titulo_obtenido', 255); // «Licenciado en Matemáticas»
            $table->string('cedula', 30)->nullable();
            $table->string('institucion', 255)->nullable();
            $table->unsignedSmallInteger('anio')->nullable();
            $table->string('archivo_url', 500)->nullable(); // disco privado
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulos_docente');
    }
};
