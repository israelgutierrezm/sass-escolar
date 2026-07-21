<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** estatus_historial (TENANT-CONFIG) — aprobada, reprobada, en curso, no presentó. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estatus_historial', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estatus_historial');
    }
};
