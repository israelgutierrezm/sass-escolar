<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ConciliadorCfdi;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pregunta al PAC qué opina el SAT de los comprobantes emitidos.
 *
 * ── Por qué es un comando y no un botón ────────────────────────────────────
 * Los dos desajustes que caza aparecen SIN que nadie haga nada en el sistema:
 * alguien cancela desde el portal del PAC, o una cancelación pedida aquí se
 * queda esperando que el receptor la acepte. No hay un acto nuestro que
 * dispare la revisión, así que tiene que preguntarse sola.
 *
 * ── Sólo LEE y anota ───────────────────────────────────────────────────────
 * Nunca escribe `estatus`. Ver el porqué en `ConciliadorCfdi` y en la
 * migración: mover ese estado desde aquí liberaría los pagos de una factura y
 * alguien podría refacturar el mismo dinero de madrugada y sin pedirlo.
 *
 * Sale con ERROR si encuentra discrepancias. Un comando programado que termina
 * en verde teniendo comprobantes que no cuadran es exactamente cómo esto se
 * queda sin mirar durante meses.
 */
class ConciliarCfdi extends Command
{
    protected $signature = 'finanzas:conciliar-cfdi
                            {--tenant=* : Limitar a estas escuelas (por id). Sin esto, todas.}
                            {--dias=90 : Cuánto hacia atrás mirar.}
                            {--limite= : Tope de comprobantes por escuela, para una corrida de prueba.}';

    protected $description = 'Contrasta los CFDI emitidos con lo que el SAT dice de ellos.';

    public function handle(ConciliadorCfdi $conciliador): int
    {
        $ids = $this->option('tenant');
        $dias = (int) $this->option('dias');
        $limite = $this->option('limite') === null ? null : (int) $this->option('limite');

        $escuelas = $ids === [] ? Tenant::all() : Tenant::whereIn('id', $ids)->get();

        if ($escuelas->isEmpty()) {
            $this->warn('No hay escuelas que conciliar.');

            return self::SUCCESS;
        }

        $filas = [];
        $conDiscrepancia = 0;
        $fallidas = [];

        foreach ($escuelas as $escuela) {
            try {
                $escuela->run(function () use ($escuela, $conciliador, $dias, $limite, &$filas, &$conDiscrepancia) {
                    $r = $conciliador->conciliar($dias, $limite);

                    if ($r['omitido'] !== null) {
                        $this->comment("[{$escuela->getTenantKey()}] {$r['omitido']}");

                        return;
                    }

                    $filas[] = [
                        $escuela->getTenantKey(),
                        $r['consultadas'],
                        count($r['discrepancias']),
                        $r['enEspera'],
                        $r['sinRespuesta'],
                    ];

                    $conDiscrepancia += count($r['discrepancias']);

                    // Cada una con su folio y su motivo: un conteo no dice cuál
                    // hay que ir a mirar, y son pocas por definición.
                    foreach ($r['discrepancias'] as $d) {
                        $this->error("[{$escuela->getTenantKey()}] factura {$d['id']} ({$d['uuid']}): {$d['motivo']}");
                    }
                });
            } catch (Throwable $e) {
                // Una escuela caída no cancela a las demás.
                $fallidas[] = [$escuela->getTenantKey(), $e->getMessage()];
            }
        }

        if ($filas !== []) {
            $this->table(['Escuela', 'Consultadas', 'Discrepancias', 'Cancelación en espera', 'Sin respuesta'], $filas);
        }

        foreach ($fallidas as [$clave, $mensaje]) {
            $this->error("[{$clave}] no se pudo conciliar: {$mensaje}");
        }

        // Que una escuela reviente NO puede terminar diciendo «todo cuadra»: es
        // la lección que dejó `acadion:auditar-datos`, que declaraba limpia una
        // base con 199 filas rotas porque la excepción se tragaba el conteo.
        return ($conDiscrepancia > 0 || $fallidas !== []) ? self::FAILURE : self::SUCCESS;
    }
}
