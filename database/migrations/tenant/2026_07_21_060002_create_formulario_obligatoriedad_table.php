<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * formulario_obligatoriedad (TENANT-CONFIG) — obligatorio, opcional, condicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulario_obligatoriedad', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulario_obligatoriedad');
    }
};
