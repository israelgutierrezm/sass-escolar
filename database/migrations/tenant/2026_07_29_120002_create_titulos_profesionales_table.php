<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * titulos_profesionales (TENANT-CONFIG) — abreviatura + descripción del título
 * del responsable (Ing./Ingeniero, Lic./Licenciado, Dr./Doctor…). A diferencia
 * de cargos y tipos_responsable, este catálogo lo administra la escuela desde
 * Configuración → Catálogos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulos_profesionales', function (Blueprint $table) {
            $table->id();
            $table->string('abreviatura', 20)->unique();
            $table->string('descripcion', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulos_profesionales');
    }
};
