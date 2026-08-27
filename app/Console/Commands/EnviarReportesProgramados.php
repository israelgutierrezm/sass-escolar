<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Reportes\Envio\EnviadorProgramado;
use Illuminate\Console\Command;

/**
 * Manda los reportes programados que toquen a esta hora.
 *
 * ── Cada quince minutos, no cada minuto ──────────────────────────────────
 * Nadie programa un reporte «a las 7:03». Con cuartos de hora, una programación
 * de las 7:00 sale a las 7:00 o a las 7:15 en el peor caso, y el despachador no
 * abre una conexión por escuela sesenta veces por hora para no hacer nada.
 *
 * Y llegar tarde no se salta el turno: `leTocaA()` compara con la hora YA
 * PASADA, no con el minuto exacto. Lo que impide el correo repetido es
 * `ultima_corrida_en`, no la puntería.
 *
 * ── Sin cola ─────────────────────────────────────────────────────────────
 * `QUEUE_CONNECTION=database` y este repositorio no declara ningún trabajador,
 * así que encolar los correos los dejaría esperando para siempre y sin avisar.
 * Van síncronos: esto corre de madrugada y no tiene a nadie esperando.
 *
 * ── Una escuela que falle no cancela a las demás ─────────────────────────
 * Se aísla cada tenant. Es la misma lección que dejó `finanzas:generar-cargos`:
 * sin aislar, un solo plan roto dejaba a TODAS las escuelas sin emitir y el
 * reporte decía «ok».
 */
class EnviarReportesProgramados extends Command
{
    protected $signature = 'reportes:enviar-programados
                            {--tenant=* : Limitar a estos tenants (por id). Sin esto, todos.}
                            {--seco : Dice qué mandaría, sin mandar ni anotar nada.}';

    protected $description = 'Manda por correo los reportes programados que toquen.';

    public function handle(): int
    {
        $seco = (bool) $this->option('seco');
        $ids = $this->option('tenant');

        $tenants = $ids === [] ? Tenant::all() : Tenant::whereIn('id', $ids)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay escuelas que revisar.');

            return self::SUCCESS;
        }

        $momento = now();
        $filas = [];
        $fallaron = [];

        $this->comment(($seco ? 'Modo seco: ' : '').'reportes programados para las '.$momento->format('Y-m-d H:i').'.');

        foreach ($tenants as $tenant) {
            try {
                $tenant->run(function () use ($tenant, $momento, $seco, &$filas) {
                    foreach (app(EnviadorProgramado::class)->correrLasQueTocan($momento, $seco) as $linea) {
                        $filas[] = [
                            $tenant->getTenantKey(),
                            $linea['programacion'],
                            $linea['estado'],
                            $linea['detalle'],
                        ];
                    }
                });
            } catch (\Throwable $falla) {
                /*
                 * Se reporta y se sigue. Una escuela con la configuración de
                 * correo a medias no puede dejar sin su reporte a las otras
                 * treinta.
                 */
                $fallaron[] = $tenant->getTenantKey().': '.$falla->getMessage();
            }
        }

        if ($filas === []) {
            $this->line('No le tocaba a ninguna.');
        } else {
            $this->table(['Escuela', 'Programación', 'Estado', 'Detalle'], $filas);
        }

        if ($fallaron !== []) {
            $this->newLine();
            $this->error('Escuelas que fallaron enteras:');

            foreach ($fallaron as $cual) {
                $this->line('  '.$cual);
            }

            // Sale con error: un fallo que termina diciendo «todo bien» es como
            // se llega a que nadie sepa que lleva un mes sin mandarse nada.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
