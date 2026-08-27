<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reportes\EjecucionReporte;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Borra las ejecuciones de reportes más viejas que la retención.
 *
 * ── Por qué se purga ──────────────────────────────────────────────────────
 * `ejecuciones_reporte` escribe una fila cada vez que alguien abre un reporte,
 * así que crece sola y sin techo. El plan del módulo la pedía «desde el primer
 * día» y se quedó sin construir; llegó con la pantalla que la lee, que es
 * cuando la tabla dejó de ser invisible.
 *
 * Y hay una razón que no es de espacio, la misma que dejó escrita la purga de
 * las bitácoras de tutoría: un registro de quién consultó qué es, él mismo, un
 * dato personal. Guardarlo para siempre «por si acaso» convierte una medida de
 * control en un archivo de vigilancia.
 *
 * ── Un año ────────────────────────────────────────────────────────────────
 * Cubre el ciclo escolar en curso y el anterior, que es el horizonte en que
 * alguien pregunta «¿quién sacó ese padrón?». Configurable con `--dias`.
 *
 * ── Por qué NO es un ajuste de la escuela ─────────────────────────────────
 * La escuela no tiene una decisión de negocio que tomar sobre cuánto se guarda
 * un registro de control interno, y este proyecto ya tuvo que retirar cinco
 * interruptores que nadie leía. Si un día una escuela pide otro plazo, el
 * comando ya lo admite por parámetro; convertirlo en ajuste sería declarar el
 * mismo número dos veces.
 */
class PurgarEjecucionesReporte extends Command
{
    protected $signature = 'reportes:purgar-ejecuciones
                            {--dias=365 : Cuántos días se conservan.}
                            {--tenant=* : Limitar a estos tenants (por id). Sin esto, todos.}
                            {--seco : Cuenta lo que borraría, sin borrar nada.}';

    protected $description = 'Purga las ejecuciones antiguas de la bitácora de reportes.';

    /** Cuántas filas por vuelta. Ver el comentario del bucle. */
    private const LOTE = 1000;

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
                $cuantos = $this->viejas($corte)->count();

                if (! $seco && $cuantos > 0) {
                    /*
                     * Por lotes: un `delete` de medio millón de filas bloquea la
                     * tabla el tiempo suficiente para que alguien note que la
                     * escuela «se puso lenta» de madrugada.
                     */
                    do {
                        $borradas = $this->viejas($corte)->limit(self::LOTE)->forceDelete();
                    } while ($borradas > 0);
                }

                $filas[] = [
                    $tenant->getTenantKey(),
                    $cuantos,
                    EjecucionReporte::withTrashed()->count(),
                ];
            });
        }

        $this->table(['Escuela', $seco ? 'Se borrarían' : 'Borradas', 'Quedan'], $filas);

        return self::SUCCESS;
    }

    /**
     * Lo anterior al corte, MIRANDO TAMBIÉN lo dado de baja.
     *
     * ── `->delete()` no borraría nada ─────────────────────────────────────
     * `EjecucionReporte` usa `TieneAuditoria`, que trae `SoftDeletes`: el
     * borrado normal escribe `deleted_at` y la fila se queda en la tabla para
     * siempre. Una purga así informa «borradas 400» y la base no adelgaza un
     * byte — y lo peor es que la comprobación obvia, «las de dentro de la
     * retención siguen ahí», se cumple igual, así que pasaría sin destapar
     * nada. De ahí `forceDelete()`.
     *
     * ── Y `withTrashed()` NO es lo que hace falta para borrarlas ──────────
     * Es lo que uno supondría, y se comprobó que no: el macro `forceDelete` de
     * `SoftDeletingScope` va contra el query builder CRUDO (`$builder->query
     * ->delete()`), así que se salta el scope y arrastra también lo dado de
     * baja lógica aunque no se pida. Medido: con y sin `withTrashed()`, las dos
     * filas viejas desaparecen igual.
     *
     * Lo que sí cambia es el CONTEO —medido, 1 contra 2—, y ése tiene lector:
     * es el número que `--seco` le enseña a quien está decidiendo si purga. Sin
     * `withTrashed()` el modo seco diría «se borrarían 300» y borraría 400.
     */
    private function viejas(Carbon $corte): Builder
    {
        return EjecucionReporte::withTrashed()->where('created_at', '<', $corte);
    }
}
