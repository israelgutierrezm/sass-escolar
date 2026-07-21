<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * respuestas_campo (TENANT) — cierra el Módulo 3 (formularios dinámicos).
 *
 * Una respuesta = una FILA (reemplaza el jsonb `respuestas`). Es la tabla que
 * permite el UPDATE directo:
 *   UPDATE respuestas_campo SET valor=? WHERE matricula_oferta_id=? AND campo_formulario_id=?
 *
 * Cuelga de `matricula_oferta` (no de la persona) para el caso rector: un
 * alumno llena "antecedente académico" al entrar a la licenciatura y LO VUELVE
 * A LLENAR al entrar a la maestría, quedando ligado a esa oferta específica.
 * `aspirante_id` cubre las respuestas dadas antes de ser alumno.
 *
 * `formulario_version` congela con qué versión de la estructura se respondió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respuestas_campo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campo_formulario_id')->constrained('campos_formulario');
            $table->integer('formulario_version');
            $table->foreignId('persona_id')->constrained('personas');
            $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('aspirante_id')->nullable()->constrained('aspirantes')->cascadeOnDelete();
            $table->string('valor', 500)->nullable();
            $table->string('documento_ruta', 500)->nullable(); // si el campo es tipo documento
            $table->auditoria();

            $table->index(['matricula_oferta_id', 'campo_formulario_id']);
            $table->index(['aspirante_id', 'campo_formulario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respuestas_campo');
    }
};
