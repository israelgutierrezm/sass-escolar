<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tipos_dispositivo_checador (TENANT-CONFIG) — qr, biométrico, geocerca, manual.
 *
 * Nota: `dispositivos_checador.tipo` y `checadas.origen` se conservan como
 * varchar según la definición de columnas de la spec; este catálogo alimenta la
 * UI y la validación, igual que otros catálogos del proyecto que la spec lista
 * sin FK explícita (tipos_plan_estudio, formulario_visibilidad...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_dispositivo_checador', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_dispositivo_checador');
    }
};
