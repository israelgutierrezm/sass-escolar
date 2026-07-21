<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tipos_campo (TENANT-CONFIG) — texto, número, select, documento... Determina
 * cómo se renderiza y valida cada campo de formulario (del legacy
 * cat_tipo_campo). Vocabulario compartido con los reactivos del LMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_campo', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_campo');
    }
};
