<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `identificador` en los catálogos LANDLORD (compartidos por todas las escuelas)
 * que alimentan el DEC de la SEP: género y entidad federativa. Son catálogos
 * nacionales, así que el identificador oficial vive una sola vez aquí.
 *
 * El género ya se sembró con la clave oficial (250 = MUJER, 251 = HOMBRE): se
 * copia. La entidad federativa queda en blanco (se captura después con la clave
 * SEP; también sirve como lugar de expedición del certificado).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['generos', 'entidades_federativas'] as $tabla) {
            if (Schema::hasTable($tabla) && ! Schema::hasColumn($tabla, 'identificador')) {
                Schema::table($tabla, function (Blueprint $t) {
                    $t->string('identificador', 40)->nullable()->after('clave');
                });
            }
        }

        // El género ya trae la clave oficial en `clave` (250/251).
        if (Schema::hasTable('generos')) {
            DB::table('generos')->whereNull('identificador')->update(['identificador' => DB::raw('clave')]);
        }
    }

    public function down(): void
    {
        foreach (['generos', 'entidades_federativas'] as $tabla) {
            if (Schema::hasColumn($tabla, 'identificador')) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn('identificador'));
            }
        }
    }
};
