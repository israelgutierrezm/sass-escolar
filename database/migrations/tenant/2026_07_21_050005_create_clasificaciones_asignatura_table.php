<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * clasificaciones_asignatura (TENANT-CONFIG) — teórica, práctica,
 * teórico-práctica (de academyx).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clasificaciones_asignatura', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clasificaciones_asignatura');
    }
};
