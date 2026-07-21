<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documentos_requeridos (TENANT) — catálogo de qué documentos se piden
 * (del legacy cat_documento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_requeridos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('descripcion', 255)->nullable();
            $table->boolean('obligatorio')->default(true);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_requeridos');
    }
};
