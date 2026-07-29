<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La clave SAT (ClaveProdServ para el CFDI de colegiaturas) pasa de la carrera
 * al NIVEL DE ESTUDIOS: el SAT la asigna por nivel, no por carrera, así que
 * repetirla en cada carrera era duplicar un dato que en realidad depende del
 * nivel. Ahora vive una sola vez, en el nivel.
 *
 * Regla oficial: el Técnico Superior Universitario lleva 86121803; todos los
 * demás niveles, 86121804. Se cubre la clave nueva («84») y la vieja
 * («tecnico_superior») para cualquier escuela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('niveles_estudio', function (Blueprint $tabla) {
            $tabla->string('clave_sat', 15)->nullable()->after('nombre');
        });

        // Backfill por la regla del SAT según la clave del nivel.
        DB::table('niveles_estudio')->update([
            'clave_sat' => DB::raw(
                "CASE WHEN clave IN ('84', 'tecnico_superior') THEN '86121803' ELSE '86121804' END"
            ),
        ]);

        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->dropColumn('clave_sat');
        });
    }

    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->string('clave_sat', 15)->nullable()->after('nivel_estudios_id');
        });

        // Se devuelve a cada carrera la clave SAT de su nivel.
        DB::statement(
            'UPDATE carreras c JOIN niveles_estudio n ON n.id = c.nivel_estudios_id
             SET c.clave_sat = n.clave_sat'
        );

        Schema::table('niveles_estudio', function (Blueprint $tabla) {
            $tabla->dropColumn('clave_sat');
        });
    }
};
