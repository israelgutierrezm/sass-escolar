<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * modulos_activos (TENANT) — qué módulos tiene encendidos ESTA escuela.
 * PK = modulo_id (una fila por módulo por tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modulos_activos', function (Blueprint $table) {
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->boolean('activo')->default(false);
            $table->auditoria();

            $table->primary('modulo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modulos_activos');
    }
};
