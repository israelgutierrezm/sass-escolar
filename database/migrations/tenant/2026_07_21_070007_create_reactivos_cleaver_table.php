<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reactivos_cleaver (TENANT-CONFIG) — banco de reactivos del test psicométrico
 * Cleaver (perfil DISC). Las 4 dimensiones son columnas booleanas, tal cual el
 * legacy cat_reactivos_cleaver_base:
 *   c = Cumplimiento, d = Dominio, i = Influencia, s = Estabilidad.
 *
 * NO se siembra con datos de ejemplo: el banco real de reactivos proviene del
 * sistema legacy y no debe inventarse (es un instrumento psicométrico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactivos_cleaver', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_reactivo', 255);
            $table->boolean('c')->default(false);
            $table->boolean('d')->default(false);
            $table->boolean('i')->default(false);
            $table->boolean('s')->default(false);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactivos_cleaver');
    }
};
