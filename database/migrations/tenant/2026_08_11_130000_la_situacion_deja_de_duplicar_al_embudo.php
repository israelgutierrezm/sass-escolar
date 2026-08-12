<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La SITUACIÓN del aspirante se queda con lo que el embudo no puede decir.
 *
 * ── El problema: dos verdades sobre lo mismo ──────────────────────────────
 * `situaciones_aspirante` traía Prospecto, En proceso, Aceptado, Rechazado e
 * Inscrito. `etapas_crm` trae el recorrido: Contacto inicial → … → Listo para
 * inscribir, pasando por Aceptado.
 *
 * O sea que «Aceptado» vivía en los DOS catálogos, se editaba en dos pantallas
 * distintas —la situación en «Editar aspirante», la etapa en la ficha— y nada
 * las mantenía sincronizadas: se podía tener situación «Prospecto» con etapa
 * «Aceptado» sin que el sistema dijera nada. Dos campos que dicen lo mismo y
 * pueden contradecirse no son redundancia: son una discusión sin árbitro.
 *
 * ── El reparto que queda ──────────────────────────────────────────────────
 * - La ETAPA dice por dónde va el recorrido. Es lo que se mueve a diario.
 * - La SITUACIÓN dice sólo el DESENLACE, que el embudo no puede expresar:
 *   sigue vivo (Prospecto), se cayó (Rechazado) o llegó (Inscrito).
 *
 * «En proceso» y «Aceptado» eran puntos del recorrido disfrazados de
 * desenlace, y se retiran.
 *
 * ── Se retiran, no se borran ──────────────────────────────────────────────
 * Borrado lógico: desaparecen de los desplegables y del filtro, y si alguna
 * escuela guardó algo que las mencione, sigue resolviéndose.
 *
 * ── A dónde van los que estaban ahí ───────────────────────────────────────
 * A «Prospecto», que es el estado de quien sigue en el proceso sin desenlace.
 * No se pierde nada: dónde está exactamente cada uno lo dice su etapa, que es
 * precisamente el dato que hacía redundante a la situación.
 */
return new class extends Migration
{
    private const RETIRADAS = ['en_proceso', 'aceptado'];

    public function up(): void
    {
        $prospecto = DB::table('situaciones_aspirante')
            ->where('clave', 'prospecto')->whereNull('deleted_at')->value('id');

        if ($prospecto === null) {
            // Sin a dónde mandarlos, no se toca nada: dejarlos apuntando a una
            // situación retirada sería peor que no hacer la limpieza.
            return;
        }

        $ids = DB::table('situaciones_aspirante')
            ->whereIn('clave', self::RETIRADAS)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('aspirantes')
            ->whereIn('situacion_id', $ids)
            ->update(['situacion_id' => $prospecto, 'updated_at' => now()]);

        DB::table('situaciones_aspirante')
            ->whereIn('id', $ids)
            ->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        DB::table('situaciones_aspirante')
            ->whereIn('clave', self::RETIRADAS)
            ->update(['deleted_at' => null, 'updated_at' => now()]);

        // Los aspirantes NO vuelven: no hay forma de saber cuáles estaban «en
        // proceso» y cuáles ya eran prospectos.
    }
};
