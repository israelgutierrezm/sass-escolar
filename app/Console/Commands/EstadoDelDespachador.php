<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Plataforma\EstadoDeLaCola;
use App\Services\Plataforma\LatidoDelDespachador;
use Illuminate\Console\Command;

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
 *
 * ── Y la COLA, que falla igual de callada ─────────────────────────────────
 * Se informa aquí y no en un comando aparte porque es el mismo síntoma y el
 * mismo sitio donde alguien va a mirar: nada pasa, nadie se entera. Una cola sin
 * trabajador deja la factura sin timbrar y quien la emitió cree que sí.
 *
 * El trabajador lo levanta el propio despachador (ver `routes/console.php`), así
 * que si el despachador está caído la cola también lo está — y este comando lo
 * dice todo junto.
 */
class EstadoDelDespachador extends Command
{
    protected $signature = 'scheduler:estado {--minutos=10 : A partir de cuántos minutos sin latido se considera caído.}';

    protected $description = 'Dice si el despachador y la cola de trabajos siguen corriendo.';

    public function handle(LatidoDelDespachador $latidos, EstadoDeLaCola $cola): int
    {
        $estado = $latidos->estado(max(2, (int) $this->option('minutos')));

        if ($estado['nunca']) {
            $this->error('El despachador NUNCA ha corrido en este servidor.');
            $this->line('Instálalo con deploy/scheduler/ — ver docs/scheduler.md.');

            return self::FAILURE;
        }

        $hace = $estado['hace_minutos'];
        $cuando = $estado['ultimo'];

        if (! $estado['vivo']) {
            $this->error("El despachador lleva {$hace} minutos sin dar señales (último: {$cuando}).");
            $this->line('Las tareas programadas NO se están ejecutando, y la cola tampoco.');

            return self::FAILURE;
        }

        $this->info("El despachador está vivo. Último latido: {$cuando} (hace {$hace} min).");

        return $this->reportarLaCola($cola);
    }

    /**
     * La cola, que falla tan callada como el despachador.
     *
     * Sale con error si está atorada, para que la vigilancia del servidor lo
     * note sin leer el texto — igual que con el latido.
     */
    private function reportarLaCola(EstadoDeLaCola $cola): int
    {
        $estado = $cola->estado();

        if (! $estado['hay_tabla']) {
            $this->line('La cola no usa base de datos en este servidor; no hay nada que revisar aquí.');

            return self::SUCCESS;
        }

        if ($estado['fallidos'] > 0) {
            /*
             * Los fallidos se DICEN aunque la cola esté sana: son trabajos que
             * nadie va a reintentar solo, y ahí siguen. Un timbrado fallido es
             * una factura que no existe ante el SAT.
             */
            $this->warn("Hay {$estado['fallidos']} trabajo(s) FALLIDO(s) sin reintentar.");
            $this->line('  Míralos con `php artisan queue:failed` y reintenta con `queue:retry all`.');
        }

        if ($estado['atorada']) {
            $this->error(
                "La cola está ATORADA: {$estado['pendientes']} trabajo(s) esperando, "
                ."el más viejo desde hace {$estado['espera_minutos']} minutos ({$estado['mas_viejo']})."
            );
            $this->line('  Nada se está procesando: ni timbrados ni grabaciones.');

            return self::FAILURE;
        }

        $this->info($estado['pendientes'] === 0
            ? 'La cola está al día: no hay trabajos esperando.'
            : "La cola avanza: {$estado['pendientes']} trabajo(s) esperando, el más viejo desde hace {$estado['espera_minutos']} min.");

        return self::SUCCESS;
    }
}
