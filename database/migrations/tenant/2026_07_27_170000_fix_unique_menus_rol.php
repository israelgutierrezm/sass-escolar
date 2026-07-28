<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `menus_rol` usa soft delete (por `TieneAuditoria`), y el único simple sobre
 * `rol_id` NO se libera al borrar lógicamente: una fila soft-borrada seguía
 * ocupando la clave y reventaba el siguiente `updateOrCreate`. Se cambia a único
 * COMPUESTO `(rol_id, deleted_at)` —el patrón correcto con soft delete—, dejando
 * `rol_id` como columna líder para que la FK siga teniendo su índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Purga cualquier fila soft-borrada que estuviera bloqueando la clave.
        Schema::disableForeignKeyConstraints();
        \Illuminate\Support\Facades\DB::table('menus_rol')->whereNotNull('deleted_at')->delete();
        Schema::enableForeignKeyConstraints();

        // Se agrega PRIMERO el índice compuesto (con rol_id líder) para que la
        // FK tenga índice; solo entonces se puede soltar el único simple.
        Schema::table('menus_rol', function (Blueprint $table) {
            $table->unique(['rol_id', 'deleted_at']);
        });
        Schema::table('menus_rol', function (Blueprint $table) {
            $table->dropUnique('menus_rol_rol_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('menus_rol', function (Blueprint $table) {
            $table->dropUnique(['rol_id', 'deleted_at']);
            $table->unique('rol_id');
        });
    }
};
