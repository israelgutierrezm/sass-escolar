<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Alinea `tipos_periodo` al catálogo OFICIAL de la SEP con sus ids exactos.
 * Los tenants nuevos ya nacen así (seeder con `sembrarFijos`); esta migración
 * corrige los tenants viejos, sembrados antes con ids 1..n.
 *
 *   91 SEMESTRE  92 BIMESTRE  93 CUATRIMESTRE  94 TETRAMESTRE
 *   260 TRIMESTRE  261 MODULAR  262 ANUAL
 *
 * Todos los periodos previos tienen equivalente oficial (no hay «extras»), así
 * que se reemplaza el catálogo completo tras remapear los planes que lo usan.
 */
return new class extends Migration
{
    /** clave previa → id oficial SEP. */
    private const A_OFICIAL = [
        'semestral' => 91, 'semestre' => 91,
        'bimestral' => 92, 'bimestre' => 92,
        'cuatrimestral' => 93, 'cuatrimestre' => 93,
        'tetramestral' => 94, 'tetramestre' => 94,
        'trimestral' => 260, 'trimestre' => 260,
        'modular' => 261,
        'anual' => 262,
    ];

    private const OFICIALES = [
        91 => 'SEMESTRE', 92 => 'BIMESTRE', 93 => 'CUATRIMESTRE', 94 => 'TETRAMESTRE',
        260 => 'TRIMESTRE', 261 => 'MODULAR', 262 => 'ANUAL',
    ];

    public function up(): void
    {
        // old id → id oficial (por clave, o por id si ya es oficial).
        $mapa = [];
        foreach (DB::table('tipos_periodo')->get(['id', 'clave']) as $fila) {
            $oficial = self::A_OFICIAL[$fila->clave] ?? (isset(self::OFICIALES[$fila->id]) ? $fila->id : null);
            if ($oficial === null) {
                throw new RuntimeException("tipo de periodo id {$fila->id} (clave «{$fila->clave}») no tiene equivalente oficial SEP.");
            }
            $mapa[$fila->id] = $oficial;
        }

        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->dropForeign(['tipo_periodo_id']);
        });

        if ($mapa !== []) {
            $case = 'CASE tipo_periodo_id';
            foreach ($mapa as $viejo => $nuevo) {
                $case .= " WHEN {$viejo} THEN {$nuevo}";
            }
            $case .= ' ELSE tipo_periodo_id END';
            DB::statement("UPDATE planes_estudio SET tipo_periodo_id = {$case}");
        }

        DB::table('tipos_periodo')->delete();
        $ahora = now();
        foreach (self::OFICIALES as $id => $nombre) {
            DB::table('tipos_periodo')->insert([
                'id' => $id,
                'clave' => (string) $id,
                'nombre' => $nombre,
                'protegido' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->foreign('tipo_periodo_id')->references('id')->on('tipos_periodo');
        });
    }

    public function down(): void
    {
        // El catálogo oficial no se revierte (se perdieron las claves previas).
    }
};
