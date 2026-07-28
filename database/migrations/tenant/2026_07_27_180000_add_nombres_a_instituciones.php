<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La institución distingue ahora entre su nombre OFICIAL (razón/denominación con
 * la que membreta documentos) y el nombre A MOSTRAR (más corto, para la barra y
 * el acceso), más sus SIGLAS. `nombre` pasa a ser el oficial; los otros dos son
 * opcionales y caen al oficial cuando faltan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instituciones', function (Blueprint $table) {
            $table->string('nombre_mostrar')->nullable()->after('nombre');
            $table->string('siglas', 30)->nullable()->after('nombre_mostrar');
        });
    }

    public function down(): void
    {
        Schema::table('instituciones', function (Blueprint $table) {
            $table->dropColumn(['nombre_mostrar', 'siglas']);
        });
    }
};
