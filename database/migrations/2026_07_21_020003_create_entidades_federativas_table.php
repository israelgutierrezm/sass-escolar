<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * entidades_federativas (LANDLORD) — las 32 entidades de México + soporte para
 * nacidos en el extranjero (clave NE). La `clave` usa el código de dos letras
 * de RENAPO/CURP, clave para el título electrónico SEP y para cross-validar
 * la CURP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidades_federativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pais_id')->constrained('paises');
            $table->string('clave', 5);
            $table->string('nombre');

            $table->unique(['pais_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entidades_federativas');
    }
};
