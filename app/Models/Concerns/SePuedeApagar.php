<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Un catálogo que la escuela puede encender y apagar.
 *
 * ── El ámbito es EXPLÍCITO, no global, y es a propósito ────────────────────
 * Un `addGlobalScope('activo')` habría filtrado los catorce desplegables de una
 * vez y sin tocar ninguno, que suena mejor de lo que es: también filtraría las
 * búsquedas POR ID. El día que alguien apagara un nivel, el historial de una
 * alumna dejaría de imprimir su nivel de estudios —el `whereKey(...)` no
 * encontraría nada— sin lanzar ningún error, sólo con un renglón menos en el
 * papel. Este proyecto ya se quemó con esa clase de fallo: lo que no se pide
 * llega en null y el resultado sólo dice otra cosa.
 *
 * Con `->activos()` escrito a mano, un desplegable que se olvide muestra de
 * más —se ve y se corrige—, y una lectura por id sigue devolviendo lo que
 * guardó la escuela aunque hoy esté apagado.
 *
 * ── Apagar no es borrar ────────────────────────────────────────────────────
 * Sólo se puede apagar lo que nadie usa (lo comprueba
 * `CatalogoAcademicoController`), así que apagado significa «no se puede elegir
 * de aquí en adelante», nunca «desaparece lo ya asignado».
 */
trait SePuedeApagar
{
    /** Lo que la escuela dejó encendido: lo único que se puede elegir. */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }
}
