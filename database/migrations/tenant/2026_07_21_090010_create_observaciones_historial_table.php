<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** observaciones_historial (TENANT-CONFIG) — anotaciones al asentar (de academyx). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observaciones_historial', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observaciones_historial');
    }
};
