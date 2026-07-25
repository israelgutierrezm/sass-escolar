<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El ciclo captura AÑO y NÚMERO DE PERIODO por separado, y su clave se genera
 * de ambos (2026 + 1 → «2026-1»). Además puede ligar un NIVEL DE ESTUDIOS
 * opcional que acota qué grupos caben dentro.
 *
 * La clave dejó de teclearse a mano: se arma. Las dos columnas nuevas son
 * nullable para no romper los ciclos que ya existen, pero se les hace backfill
 * leyendo su clave —el año son los primeros cuatro dígitos y el periodo el
 * número tras el último separador—, así los viejos también quedan con año y
 * periodo coherentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ciclos', function (Blueprint $tabla) {
            $tabla->unsignedSmallInteger('anio')->nullable()->after('clave');
            $tabla->unsignedTinyInteger('numero_periodo')->nullable()->after('anio');
            // Nivel de estudios (catálogo tenant). Nullable: un ciclo puede no
            // acotar por nivel. `nullOnDelete` para que borrar un nivel no deje
            // la referencia colgando.
            $tabla->foreignId('nivel_estudios_id')->nullable()->after('numero_periodo')
                ->constrained('niveles_estudio')->nullOnDelete();
        });

        // Backfill de los ciclos existentes desde su clave.
        foreach (DB::table('ciclos')->get(['id', 'clave']) as $ciclo) {
            $anio = preg_match('/(\d{4})/', (string) $ciclo->clave, $m) ? (int) $m[1] : null;
            $periodo = preg_match('/[\/-](\d+)\s*$/', (string) $ciclo->clave, $p) ? (int) $p[1] : null;

            DB::table('ciclos')->where('id', $ciclo->id)->update([
                'anio' => $anio,
                // El periodo se acota a 1..4; si la clave traía algo raro, se
                // deja null para que se corrija al editar.
                'numero_periodo' => ($periodo !== null && $periodo >= 1 && $periodo <= 4) ? $periodo : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ciclos', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('nivel_estudios_id');
            $tabla->dropColumn(['anio', 'numero_periodo']);
        });
    }
};
