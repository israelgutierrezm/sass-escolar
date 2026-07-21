<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generos (LANDLORD) — identidad de género, separada del sexo biológico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 30)->unique();
            $table->string('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generos');
    }
};
