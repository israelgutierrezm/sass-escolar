<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auditoria (TENANT) — bitácora transversal. Registra quién cambió qué en
 * cualquier tabla del tenant. Es la ÚNICA excepción a la convención de
 * auditoría: por ser append-only y genérica, no lleva las columnas estándar
 * (updated_at/deleted_at/created_by/updated_by) ni soft delete; solo created_at.
 * También es el único uso justificado de columnas JSON (audita tablas de
 * columnas variables, no puede tener columnas fijas para los valores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('evento', 50); // created / updated / deleted
            $table->json('valores_anteriores')->nullable();
            $table->json('valores_nuevos')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
