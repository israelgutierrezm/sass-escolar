<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * autorizaciones_reconocimiento (TENANT-CONFIG) — tipo de RVOE/incorporación
 * (de academyx, clave para SEP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizaciones_reconocimiento', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizaciones_reconocimiento');
    }
};
