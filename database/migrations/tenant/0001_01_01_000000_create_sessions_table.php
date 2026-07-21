<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infraestructura de sesión de Laravel, por tenant.
 *
 * Originalmente esta migración del scaffolding creaba también la tabla `users`.
 * Se eliminó: la tabla de credenciales de este sistema es `usuarios` (Módulo 1
 * de la spec), que cuelga de `personas`. Mantener ambas dejaría una tabla
 * muerta y dos conceptos de usuario conviviendo.
 *
 * `sessions.user_id` no lleva FK (así viene de Laravel) y referencia a
 * `usuarios.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index(); // -> usuarios.id
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
