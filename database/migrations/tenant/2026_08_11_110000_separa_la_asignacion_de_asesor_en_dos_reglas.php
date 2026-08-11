<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La asignación de asesor se parte en DOS reglas, porque son dos decisiones.
 *
 * ── Qué estaba mal ────────────────────────────────────────────────────────
 * Era un solo desplegable con tres opciones excluyentes: manual, «se lo queda
 * quien lo registra» o «por turno». Y no son excluyentes: lo normal es querer
 * que el asesor conserve lo que él mismo trae de una feria Y que lo que cae por
 * la web se reparta solo. El desplegable obligaba a renunciar a una de las dos.
 *
 * Ahora son:
 *
 * - `aspirante.asesor_se_lo_queda_quien_registra` (sí/no)
 * - `aspirante.asignacion_de_asesor` (manual | secuencial) — qué pasa con el
 *   resto.
 *
 * ── El valor viejo se traduce, no se pierde ───────────────────────────────
 * Quien tuviera guardado `quien_registra` tenía de hecho las dos cosas: el
 * servicio se lo daba al capturador y, si no era asesor, caía al reparto por
 * turno. O sea que su equivalente exacto es el interruptor encendido MÁS el
 * modo secuencial, y así se migra. Dejarlo como estaba habría caído al valor
 * por omisión —manual— y la escuela habría dejado de repartir sin enterarse.
 */
return new class extends Migration
{
    private const INTERRUPTOR = 'aspirante.asesor_se_lo_queda_quien_registra';

    private const MODO = 'aspirante.asignacion_de_asesor';

    public function up(): void
    {
        if (! $this->hayTablaDeAjustes()) {
            return;
        }

        $guardado = DB::table('configuraciones')->where('clave', self::MODO)->value('valor');

        if ($guardado !== 'quien_registra') {
            return;
        }

        DB::table('configuraciones')->where('clave', self::MODO)->update([
            'valor' => 'secuencial',
            'updated_at' => now(),
        ]);

        // `clave` es la llave primaria de la tabla: `updateOrInsert` la usa
        // para no duplicar si la escuela ya había tocado el interruptor.
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => self::INTERRUPTOR],
            ['valor' => '1', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        // No se deshace: volver a fundir dos reglas en una perdería la que
        // sobre, y no hay forma de saber cuál eligió la escuela después.
    }

    /**
     * La tabla puede llamarse distinto o no existir todavía en una escuela
     * recién creada: se comprueba antes de tocarla.
     */
    private function hayTablaDeAjustes(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('configuraciones')
            && \Illuminate\Support\Facades\Schema::hasColumn('configuraciones', 'clave');
    }
};
