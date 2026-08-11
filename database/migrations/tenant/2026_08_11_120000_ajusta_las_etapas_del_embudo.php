<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El embudo se queda en cinco etapas: se retira «En evaluación» y la última
 * pasa a llamarse «Listo para inscribir».
 *
 * ── Por qué la última cambia de nombre Y de clave ─────────────────────────
 * Se llamaba «Inscrito», con clave `inscrito`, y ahora significa lo contrario:
 * que está LISTO para inscribirse, no que ya lo hizo. Inscribirlo es otro acto
 * —el que genera su matrícula— y ocurre después.
 *
 * Dejar la clave en `inscrito` para una etapa que significa «todavía no» es la
 * clase de mentira que muerde meses después, cuando alguien escriba
 * `where('clave', 'inscrito')` creyendo que cuenta alumnos. Se comprobó que
 * NINGÚN código consulta esta clave antes de cambiarla.
 *
 * ── «En evaluación» se RETIRA, no se borra ────────────────────────────────
 * Borrarla de verdad rompería dos cosas: los prospectos que estén ahí y los
 * seguimientos que congelaron esa etapa —«se le llamó estando en evaluación»
 * sigue siendo cierto aunque la etapa ya no exista—. Con borrado lógico
 * desaparece de los desplegables y del embudo, y el historial la sigue
 * resolviendo (la relación la lee `withTrashed`).
 *
 * ── A dónde van los que estaban ahí ───────────────────────────────────────
 * A la etapa ANTERIOR, no a la siguiente. Moverlos a «Aceptado» sería afirmar
 * que se les aceptó, y eso no lo decidió nadie: el sistema no puede inventar
 * una decisión de admisión para no dejar un hueco.
 */
return new class extends Migration
{
    public function up(): void
    {
        $evaluacion = DB::table('etapas_crm')
            ->where('clave', 'evaluacion')->whereNull('deleted_at')->first();

        if ($evaluacion !== null) {
            // La anterior por ORDEN, no por id: una escuela pudo reordenarlas.
            $anterior = DB::table('etapas_crm')
                ->whereNull('deleted_at')
                ->where('orden', '<', $evaluacion->orden)
                ->orderByDesc('orden')
                ->value('id');

            if ($anterior !== null) {
                DB::table('aspirantes')
                    ->where('etapa_crm_id', $evaluacion->id)
                    ->update(['etapa_crm_id' => $anterior, 'updated_at' => now()]);

                // La etapa en la que nace un prospecto del formulario público
                // también puede apuntar aquí.
                DB::table('formularios_publicos')
                    ->where('etapa_crm_id', $evaluacion->id)
                    ->update(['etapa_crm_id' => $anterior, 'updated_at' => now()]);
            }

            DB::table('etapas_crm')
                ->where('id', $evaluacion->id)
                ->update(['deleted_at' => now()]);
        }

        DB::table('etapas_crm')
            ->where('clave', 'inscrito')
            ->update([
                'clave' => 'listo_para_inscribir',
                'nombre' => 'Listo para inscribir',
                'updated_at' => now(),
            ]);

        $this->renumerar();
    }

    public function down(): void
    {
        DB::table('etapas_crm')
            ->where('clave', 'listo_para_inscribir')
            ->update(['clave' => 'inscrito', 'nombre' => 'Inscrito', 'updated_at' => now()]);

        DB::table('etapas_crm')
            ->where('clave', 'evaluacion')
            ->update(['deleted_at' => null, 'updated_at' => now()]);

        $this->renumerar();

        // Los prospectos NO vuelven: no hay forma de saber cuáles estaban en
        // evaluación y cuáles ya venían de documentación.
    }

    /**
     * El orden queda 1, 2, 3… sin huecos.
     *
     * Retirar la cuarta dejaba la serie en 1,2,3,5,6. Nadie la ve —la pantalla
     * numera por posición— pero el hueco es una trampa para el próximo que
     * ordene por `orden` suponiendo que es consecutivo.
     */
    private function renumerar(): void
    {
        $vivas = DB::table('etapas_crm')->whereNull('deleted_at')->orderBy('orden')->pluck('id');

        foreach ($vivas as $posicion => $id) {
            DB::table('etapas_crm')->where('id', $id)->update(['orden' => $posicion + 1]);
        }
    }
};
