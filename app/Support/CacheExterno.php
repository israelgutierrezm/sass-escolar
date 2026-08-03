<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Dónde se guarda lo que responden los servicios de fuera.
 *
 * ── Por qué un almacén aparte del tenant ───────────────────────────────────
 * `stancl/tenancy` envuelve `Cache::` con etiquetas para aislar por escuela, y
 * el driver del proyecto (`database`) no las admite: cualquier `Cache::remember`
 * revienta con «this cache store does not support tagging».
 *
 * Y aunque las admitiera, no habría qué aislar: el clima de unas coordenadas,
 * los feriados oficiales de México o el dólar del día no son dato de ninguna
 * escuela en particular. Dos escuelas de la misma ciudad comparten el mismo
 * cielo y bien pueden compartir la consulta.
 *
 * ── El fallo NO se guarda ──────────────────────────────────────────────────
 * Es la razón de que esto exista como clase y no como tres copias sueltas.
 * `remember` guarda lo que devuelva el callback, y eso incluye el null de un
 * intento que salió mal: un parpadeo de red dejaba la tarjeta sin dólar tres
 * horas —o sin clima media hora— aunque el servicio volviera al minuto
 * siguiente. Peor, se diagnostica como un bug del código, porque el servicio
 * responde bien cuando uno lo prueba a mano.
 *
 * Aquí, si no se pudo traer, se olvida la llave y el siguiente que entre
 * reintenta.
 */
class CacheExterno
{
    /**
     * Trae y recuerda, salvo que haya fallado.
     *
     * @template T
     *
     * @param  callable(): ?T  $traer
     * @return ?T
     */
    public static function recordar(string $llave, int $minutos, callable $traer)
    {
        $almacen = self::almacen();

        $valor = $almacen->remember($llave, now()->addMinutes($minutos), $traer);

        if ($valor === null) {
            $almacen->forget($llave);
        }

        return $valor;
    }

    public static function olvidar(string $llave): void
    {
        self::almacen()->forget($llave);
    }

    private static function almacen(): Repository
    {
        return Cache::build([
            'driver' => 'file',
            'path' => storage_path('framework/cache/externos'),
        ]);
    }
}
