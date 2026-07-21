<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * formularios (TENANT) — motor de formularios dinámicos, 100% relacional.
 *
 * El versionado (unique clave+version) permite que respuestas viejas apunten a
 * la estructura con la que se respondieron sin romperse: al cambiar la
 * estructura se sube la versión en lugar de mutar la existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formularios', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50); // antecedente_academico, datos_generales...
            $table->string('titulo', 150);
            $table->string('instruccion', 255)->nullable();
            $table->string('icono', 50)->nullable();
            $table->smallInteger('orden')->default(0);
            $table->smallInteger('porcentaje')->nullable(); // % de avance que representa
            $table->boolean('obligatorio')->default(false);
            $table->integer('version')->default(1);
            $table->auditoria();

            $table->unique(['clave', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formularios');
    }
};
