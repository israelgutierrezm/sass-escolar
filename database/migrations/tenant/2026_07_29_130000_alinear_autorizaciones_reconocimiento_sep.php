<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea `autorizaciones_reconocimiento` al catálogo OFICIAL de la SEP, con sus
 * ids exactos (1–9), para que `planes_estudio.autorizacion_reconocimiento_id`
 * sea directamente el `idAutorizacionReconocimiento` del título electrónico.
 *
 * Catálogo oficial:
 *   1 RVOE FEDERAL            2 RVOE ESTATAL          3 AUTORIZACIÓN FEDERAL
 *   4 AUTORIZACIÓN ESTATAL    5 ACTA DE SESIÓN        6 ACUERDO DE INCORPORACIÓN
 *   7 ACUERDO SECRETARIAL SEP 8 DECRETO DE CREACIÓN   9 OTRO
 *
 * Los planes existentes se remapean por la CLAVE semántica que tenían. Las dos
 * entradas que NO son oficiales (Universidad Autónoma, Incorporación a
 * universidad) no tienen equivalente en la SEP, así que sus planes caen a
 * OTRO (9); el revisor puede reasignarlos si aplica. El catálogo queda
 * `protegido` (oficial, no editable desde Catálogos).
 */
return new class extends Migration
{
    /** clave semántica previa → id oficial SEP. Lo desconocido cae a OTRO (9). */
    private const A_OFICIAL = [
        'rvoe_federal' => 1,
        'rvoe_estatal' => 2,
        'autorizacion_federal' => 3,
        'autorizacion_estatal' => 4,
        'acta_sesion' => 5,
        'acuerdo_incorporacion' => 6,
        'acuerdo_secretarial_sep' => 7,
        'decreto_creacion' => 8,
        'otro' => 9,
        // Sin equivalente oficial → OTRO.
        'autonoma' => 9,
        'incorporacion_uni' => 9,
    ];

    private const OFICIALES = [
        1 => 'RVOE FEDERAL',
        2 => 'RVOE ESTATAL',
        3 => 'AUTORIZACIÓN FEDERAL',
        4 => 'AUTORIZACIÓN ESTATAL',
        5 => 'ACTA DE SESIÓN',
        6 => 'ACUERDO DE INCORPORACIÓN',
        7 => 'ACUERDO SECRETARIAL SEP',
        8 => 'DECRETO DE CREACIÓN',
        9 => 'OTRO',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('autorizaciones_reconocimiento', 'protegido')) {
            Schema::table('autorizaciones_reconocimiento', function (Blueprint $table) {
                $table->boolean('protegido')->default(false)->after('nombre');
            });
        }

        // old id → id oficial, según la clave que traía cada fila.
        $mapa = [];
        foreach (DB::table('autorizaciones_reconocimiento')->get(['id', 'clave']) as $fila) {
            $mapa[$fila->id] = self::A_OFICIAL[$fila->clave] ?? 9;
        }

        // La FK impide reescribir ids; se quita, se remapea y se repone.
        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->dropForeign(['autorizacion_reconocimiento_id']);
        });

        // Un solo UPDATE con CASE: lee el id ORIGINAL de cada plan (evita que un
        // remapeo pise a otro si se hicieran en cadena).
        if ($mapa !== []) {
            $case = 'CASE autorizacion_reconocimiento_id';
            foreach ($mapa as $viejo => $nuevo) {
                $case .= " WHEN {$viejo} THEN {$nuevo}";
            }
            $case .= ' ELSE 9 END';
            DB::statement("UPDATE planes_estudio SET autorizacion_reconocimiento_id = {$case}");
        }

        // Reemplazo del catálogo por los 9 oficiales con ids fijos.
        DB::table('autorizaciones_reconocimiento')->delete();
        $ahora = now();
        foreach (self::OFICIALES as $id => $nombre) {
            DB::table('autorizaciones_reconocimiento')->insert([
                'id' => $id,
                'clave' => (string) $id,
                'nombre' => $nombre,
                'protegido' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->foreign('autorizacion_reconocimiento_id')->references('id')->on('autorizaciones_reconocimiento');
        });
    }

    /**
     * No se restauran las claves semánticas ni las entradas no oficiales
     * (se perdió esa información al alinear). Solo se retira `protegido`.
     */
    public function down(): void
    {
        if (Schema::hasColumn('autorizaciones_reconocimiento', 'protegido')) {
            Schema::table('autorizaciones_reconocimiento', function (Blueprint $table) {
                $table->dropColumn('protegido');
            });
        }
    }
};
