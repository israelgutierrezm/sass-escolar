<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos ajustes de catálogo/plan:
 *
 *  - `tipos_asignatura` gana `protegido`: sus valores oficiales (obligatoria,
 *    optativa, adicional, complementaria) no se editan ni se eliminan.
 *  - `planes_estudio.total_creditos` pasa a nullable: el plan se define por el
 *    número de asignaturas para completar la carrera, no por un total de
 *    créditos declarado a mano; ese campo se quitó del formulario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_asignatura', function (Blueprint $table) {
            $table->boolean('protegido')->default(false);
        });

        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->float('total_creditos')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tipos_asignatura', function (Blueprint $table) {
            $table->dropColumn('protegido');
        });

        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->float('total_creditos')->nullable(false)->change();
        });
    }
};
