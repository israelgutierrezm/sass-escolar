<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dispositivos_checador (TENANT) — el punto de fichaje: lector QR, biométrico,
 * geocerca (app móvil) o captura manual. Las columnas de geocerca solo aplican
 * al tipo `geocerca`; `tolerancia_min` son los minutos de gracia al registrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos_checador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained('campus')->cascadeOnDelete();
            $table->string('tipo', 30); // qr / biometrico / geocerca / manual
            $table->string('identificador', 120); // serial o token del dispositivo
            $table->decimal('geocerca_lat', 10, 7)->nullable();
            $table->decimal('geocerca_lng', 10, 7)->nullable();
            $table->integer('geocerca_radio_m')->nullable();
            $table->smallInteger('tolerancia_min')->nullable();
            $table->auditoria();

            $table->unique(['campus_id', 'identificador']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_checador');
    }
};
