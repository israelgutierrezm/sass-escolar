<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\GeneradorAdeudos;
use Illuminate\Console\Command;
use Throwable;

/**
 * Genera los cargos que falten, en todas las escuelas.
 *
 * ── Qué resuelve ───────────────────────────────────────────────────────────
 * Hasta ahora los cargos sólo nacían al asignar un plan a un grupo de alumnos, o
 * uno por uno desde el botón «Generar» del estado de cuenta. Si alguien agregaba
 * una línea al plan de la escuela a mitad del ciclo, esa línea no le llegaba a
 * nadie hasta que un humano volviera a pasar por cada matrícula.
 *
 * ── Por qué es un comando aparte de `finanzas:evaluar` ─────────────────────
 * Porque esto CREA DEUDA y aquél sólo recalcula la que ya existe. Meterlo dentro
 * de un comando llamado «evaluar» escondería un acto que le cobra dinero a
 * alguien detrás de un nombre que suena a lectura. Aparte, además, se puede
 * correr, probar en seco y programar por su cuenta.
 *
 * ── Por qué va ANTES del barrido en el calendario ──────────────────────────
 * No se puede recargar por mora un cargo que todavía no existe, ni decidir quién
 * es deudor sin haberlo emitido. El orden entre los dos comandos lo expresa el
 * horario en `routes/console.php`, no una llamada escondida.
 *
 * Es idempotente: `GeneradorAdeudos` comprueba la pareja (matrícula, línea)
 * antes de crear, y desde la reparación del único de generación la base lo
 * sostiene además de esa comprobación. Correrlo dos veces no le cobra a nadie
 * dos veces.
 */
class GenerarCargosDelPeriodo extends Command
{
    protected $signature = 'finanzas:generar-cargos
                            {--tenant=* : Limitar a estas escuelas (por id). Sin esto, todas.}
                            {--seco : Solo informa lo que haría, sin escribir.}';

    protected $description = 'Genera los cargos pendientes de los planes de cobro, en todas las escuelas.';

    public function handle(GeneradorAdeudos $generador): int
    {
        $ids = $this->option('tenant');
        $seco = (bool) $this->option('seco');

        $escuelas = $ids === [] ? Tenant::all() : Tenant::whereIn('id', $ids)->get();

        if ($escuelas->isEmpty()) {
            $this->warn('No hay escuelas a las que generarles cargos.');

            return self::SUCCESS;
        }

        if ($seco) {
            $this->comment('Modo seco: no se escribirá nada.');
        }

        $filas = [];
        $fallidas = [];

        foreach ($escuelas as $escuela) {
            try {
                $escuela->run(function () use ($escuela, $generador, $seco, &$filas) {
                    if ($seco) {
                        $filas[] = [$escuela->getTenantKey(), '—', '—', '—', 'simulado'];

                        return;
                    }

                    $r = $generador->generarParaTodas();

                    $filas[] = [
                        $escuela->getTenantKey(),
                        $r['planes'],
                        $r['matriculas'],
                        $r['cargos'],
                        $r['fallidos'] === [] ? 'ok' : count($r['fallidos']).' plan(es) con problema',
                    ];

                    // Los planes que reventaron se enseñan con su motivo: un
                    // «ok» con cargos de menos no se nota hasta el corte.
                    foreach ($r['fallidos'] as $fallo) {
                        $this->warn("[{$escuela->getTenantKey()}] plan {$fallo['plan']}: {$fallo['motivo']}");
                    }
                });
            } catch (Throwable $e) {
                // Una escuela caída no cancela a las demás: es la misma regla que
                // sigue `finanzas:evaluar`, y aquí importa más —dejar sin cargos
                // a todo el resto por un problema de una sola sería peor que el
                // problema original.
                $fallidas[] = [$escuela->getTenantKey(), $e->getMessage()];
            }
        }

        $this->table(['Escuela', 'Planes', 'Matrículas', 'Cargos nuevos', 'Estado'], $filas);

        if ($fallidas !== []) {
            $this->newLine();
            $this->error('Escuelas que fallaron:');
            $this->table(['Escuela', 'Motivo'], $fallidas);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
