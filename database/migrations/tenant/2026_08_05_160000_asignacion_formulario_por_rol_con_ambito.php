<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quién se le muestra un formulario: primero el ROL, y sólo después el ámbito.
 *
 * `aplica_a_tipo` ofrecía cuatro destinos como hermanos —rol, nivel, carrera,
 * oferta— y eso decía algo que no es cierto: que un formulario asignado «a la
 * carrera de Derecho» se le muestra a alguien, cuando lo que falta es saber a
 * QUIÉN de esa carrera. Nivel, carrera y oferta no son destinatarios: son
 * recortes del destinatario.
 *
 * Y ese recorte sólo tiene sentido para aspirantes y alumnos, que son los
 * únicos que tienen carrera. Es además la razón de fondo: el aspirante se
 * convierte en alumno y su expediente de formularios viaja con él, así que los
 * dos roles se recortan con el mismo criterio o el expediente se parte al
 * cruzar la frontera.
 *
 * Queda: `rol_id` —obligatorio, a quién— más un ámbito opcional que lo acota.
 * Un solo ámbito y no tres, porque son niveles del mismo árbol: elegir la
 * oferta ya implica su carrera, y la carrera ya implica su nivel.
 *
 * No hay backfill que hacer: la funcionalidad se soltó sin que nadie llegara a
 * asignar nada (cero filas en la tabla). Si las hubiera, las de tipo `rol` se
 * moverían a `rol_id` y las académicas no tendrían a quién apuntar —justo el
 * agujero que esta migración cierra—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formulario_asignacion', function (Blueprint $table) {
            $table->foreignId('rol_id')->nullable()->after('formulario_id')->constrained('roles');
            $table->string('ambito_tipo', 20)->nullable()->after('rol_id');
            $table->unsignedBigInteger('ambito_id')->nullable()->after('ambito_tipo');
        });

        // Lo que hubiera apuntado a un rol conserva su destinatario.
        DB::table('formulario_asignacion')
            ->where('aplica_a_tipo', 'rol')
            ->update(['rol_id' => DB::raw('aplica_a_id')]);

        DB::table('formulario_asignacion')
            ->whereIn('aplica_a_tipo', ['nivel', 'carrera', 'oferta'])
            ->update([
                'ambito_tipo' => DB::raw('aplica_a_tipo'),
                'ambito_id' => DB::raw('aplica_a_id'),
            ]);

        Schema::table('formulario_asignacion', function (Blueprint $table) {
            $table->dropColumn(['aplica_a_tipo', 'aplica_a_id']);
        });
    }

    public function down(): void
    {
        Schema::table('formulario_asignacion', function (Blueprint $table) {
            $table->string('aplica_a_tipo', 20)->nullable()->after('formulario_id');
            $table->unsignedBigInteger('aplica_a_id')->nullable()->after('aplica_a_tipo');
        });

        /*
         * La vuelta atrás pierde información y no puede no perderla: una
         * asignación «alumnos de Derecho» son dos datos y allá cabe uno solo.
         * Gana el ámbito cuando existe, porque era el más específico.
         */
        DB::table('formulario_asignacion')->whereNotNull('ambito_tipo')->update([
            'aplica_a_tipo' => DB::raw('ambito_tipo'),
            'aplica_a_id' => DB::raw('ambito_id'),
        ]);

        DB::table('formulario_asignacion')->whereNull('ambito_tipo')->update([
            'aplica_a_tipo' => 'rol',
            'aplica_a_id' => DB::raw('rol_id'),
        ]);

        Schema::table('formulario_asignacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rol_id');
            $table->dropColumn(['ambito_tipo', 'ambito_id']);
        });
    }
};
