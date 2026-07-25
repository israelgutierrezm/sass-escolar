<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La modalidad pasa a distinguir una oferta.
 *
 * El índice único era `carrera+plan+campus+turno`: dos ofertas del mismo
 * programa en el mismo campus y turno no podían coexistir aunque fueran de
 * modalidad distinta. Pero el cliente pidió justo eso —una carrera ofertada en
 * presencial Y en línea en el mismo campus—, así que la modalidad entra a la
 * llave. «Lo mismo en presencial» y «lo mismo en línea» son ofertas distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El nuevo índice se crea ANTES de tirar el viejo: este último sostiene
        // la FK de `carrera_id` (es el índice con esa columna a la izquierda), y
        // MySQL no deja quitarlo mientras sea el único que la respalda. El nuevo
        // también empieza por `carrera_id`, así que puede tomar ese relevo.
        Schema::table('oferta', function (Blueprint $tabla) {
            $tabla->unique(['carrera_id', 'plan_id', 'campus_id', 'turno_id', 'modalidad']);
        });

        Schema::table('oferta', function (Blueprint $tabla) {
            $tabla->dropUnique('oferta_carrera_id_plan_id_campus_id_turno_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('oferta', function (Blueprint $tabla) {
            $tabla->unique(['carrera_id', 'plan_id', 'campus_id', 'turno_id']);
        });

        Schema::table('oferta', function (Blueprint $tabla) {
            $tabla->dropUnique(['carrera_id', 'plan_id', 'campus_id', 'turno_id', 'modalidad']);
        });
    }
};
