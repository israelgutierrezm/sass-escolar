<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teléfono local (fijo) de la persona, aparte del `celular`.
 *
 * El bloque de identidad compartido captura celular y teléfono local por
 * separado: no es lo mismo el móvil con el que se contacta a la persona que el
 * fijo de su casa u oficina. Nullable: mucha gente ya no tiene fijo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->string('telefono_local', 20)->nullable()->after('celular');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('telefono_local');
        });
    }
};
