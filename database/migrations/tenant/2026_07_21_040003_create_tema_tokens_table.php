<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tema_tokens (TENANT-CONFIG) — un color/token por fila (color_primario,
 * color_fondo, barra_superior...). Se inyectan como CSS custom properties en
 * el front. Relacional, sin JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tema_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id')->constrained('temas')->cascadeOnDelete();
            $table->string('token', 60);
            $table->string('valor', 40);
            $table->auditoria();

            $table->unique(['tema_id', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tema_tokens');
    }
};
