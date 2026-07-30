<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `identificador` en los catálogos que alimentan el Documento Electrónico de
 * Certificación (DEC) de la SEP. El `id` auto-incremental NO es confiable como
 * identificador oficial (puede empezar en otro número o desalinearse entre
 * escuelas); este campo guarda el valor que se envía en el XML —homologando
 * todos los catálogos con un mismo concepto—.
 *
 * Nivel de estudios y tipo de periodo ya se sembraron con la clave oficial
 * (81 = LICENCIATURA, 91 = SEMESTRE, …); se copia esa clave al identificador.
 * Campus, tipo de asignatura y cargo quedan en blanco para que la escuela los
 * capture con sus claves.
 */
return new class extends Migration
{
    /** Tablas tenant que reciben el identificador. */
    private array $tablas = ['niveles_estudio', 'tipos_periodo', 'tipos_asignatura', 'campus', 'cargos'];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            if (Schema::hasTable($tabla) && ! Schema::hasColumn($tabla, 'identificador')) {
                Schema::table($tabla, function (Blueprint $t) {
                    $t->string('identificador', 40)->nullable()->after('clave');
                });
            }
        }

        // Nivel y tipo de periodo ya traen la clave oficial en `clave`.
        foreach (['niveles_estudio', 'tipos_periodo'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                DB::table($tabla)->whereNull('identificador')->update(['identificador' => DB::raw('clave')]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            if (Schema::hasColumn($tabla, 'identificador')) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn('identificador'));
            }
        }
    }
};
