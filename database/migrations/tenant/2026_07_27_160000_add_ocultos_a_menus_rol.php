<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Además de ORDENAR y ANIDAR, el editor de menú por rol ahora puede OCULTAR
 * opciones o grupos que ese rol sí podría ver por permiso. `ocultos` guarda las
 * claves del catálogo que la barra lateral no debe mostrar para ese rol (ni
 * siquiera al fusionar opciones nuevas). Ocultar es cosmético: NO cambia
 * permisos, solo la visibilidad en la barra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus_rol', function (Blueprint $table) {
            $table->json('ocultos')->nullable()->after('estructura');
        });
    }

    public function down(): void
    {
        Schema::table('menus_rol', function (Blueprint $table) {
            $table->dropColumn('ocultos');
        });
    }
};
