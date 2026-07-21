<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sexos (LANDLORD) — catálogo biológico/oficial para documentos SEP (H/M).
 * Universal y estandarizado nacionalmente. Separado de `generos` (identidad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sexos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 5)->unique();
            $table->string('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sexos');
    }
};
