<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * ¿Sigue vivo el despachador de tareas programadas?
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * Un scheduler que deja de correr NO FALLA: no hay excepción, no hay log, no
 * hay alerta. Simplemente no pasa nada, y el síntoma llega semanas después por
 * otro lado —«no se generaron los recargos de marzo»— cuando ya nadie relaciona
 * una cosa con la otra.
 *
 * El propio scheduler escribe una marca cada minuto (ver `routes/console.php`).
 * Esto la lee.
 *
 * ── Un solo criterio para dos consumidores ─────────────────────────────────
 * Lo consultan el comando `scheduler:estado` —para la vigilancia del servidor—
 * y el aviso del panel. Con la lógica duplicada, uno diría «caído» y el otro
 * «vivo» el día que alguien cambiara la tolerancia en un solo sitio.
 */
class LatidoDelDespachador
{
    /**
     * A partir de cuántos minutos sin señales se da por caído.
     *
     * Diez y no dos: una corrida puede retrasarse por un pico de carga, y un
     * aviso que aparece y desaparece solo se aprende a ignorar. Diez minutos ya
     * no es un retraso, es que no está corriendo.
     */
    public const TOLERANCIA_MINUTOS = 10;

    /**
     * @return array{vivo: bool, nunca: bool, ultimo: ?string, hace_minutos: ?int}
     */
    public function estado(?int $tolerancia = null): array
    {
        $tolerancia ??= self::TOLERANCIA_MINUTOS;

        $marca = Cache::store('scheduler')->get('ultimo-latido');

        if ($marca === null) {
            return ['vivo' => false, 'nunca' => true, 'ultimo' => null, 'hace_minutos' => null];
        }

        $cuando = Carbon::parse($marca);
        $hace = (int) $cuando->diffInMinutes(now());

        return [
            'vivo' => $hace <= $tolerancia,
            'nunca' => false,
            'ultimo' => $cuando->toDateTimeString(),
            'hace_minutos' => $hace,
        ];
    }
}
