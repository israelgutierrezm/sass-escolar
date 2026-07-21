<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * situaciones_aspirante (TENANT-CONFIG) — prospecto, en proceso, aceptado,
 * rechazado, inscrito (de cat_situacion_aspirante).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('situaciones_aspirante', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('situaciones_aspirante');
    }
};
