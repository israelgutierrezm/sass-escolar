<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cambiar un índice que, sin decirlo, sostiene una llave foránea.
 *
 * ── La trampa ──────────────────────────────────────────────────────────────
 * MySQL exige que toda llave foránea esté cubierta por algún índice, y acepta
 * cualquiera que EMPIECE por su columna. Así que un índice compuesto como
 * `(curso_id, tema)` termina sosteniendo la foránea de `curso_id` sin que nada
 * lo diga. El día que se quiere tirar —para quitar `tema`, para cambiar la
 * llave única— MySQL responde:
 *
 *     Cannot drop index '…': needed in a foreign key constraint
 *
 * Ya mordió dos veces en este proyecto: al separar el pase de lista en teoría y
 * práctica, y al retirar `tema` de los reactivos. Las dos veces se resolvió
 * igual —crear el sustituto ANTES de tirar el viejo—, y las dos veces se
 * descubrió fallando. Esta clase es esa solución escrita una sola vez, para que
 * la tercera migración la llame en un renglón en vez de volver a tropezar.
 *
 * ── Por qué no se indexan todas las foráneas «por si acaso» ────────────────
 * Un índice de más se paga en CADA escritura, para siempre, a cambio de evitar
 * un problema que aparece solo cuando se cambia el esquema y se arregla en dos
 * líneas. En una tabla como `mensajes` sería cobrarle a cada mensaje del chat
 * el precio de una migración futura. Se paga cuando hace falta, no antes.
 */
class IndiceQueSostieneUnaFk
{
    /**
     * Tira un índice dejando la llave foránea cubierta por otro.
     *
     * Crea primero un índice simple sobre la columna de la foránea, y solo
     * entonces retira el viejo. El orden es lo único que importa: al revés,
     * MySQL rechaza la operación y la migración muere a la mitad.
     *
     * Es idempotente: si el índice simple ya existe no se duplica, y si el
     * viejo ya no está no se reclama. Un reintento tras un fallo parcial no
     * choca contra su propio trabajo.
     *
     * @param  string  $tabla  la tabla
     * @param  array<int, string>|string  $indiceViejo  columnas del índice a
     *                                                  retirar, o su nombre
     * @param  string  $columnaFk  la columna con la llave foránea
     */
    public static function reemplazar(string $tabla, array|string $indiceViejo, string $columnaFk): void
    {
        $sustituto = "{$tabla}_{$columnaFk}_index";

        if (! self::existe($tabla, $sustituto)) {
            Schema::table($tabla, fn ($t) => $t->index($columnaFk, $sustituto));
        }

        $nombreViejo = is_array($indiceViejo)
            ? $tabla.'_'.implode('_', $indiceViejo).'_index'
            : $indiceViejo;

        if (self::existe($tabla, $nombreViejo)) {
            Schema::table($tabla, fn ($t) => $t->dropIndex($nombreViejo));
        }
    }

    /**
     * Deshace lo anterior: repone el índice viejo y retira el sustituto.
     *
     * Mismo cuidado en orden inverso —primero se crea, luego se tira—, para que
     * un `migrate:rollback` no deje la foránea sin quien la cubra.
     *
     * @param  array<int, string>  $columnas
     */
    public static function reponer(string $tabla, array $columnas, string $columnaFk): void
    {
        $viejo = $tabla.'_'.implode('_', $columnas).'_index';

        if (! self::existe($tabla, $viejo)) {
            Schema::table($tabla, fn ($t) => $t->index($columnas, $viejo));
        }

        $sustituto = "{$tabla}_{$columnaFk}_index";

        if (self::existe($tabla, $sustituto)) {
            Schema::table($tabla, fn ($t) => $t->dropIndex($sustituto));
        }
    }

    /** Si la tabla ya tiene ese índice, por nombre. */
    public static function existe(string $tabla, string $indice): bool
    {
        $base = DB::connection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $base)
            ->where('table_name', $tabla)
            ->where('index_name', $indice)
            ->exists();
    }
}
