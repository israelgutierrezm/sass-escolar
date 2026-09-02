<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Finanzas\RecordatorioDeCobranza;
use Illuminate\Console\Command;
use Throwable;

/**
 * El recordatorio diario de cobranza.
 *
 * ── Comando APARTE de `finanzas:evaluar`, y DESPUÉS de él ─────────────────
 * Después porque los recargos y las becas cambian lo que se debe, y el aviso
 * dice un importe: mandarlo antes daría una cifra que la misma madrugada va a
 * cambiar.
 *
 * Y aparte porque esto le HABLA A LA GENTE. `evaluar` recalcula números;
 * escribirle a las familias de una escuela entera es otra clase de acto, y
 * esconderlo dentro de un comando llamado «evaluar» es como se llega a que
 * nadie sepa de dónde salió un mensaje. Mismo criterio que separar
 * `finanzas:generar-cargos`.
 *
 * ── El modo seco existe para poder encenderlo con calma ────────────────────
 * Antes de activar un peldaño, la escuela quiere ver a cuánta gente le va a
 * llegar y con qué texto. Sin eso, la única forma de saberlo es mandarlo.
 */
class RecordarCobranza extends Command
{
    protected $signature = 'finanzas:recordar-cobranza
                            {--tenant=* : Limitar a estos tenants (por id). Sin esto, todos.}
                            {--seco : Solo informa a quién le llegaría, sin mandar nada.}';

    protected $description = 'Avisa a quien tiene cargos por vencer o vencidos, según la escalera configurada.';

    public function handle(RecordatorioDeCobranza $recordatorio): int
    {
        $ids = $this->option('tenant');
        $seco = (bool) $this->option('seco');

        $tenants = $ids === [] ? Tenant::all() : Tenant::whereIn('id', $ids)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay tenants a los que recordar.');

            return self::SUCCESS;
        }

        if ($seco) {
            $this->comment('Modo seco: no se manda ningún aviso.');
        }

        $filas = [];
        $fallidos = [];

        foreach ($tenants as $tenant) {
            try {
                $tenant->run(function () use ($tenant, $recordatorio, $seco, &$filas) {
                    $r = $recordatorio->correr(null, $seco);

                    $filas[] = [
                        $tenant->getTenantKey(),
                        $r['alumnos'],
                        $r['avisos'],
                        $r['cargos'],
                        $seco ? 'simulado' : 'ok',
                    ];

                    if ($seco && $r['detalle'] !== []) {
                        $this->line("  {$tenant->getTenantKey()}:");

                        foreach ($r['detalle'] as $d) {
                            $this->line(sprintf(
                                '    %-12s %-32s %-24s %d cargo(s)  $%s',
                                $d['matricula'],
                                mb_substr((string) $d['alumno'], 0, 32),
                                mb_substr($d['regla'], 0, 24),
                                $d['cargos'],
                                number_format($d['monto'], 2),
                            ));
                        }
                    }
                });
            } catch (Throwable $e) {
                // Una escuela caída no deja sin recordar a las demás.
                $fallidos[] = [$tenant->getTenantKey(), $e->getMessage()];
                $filas[] = [$tenant->getTenantKey(), '—', '—', '—', 'ERROR'];
            }
        }

        $this->table(['Tenant', 'Alumnos', 'Avisos', 'Cargos', 'Estado'], $filas);

        foreach ($fallidos as [$id, $mensaje]) {
            $this->error("{$id}: {$mensaje}");
        }

        return $fallidos === [] ? self::SUCCESS : self::FAILURE;
    }
}
