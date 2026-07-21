<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * temas (TENANT-CONFIG) — catálogo de temas visuales que la escuela pone a
 * disposición de sus usuarios (Claro, Oscuro, Alto contraste, institucional...).
 * Los colores NO van en JSON: viven en tema_tokens (una fila por token).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temas', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 120);
            $table->boolean('es_default')->default(false);
            $table->boolean('permite_override_usuario')->default(false);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temas');
    }
};
