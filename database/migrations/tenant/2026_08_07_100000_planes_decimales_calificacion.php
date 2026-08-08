<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con cuántos decimales se califica.
 *
 * Hasta ahora la escala del plan decía de cuánto a cuánto —0 a 10, 5 a 10— pero
 * no con qué precisión, así que `numeric` aceptaba un 8.756 en un acta. En
 * México lo normal es entero (8) o un decimal (8.5); algunas escuelas usan dos.
 *
 * ── Por qué en el PLAN y no en la carrera ──────────────────────────────────
 * Se pidió «por carrera», pero la escala completa —mínima, máxima y mínima
 * aprobatoria— ya vive aquí desde el principio, y una misma carrera tiene
 * varios planes: el 2018 podía calificar de 5 a 10 y el 2022 de 0 a 100.
 * Poner los decimales un nivel más arriba dejaría la precisión y los límites en
 * sitios distintos, y el día que se contradigan no habría forma de saber cuál
 * manda. Configurarlo para toda una carrera se resuelve en la pantalla, que
 * puede aplicarlo a sus planes de un golpe.
 *
 * ── Por qué 2 por omisión ──────────────────────────────────────────────────
 * Es el más permisivo de los tres valores que se usan, así que ninguna captura
 * que hoy pasa deja de pasar de un día para otro. Quien quiera enteros lo
 * configura; nadie se encuentra sus actas rechazadas sin haber pedido nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->unsignedTinyInteger('decimales_calificacion')
                ->default(2)
                ->after('calificacion_minima_aprobatoria');
        });
    }

    public function down(): void
    {
        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->dropColumn('decimales_calificacion');
        });
    }
};
