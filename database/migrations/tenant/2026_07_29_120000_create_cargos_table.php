<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cargos (TENANT-CONFIG) — el puesto del responsable que firma certificaciones y
 * títulos: director, subdirector, rector, vicerrector, responsable de expedición.
 * Catálogo oficial fijo y protegido (no se edita ni elimina).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->boolean('protegido')->default(false);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargos');
    }
};
