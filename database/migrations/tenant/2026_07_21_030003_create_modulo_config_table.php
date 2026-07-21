<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * modulo_config (TENANT) — configuración clave/valor de cada módulo, relacional
 * (una fila por parámetro). PK compuesta (modulo_id, clave).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modulo_config', function (Blueprint $table) {
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->string('clave', 100);
            $table->string('valor', 500)->nullable();
            $table->auditoria();

            $table->primary(['modulo_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modulo_config');
    }
};
