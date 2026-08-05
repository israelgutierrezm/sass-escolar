<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Certificado y título dejan de ser dos permisos: son uno.
 *
 * Se separaron pensando en programas que dieran certificado sin llegar a
 * título. No existen: una carrera con RVOE emite las dos cosas —el certificado
 * acredita las materias y el título acredita haberla terminado, y no hay
 * titulación sin certificado ni certificado que no acabe en título—. Tenerlos
 * aparte sólo abría la puerta a media configuración: alguien apaga uno, el otro
 * se queda encendido y el alumno aparece en un lote y no en el otro sin que
 * nadie sepa por qué.
 *
 * La unión y no la intersección: si cualquiera de los dos estaba encendido, la
 * carrera emite. Apagar por error lo que la escuela sí expedía dejaría a sus
 * egresados sin documentos; encenderlo de más sólo los deja visibles para que
 * una persona decida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->boolean('emite_documentos_oficiales')->default(true)->after('imagen_url');
        });

        DB::table('carreras')->update([
            'emite_documentos_oficiales' => DB::raw('(emite_certificado OR emite_titulo)'),
        ]);

        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->dropColumn(['emite_certificado', 'emite_titulo']);
        });
    }

    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->boolean('emite_certificado')->default(true)->after('imagen_url');
            $tabla->boolean('emite_titulo')->default(true)->after('emite_certificado');
        });

        DB::table('carreras')->update([
            'emite_certificado' => DB::raw('emite_documentos_oficiales'),
            'emite_titulo' => DB::raw('emite_documentos_oficiales'),
        ]);

        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->dropColumn('emite_documentos_oficiales');
        });
    }
};
