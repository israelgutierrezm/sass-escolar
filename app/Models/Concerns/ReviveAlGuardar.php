<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * `updateOrCreate` que también ve las filas borradas.
 *
 * ── La trampa ──────────────────────────────────────────────────────────────
 * `updateOrCreate` busca con el scope global de `SoftDeletes`, así que NO ve las
 * filas con `deleted_at`. Cuando la tabla tiene una llave única sobre esas mismas
 * columnas, el resultado es que Eloquent no encuentra nada, intenta insertar, y
 * la base rechaza contra un registro que ella sí ve:
 *
 *     Duplicate entry '…' for key '…'
 *
 * Nadie lo nota hasta que alguien borra un registro y la operación se repite: el
 * docente que borra una asistencia y vuelve a pasar lista, el alumno que
 * reentrega algo cuya entrega se retiró, la conversación que se cerró y se
 * reabre. Se descubrió con el pase de lista, y las mismas condiciones están en
 * `entregas`, `cursos` y `conversaciones`.
 *
 * ── Reviver es lo correcto, no crear otra ──────────────────────────────────
 * La llave única dice que solo puede haber UNA fila para esa combinación. Volver
 * a entregar la misma actividad no es una entrega nueva: es la misma que vuelve
 * a estar. Reviviéndola se conserva su historia —cuándo se creó, quién la tocó—
 * en vez de partirla en dos registros que nadie sabría reconciliar.
 */
trait ReviveAlGuardar
{
    /**
     * Como `updateOrCreate`, pero mirando también lo borrado y reviviéndolo.
     *
     * @param  array<string, mixed>  $llaves  por dónde se busca (la llave única)
     * @param  array<string, mixed>  $valores  lo que se escribe
     */
    public static function actualizarOReviver(array $llaves, array $valores = []): static
    {
        $registro = static::withTrashed()->firstOrNew($llaves);

        $registro->fill($valores);

        /*
         * Fuera del `fill` a propósito: `deleted_at` no está —ni debe estar— en
         * el `$fillable`, porque borrar no es algo que se asigne en masa desde
         * una petición. Puesto ahí se descartaría en silencio y la fila seguiría
         * borrada, que es justo el error que este método viene a evitar.
         */
        $registro->deleted_at = null;
        $registro->save();

        return $registro;
    }

    /**
     * Como `firstOrCreate`, pero mirando también lo borrado y reviviéndolo.
     *
     * La diferencia con el anterior importa: aquí los valores se escriben SOLO
     * si la fila no existía o estaba borrada. Usar `actualizarOReviver` donde
     * antes había un `firstOrCreate` reimpondría esos valores en cada llamada
     * —un `['publicado' => true]` volvería a publicar lo que alguien acababa de
     * despublicar, sin que nada lo dijera—.
     *
     * @param  array<string, mixed>  $llaves
     * @param  array<string, mixed>  $valoresSiEsNueva
     */
    public static function primeraOReviver(array $llaves, array $valoresSiEsNueva = []): static
    {
        $registro = static::withTrashed()->firstOrNew($llaves);

        if (! $registro->exists || $registro->trashed()) {
            $registro->fill($valoresSiEsNueva);
        }

        $registro->deleted_at = null;
        $registro->save();

        return $registro;
    }
}
