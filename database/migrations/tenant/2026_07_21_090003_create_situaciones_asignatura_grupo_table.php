<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** situaciones_asignatura_grupo (TENANT-CONFIG) — activa, cerrada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('situaciones_asignatura_grupo', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('situaciones_asignatura_grupo');
    }
};
