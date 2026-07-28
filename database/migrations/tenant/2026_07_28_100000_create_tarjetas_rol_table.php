<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué tarjetas (widgets) del panel enciende cada rol. `activas` es la lista de
 * claves de tarjeta prendidas; sin fila, el rol ve TODAS las que su permiso le
 * permite (comportamiento por defecto). Encender/apagar es cosmético: no otorga
 * permiso, solo decide qué se muestra.
 *
 * Único COMPUESTO `(rol_id, deleted_at)` desde el inicio: `TieneAuditoria`
 * soft-borra y un único simple no se liberaría (regla ya conocida del proyecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarjetas_rol', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->json('activas'); // ["cartera", "embudo", ...]
            $table->auditoria();
            $table->unique(['rol_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarjetas_rol');
    }
};
