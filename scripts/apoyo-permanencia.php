<?php

/**
 * Dejar el módulo de permanencia en cero, en el ORDEN que las foráneas admiten.
 *
 * Se carga con `require __DIR__.'/apoyo-permanencia.php';` dentro de la
 * transacción de la suite, igual que `apoyo-roles.php`.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * Varias suites del módulo prueban ARITMÉTICA —«esta bandeja trae dos», «este
 * puntaje suma 18»—, y eso sólo se puede afirmar sabiendo qué hay. Así que
 * parten de cero. El problema es CÓMO: cada fase agregó una tabla que cuelga de
 * `alertas` o de `corridas_evaluacion`, y un `Alerta::query()->forceDelete()`
 * pelado revienta con **1451** en cuanto la escuela tiene un caso abierto o un
 * riesgo calculado.
 *
 * Y revienta **sólo donde HAY datos** de esas tablas: no en un demo recién
 * migrado, y sí en la escuela del cliente. Este proyecto ya se cobró esa lección
 * dos veces —con `caso_alerta` y con `riesgo_matricula`— y volvió a cobrársela
 * al mirar el módulo en el navegador: cuatro suites en verde se pusieron en rojo
 * en cuanto la escuela tuvo su primer caso.
 *
 * Repetir el bloque en cada suite es como se llega a que una se quede sin la
 * tabla que la fase siguiente agregue. Vive aquí una sola vez.
 */

use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use Illuminate\Support\Facades\DB;

if (! function_exists('limpiarPermanencia')) {
    /**
     * Borra casos, señales, riesgo y reglas, de las hojas hacia la raíz.
     *
     * @param  bool  $conReglas  también las reglas y sus versiones. Se deja
     *                           fuera cuando la suite las quiere conservar —la
     *                           de indicadores mide sobre las sembradas—.
     */
    function limpiarPermanencia(bool $conReglas = true): void
    {
        // ── Lo que cuelga de un CASO ──────────────────────────────────────
        DB::table('accesos_caso')->delete();
        DB::table('transiciones_caso')->delete();
        DB::table('tareas_caso')->delete();
        DB::table('intervenciones')->delete();
        DB::table('caso_equipo')->delete();
        DB::table('caso_alerta')->delete();

        /*
         * `casos_permanencia.caso_origen_id` apunta a su PROPIA tabla —una
         * reapertura nombra al caso anterior—, así que un `DELETE` pelado
         * revienta contra sí mismo. Se suelta la columna antes.
         */
        DB::table('casos_permanencia')->update(['caso_origen_id' => null]);
        DB::table('casos_permanencia')->delete();

        // ── Lo que cuelga de los AVISOS del módulo ────────────────────────
        if (DB::getSchemaBuilder()->hasTable('avisos_permanencia')) {
            DB::table('avisos_permanencia')->delete();
        }

        // ── Las señales ───────────────────────────────────────────────────
        Alerta::query()->forceDelete();

        /*
         * `riesgo_matricula.corrida_id` apunta a `corridas_evaluacion`, así que
         * el riesgo va ANTES que las corridas. Lo agregó la fase 4 y tumbó las
         * suites de las fases 1 a 3.
         */
        DB::table('riesgo_matricula')->delete();
        DB::table('corridas_evaluacion')->delete();

        if ($conReglas) {
            DB::table('exclusiones_regla_alerta')->delete();
            ReglaAlertaVersion::query()->forceDelete();
            ReglaAlerta::query()->forceDelete();
        }
    }
}
