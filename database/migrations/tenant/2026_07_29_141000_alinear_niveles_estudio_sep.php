<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Alinea `niveles_estudio` a los ids OFICIALES de la SEP. Los tenants nuevos ya
 * nacen así (seeder); esto corrige los viejos (sembrados con ids 1..n copiados
 * de la landlord).
 *
 *   81 LICENCIATURA  82 MAESTRÍA  83 PROFESIONAL ASOCIADO
 *   84 TÉCNICO SUPERIOR UNIVERSITARIO  85 ESPECIALIDAD  95 DOCTORADO
 *
 * A diferencia de periodo/autorización, aquí NO se reemplaza el catálogo: se
 * renumeran EN SITIO los seis niveles oficiales y se CONSERVAN los niveles no
 * oficiales que un tenant pudiera tener (p. ej. Bachillerato, Diplomado), porque
 * pueden estar en uso (ciclos) y no existe un equivalente al que mandarlos. Se
 * remapean `carreras` (sin FK) y el pivote `ciclo_nivel` (con FK cascade).
 */
return new class extends Migration
{
    /** clave previa → [idOficial, nombre, orden, claveSat]. */
    private const OFICIAL = [
        'profesional_asociado' => [83, 'PROFESIONAL ASOCIADO', 1, '86121804'],
        'tecnico_superior' => [84, 'TÉCNICO SUPERIOR UNIVERSITARIO', 2, '86121803'],
        'licenciatura' => [81, 'LICENCIATURA', 3, '86121804'],
        'especialidad' => [85, 'ESPECIALIDAD', 4, '86121804'],
        'maestria' => [82, 'MAESTRÍA', 5, '86121804'],
        'doctorado' => [95, 'DOCTORADO', 6, '86121804'],
    ];

    public function up(): void
    {
        // old id → new id oficial, sólo para los seis oficiales (por clave).
        $mapa = [];
        foreach (DB::table('niveles_estudio')->get(['id', 'clave']) as $fila) {
            if (isset(self::OFICIAL[$fila->clave])) {
                $nuevo = self::OFICIAL[$fila->clave][0];
                if ($nuevo !== $fila->id) {
                    $mapa[$fila->id] = $nuevo;
                }
            }
        }

        // El pivote tiene FK cascade; se quita para poder renumerar.
        Schema::table('ciclo_nivel', function (Blueprint $table) {
            $table->dropForeign(['nivel_estudios_id']);
        });

        // Renumerar los niveles oficiales en sitio (los no oficiales se quedan).
        foreach (self::OFICIAL as $clave => [$id, $nombre, $orden, $claveSat]) {
            DB::table('niveles_estudio')->where('clave', $clave)->update([
                'id' => $id,
                'clave' => (string) $id,
                'nombre' => $nombre,
                'orden' => $orden,
                'clave_sat' => $claveSat,
                'protegido' => true,
                'updated_at' => now(),
            ]);
        }

        // Remapear referencias (ELSE conserva las de niveles no oficiales).
        if ($mapa !== []) {
            $case = 'CASE nivel_estudios_id';
            foreach ($mapa as $viejo => $nuevo) {
                $case .= " WHEN {$viejo} THEN {$nuevo}";
            }
            $case .= ' ELSE nivel_estudios_id END';
            DB::statement("UPDATE carreras SET nivel_estudios_id = {$case}");
            DB::statement("UPDATE ciclo_nivel SET nivel_estudios_id = {$case}");
        }

        Schema::table('ciclo_nivel', function (Blueprint $table) {
            $table->foreign('nivel_estudios_id')->references('id')->on('niveles_estudio')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // No se revierte (se perdieron las claves textuales previas).
    }
};
