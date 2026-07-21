<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * formulario_visibilidad (TENANT-CONFIG) — alumno, admin, ambos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulario_visibilidad', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulario_visibilidad');
    }
};
