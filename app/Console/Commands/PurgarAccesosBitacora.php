<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlEscolar\AccesoBitacoraTutoria;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Borra los accesos a bitácoras de tutoría más viejos que la retención.
 *
 * ── Por qué se purga ───────────────────────────────────────────────────────
 * La tabla crece sola: un tutor con veinte tutorados que revisa fichas a diario
 * genera cientos de filas al mes, y nadie la mira. En tres años sería la tabla
 * más grande del tenant sin haber servido para nada en el 99,99% de sus filas.
 *
 * Pero además hay una razón que no es de espacio: un registro de quién consultó
 * qué es, él mismo, un dato personal. Guardarlo para siempre «por si acaso»
 * convierte una medida de protección en un archivo de vigilancia. Se conserva
 * lo que puede hacer falta para investigar algo y se tira el resto.
 *
 * ── Dos años ───────────────────────────────────────────────────────────────
 * Cubre el ciclo escolar en curso y los anteriores que todavía puedan
 * reclamarse, que es el horizonte real en que alguien pregunta «quién vio esto».
 * Configurable con `--dias` para la escuela que necesite otro plazo.
 */
class PurgarAccesosBitacora extends Command
{
    protected $signature = 'tutorias:purgar-accesos
                            {--dias=730 : Cuántos días se conservan.}
                            {--tenant=* : Limitar a estos tenants (por id). Sin esto, todos.}
                            {--seco : Cuenta lo que borraría, sin borrar nada.}';

    protected $description = 'Purga los accesos antiguos a las bitácoras de tutoría.';

    public function handle(): int
    {
        $dias = max(30, (int) $this->option('dias'));
        $seco = (bool) $this->option('seco');
        $ids = $this->option('tenant');

        $corte = now()->subDays($dias)->startOfDay();

        $tenants = $ids === [] ? Tenant::all() : Tenant::whereIn('id', $ids)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay tenants que purgar.');

            return self::SUCCESS;
        }

        $this->comment(($seco ? 'Modo seco: ' : '')."se conservan {$dias} días (desde {$corte->toDateString()}).");

        $filas = [];

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($tenant, $corte, $seco, &$filas) {
                $consulta = AccesoBitacoraTutoria::query()->where('creado_en', '<', $corte);

                $cuantos = $consulta->count();

                if (! $seco && $cuantos > 0) {
                    /*
                     * Por lotes: un `delete` de medio millón de filas bloquea la
                     * tabla el tiempo suficiente para que alguien note que la
                     * escuela «se puso lenta» a las tres de la mañana.
                     */
                    do {
                        $borradas = AccesoBitacoraTutoria::query()
                            ->where('creado_en', '<', $corte)
                            ->limit(1000)
                            ->delete();
                    } while ($borradas > 0);
                }

                $filas[] = [$tenant->getTenantKey(), $cuantos, AccesoBitacoraTutoria::count()];
            });
        }

        $this->table(['Escuela', $seco ? 'Se borrarían' : 'Borrados', 'Quedan'], $filas);

        return self::SUCCESS;
    }
}
