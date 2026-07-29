<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tipos_responsable (TENANT-CONFIG) — distingue al responsable de CERTIFICACIÓN
 * (1) del de TITULACIÓN (2). Catálogo oficial fijo y protegido; la lógica lo
 * conoce por clave, así que sus valores no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_responsable', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 100);
            $table->boolean('protegido')->default(false);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_responsable');
    }
};
