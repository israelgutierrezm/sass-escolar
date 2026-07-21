<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * situaciones_alumno (TENANT-CONFIG) — activo, baja temporal, baja definitiva,
 * egresado, titulado, condicionado (de cat_situacion_alumno de IMEP, ahora
 * configurable). La usan `alumnos` y `matricula_oferta`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('situaciones_alumno', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('situaciones_alumno');
    }
};
