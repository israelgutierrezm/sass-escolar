<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * opciones_campo (TENANT) — cada opción de select/radio es una FILA (reemplaza
 * el array de opciones del JSON). Del legacy tr_reactivos_campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opciones_campo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campo_formulario_id')->constrained('campos_formulario')->cascadeOnDelete();
            $table->string('valor', 100); // valor guardado
            $table->string('etiqueta', 255); // texto mostrado
            $table->smallInteger('orden')->default(0);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opciones_campo');
    }
};
