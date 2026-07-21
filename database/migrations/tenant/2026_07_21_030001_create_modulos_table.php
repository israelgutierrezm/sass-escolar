<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * modulos (TENANT-CONFIG) — catálogo de módulos encendibles del sistema.
 * Se siembra igual en todos los tenants (ver Tenant\ModuloSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modulos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 120);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modulos');
    }
};
