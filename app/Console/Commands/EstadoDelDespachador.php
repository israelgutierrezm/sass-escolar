<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * ¿Está vivo el despachador de tareas programadas?
 *
 * ── Por qué hace falta un comando para esto ────────────────────────────────
 * Un scheduler que deja de correr NO FALLA. No hay excepción, no hay log, no
 * hay alerta: simplemente no pasa nada. El síntoma llega semanas después y por
 * otro lado —«no se generaron los recargos de marzo»— y para entonces nadie
 * relaciona una cosa con la otra.
 *
 * El latido se escribe cada minuto desde `routes/console.php`. Esto lo lee y
 * dice desde cuándo no hay señales. Devuelve código de salida 1 cuando está
 * caído, para poder engancharlo a la vigilancia del servidor sin leer su texto.
 */
class EstadoDelDespachador extends Command
{
    protected $signature = 'scheduler:estado {--minutos=10 : A partir de cuántos minutos sin latido se considera caído.}';

    protected $description = 'Dice si el despachador de tareas programadas sigue corriendo.';

    public function handle(): int
    {
        $tolerancia = max(2, (int) $this->option('minutos'));

        $latido = Cache::store('scheduler')->get('ultimo-latido');

        if ($latido === null) {
            $this->error('El despachador NUNCA ha corrido en este servidor.');
            $this->line('Instálalo con deploy/scheduler/ — ver docs/scheduler.md.');

            return self::FAILURE;
        }

        $cuando = Carbon::parse($latido);
        $hace = (int) $cuando->diffInMinutes(now());

        if ($hace > $tolerancia) {
            $this->error("El despachador lleva {$hace} minutos sin dar señales (último: {$cuando->toDateTimeString()}).");
            $this->line('Las tareas programadas NO se están ejecutando.');

            return self::FAILURE;
        }

        $this->info("El despachador está vivo. Último latido: {$cuando->toDateTimeString()} (hace {$hace} min).");

        return self::SUCCESS;
    }
}
