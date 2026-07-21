<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * niveles_estudio (LANDLORD) — bachillerato, licenciatura, especialidad,
 * maestría, doctorado, diplomado... Universal porque la SEP los estandariza.
 * `orden` define la progresión académica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niveles_estudio', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 30)->unique();
            $table->string('nombre');
            $table->unsignedSmallInteger('orden')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('niveles_estudio');
    }
};
