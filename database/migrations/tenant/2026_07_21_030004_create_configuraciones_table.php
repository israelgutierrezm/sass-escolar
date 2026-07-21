<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * configuraciones (TENANT) — clave/valor para todo lo encendible/apagable que
 * no es un módulo completo (modo de pago, inscripción autogestiva, branding...).
 * Un valor = una fila. PK = clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->string('clave', 100)->primary();
            $table->string('valor', 500)->nullable();
            $table->string('tipo_dato', 20)->default('string'); // string/int/bool/date
            $table->string('descripcion', 255)->nullable();
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
