<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * persona_rol (TENANT) — qué roles tiene activos una persona. MULTI-ROL:
 * la misma persona puede ser docente y encargado de admisiones a la vez.
 *
 * `activo` permite desactivar un rol sin borrar el historial.
 *
 * `campus_id` es el ALCANCE del rol: "director del campus Norte" es el rol
 * director_campus acotado a ese campus. NULL = alcance global (todos los
 * campus o rol que no se acota por campus). Por eso la PK es un id surrogate
 * y no (persona_id, rol_id): una persona puede tener el mismo rol en varios
 * campus.
 *
 * Caveat conocido del índice único: MySQL trata los NULL como distintos, así
 * que el unique no impide dos filas con el mismo (persona, rol) y campus NULL.
 * Se valida en la aplicación al asignar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persona_rol', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campus')->cascadeOnDelete();
            $table->boolean('activo')->default(true);
            $table->auditoria();

            $table->unique(['persona_id', 'rol_id', 'campus_id']);
            $table->index(['persona_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_rol');
    }
};
