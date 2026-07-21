<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * estados_documento (TENANT-CONFIG) — pendiente, aceptado, rechazado
 * (de cat_estado_documento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estados_documento', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estados_documento');
    }
};
