<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * usuario_tema_override (TENANT) — ajustes personales del usuario sobre su
 * tema, un token por fila (relacional, sin JSON).
 *
 * Solo se aplican si el tema elegido tiene `permite_override_usuario = true`.
 * Cascada de resolución en el front: tema del sistema → tema de la escuela
 * (temas.es_default) → tema elegido por el usuario → estos overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_tema_override', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('token', 60);
            $table->string('valor', 40);
            $table->auditoria();

            $table->unique(['usuario_id', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_tema_override');
    }
};
