<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cleaver_aspirante (TENANT) — respuestas del aspirante al test DISC. El
 * perfil se calcula agregando estas filas contra las dimensiones del reactivo.
 * `respuesta_id` distingue "más" / "menos" (el formato del instrumento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaver_aspirante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aspirante_id')->constrained('aspirantes')->cascadeOnDelete();
            $table->foreignId('reactivo_cleaver_id')->constrained('reactivos_cleaver');
            $table->smallInteger('respuesta_id'); // más / menos
            $table->auditoria();

            $table->unique(['aspirante_id', 'reactivo_cleaver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaver_aspirante');
    }
};
