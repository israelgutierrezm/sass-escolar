<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * campos_formulario (TENANT) — cada pregunta es una FILA (reemplaza el schema
 * jsonb). Soporta validación por regex + mensaje_error y campos condicionales
 * (campo_padre_id + valor que lo dispara), como el legacy CUGS.
 *
 * `promueve_a` documenta que el dato además se escribe en una columna real
 * (p.ej. personas.celular): los datos calientes viven tipados, no aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campos_formulario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formulario_id')->constrained('formularios')->cascadeOnDelete();
            $table->foreignId('tipo_campo_id')->constrained('tipos_campo');
            $table->string('pregunta', 255); // el label
            $table->string('descripcion', 255)->nullable();
            $table->boolean('obligatorio')->default(false);
            $table->string('regex', 255)->nullable();
            $table->string('mensaje_error', 150)->nullable();
            $table->smallInteger('orden')->default(0);
            $table->foreignId('campo_padre_id')->nullable()->constrained('campos_formulario')->nullOnDelete();
            $table->string('condicional', 100)->nullable(); // valor del padre que dispara este campo
            $table->decimal('min', 10, 2)->nullable();
            $table->decimal('max', 10, 2)->nullable();
            $table->string('promueve_a', 60)->nullable();
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campos_formulario');
    }
};
