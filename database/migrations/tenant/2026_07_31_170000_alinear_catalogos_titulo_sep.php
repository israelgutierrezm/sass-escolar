<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea a la SEP tres catálogos que alimentan el título electrónico, verificados
 * contra los catálogos oficiales (catalogos_para_instituciones):
 *
 *  - `cargos`: idCargo oficial (0–11). Se pone el identificador a los que ya
 *    existen (por nombre) y se siembran los 12 oficiales protegidos, para que el
 *    FirmaResponsable/@idCargo salga correcto sin depender del id de fila.
 *  - `modalidades_titulacion`: corrige el texto del id 3 a "POR ESTUDIOS DE
 *    POSGRADOS" (plural, como el catálogo oficial).
 *  - `niveles_estudio`: agrega SECUNDARIA (idTipoEstudioAntecedente 6) y
 *    EQUIVALENTE A BACHILLERATO (5) como niveles borrados lógicamente —así NO
 *    aparecen como programas ofertables pero SÍ en el desplegable de antecedente
 *    (que usa withTrashed). El ejemplo oficial usa SECUNDARIA como antecedente.
 */
return new class extends Migration
{
    private const CARGOS = [
        0 => 'SECRETARIO DE EDUCACIÓN PÚBLICA',
        1 => 'DIRECTOR',
        2 => 'SUBDIRECTOR',
        3 => 'RECTOR',
        4 => 'VICERRECTOR',
        5 => 'RESPONSABLE DE EXPEDICIÓN',
        6 => 'SECRETARIO GENERAL',
        7 => 'AUTORIDAD LOCAL',
        8 => 'AUTORIDAD FEDERAL',
        9 => 'DIRECTOR GENERAL',
        10 => 'RECTOR GENERAL',
        11 => 'TITULAR DE LA AUTORIDAD EDUCATIVA FEDERAL EN LA CIUDAD DE MÉXICO',
    ];

    public function up(): void
    {
        $ahora = now();

        // Cargos: alinear existentes por nombre y sembrar los oficiales que falten.
        foreach (self::CARGOS as $idCargo => $nombre) {
            $existente = DB::table('cargos')->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($nombre)])->first();

            if ($existente !== null) {
                DB::table('cargos')->where('id', $existente->id)->update([
                    'identificador' => (string) $idCargo,
                    'protegido' => true,
                    'updated_at' => $ahora,
                ]);
            } else {
                DB::table('cargos')->insert([
                    'clave' => 'SEP'.$idCargo,
                    'identificador' => (string) $idCargo,
                    'nombre' => $nombre,
                    'protegido' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        // Modalidad id 3: texto oficial en plural.
        DB::table('modalidades_titulacion')->where('identificador', 3)
            ->update(['descripcion' => 'POR ESTUDIOS DE POSGRADOS']);

        // Niveles antecedente que la escuela no oferta como programa: se agregan
        // borrados lógicamente (aparecen en el desplegable de antecedente vía
        // withTrashed, no en los de programas).
        if (Schema::hasColumn('niveles_estudio', 'identificador_titulo')) {
            foreach ([
                ['clave' => 'equivalente_bachillerato', 'nombre' => 'EQUIVALENTE A BACHILLERATO', 'orden' => 90, 'titulo' => 5],
                ['clave' => 'secundaria', 'nombre' => 'SECUNDARIA', 'orden' => 91, 'titulo' => 6],
            ] as $n) {
                $existe = DB::table('niveles_estudio')->where('clave', $n['clave'])->exists();
                if (! $existe) {
                    DB::table('niveles_estudio')->insert([
                        'clave' => $n['clave'],
                        'nombre' => $n['nombre'],
                        'orden' => $n['orden'],
                        'identificador_titulo' => $n['titulo'],
                        'protegido' => true,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                        'deleted_at' => $ahora,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('cargos')->whereIn('clave', array_map(fn ($i) => 'SEP'.$i, array_keys(self::CARGOS)))->delete();
        DB::table('niveles_estudio')->whereIn('clave', ['equivalente_bachillerato', 'secundaria'])->forceDelete();
    }
};
