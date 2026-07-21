<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra la dependencia hacia adelante que quedó abierta en el Módulo 4:
 * `aspirantes.ciclo_ingreso_id` se creó como columna suelta porque `ciclos`
 * pertenece a este módulo y todavía no existía. Ahora que existe, se agrega el
 * constraint real.
 *
 * nullOnDelete: si se elimina un ciclo, el aspirante no se pierde; solo queda
 * sin ciclo de ingreso asignado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aspirantes', function (Blueprint $table) {
            $table->foreign('ciclo_ingreso_id')
                ->references('id')
                ->on('ciclos')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('aspirantes', function (Blueprint $table) {
            $table->dropForeign(['ciclo_ingreso_id']);
        });
    }
};
