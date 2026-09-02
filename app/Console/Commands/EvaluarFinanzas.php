<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Finanzas\ConvenioPago;
use App\Models\Tenant;
use App\Services\CalculadorRecargos;
use App\Services\ConvenioDePago;
use App\Services\EvaluadorBecas;
use App\Services\EvaluadorDeudor;
use Illuminate\Console\Command;
use Throwable;

/**
 * El barrido diario de la cartera.
 *
 * Sin esto las reglas configuradas no se aplican solas: las becas no se
 * suspenden al vencer un cargo, los recargos no crecen y nadie pasa a moroso.
 *
 * **El orden importa** y no es intercambiable:
 *
 *  1. **Becas.** Si el alumno se atrasó y su beca exige puntualidad, ese cargo
 *     pierde el descuento. Va primero porque cambia el monto sobre el que se
 *     calcula la mora.
 *  2. **Recargos.** Ya con el monto correcto, se recalcula el recargo. Es
 *     idempotente: recalcula en vez de sumar, así correrlo dos veces el mismo
 *     día no infla la deuda de nadie.
 *  3. **Convenios cumplidos.** Un convenio pagado por completo se reconoce
 *     solo —que esté pagado es aritmética, no una decisión— y va ANTES del
 *     evaluador de deudores, porque cierra el convenio y eso cambia la
 *     situación que le toca al alumno.
 *  4. **Deudor.** Al final, porque depende de lo que quedó debiendo.
 *
 * Cada tenant se procesa aislado: un error en una escuela no debe dejar sin
 * evaluar a las demás, así que se atrapa y se reporta al final.
 */
class EvaluarFinanzas extends Command
{
    protected $signature = 'finanzas:evaluar
                            {--tenant=* : Limitar a estos tenants (por id). Sin esto, todos.}
                            {--seco : Solo informa lo que haría, sin escribir.}';

    protected $description = 'Aplica becas por atraso, recalcula recargos y actualiza el estatus de deudor.';

    public function handle(
        EvaluadorBecas $becas,
        CalculadorRecargos $recargos,
        EvaluadorDeudor $deudor,
        ConvenioDePago $convenios,
    ): int {
        $ids = $this->option('tenant');
        $seco = (bool) $this->option('seco');

        $tenants = $ids === []
            ? Tenant::all()
            : Tenant::whereIn('id', $ids)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay tenants que evaluar.');

            return self::SUCCESS;
        }

        if ($seco) {
            $this->comment('Modo seco: no se escribirá nada.');
        }

        $filas = [];
        $fallidos = [];

        foreach ($tenants as $tenant) {
            try {
                $tenant->run(function () use ($tenant, $becas, $recargos, $deudor, $convenios, $seco, &$filas) {
                    if ($seco) {
                        $filas[] = [$tenant->getTenantKey(), '—', '—', '—', '—', '—', 'simulado'];

                        return;
                    }

                    // 1) Becas: castiga los atrasos antes de calcular la mora.
                    $r1 = $becas->evaluarAtrasos();

                    // 2) Recargos: ya sobre el monto correcto.
                    $r2 = $recargos->recalcularCartera();

                    // 3) Convenios pagados: se cierran solos, antes de decidir
                    //    la situación del alumno.
                    $r3 = 0;

                    foreach (ConvenioPago::query()->vigentes()->get() as $convenio) {
                        $r3 += $convenios->revisarCumplimiento($convenio) ? 1 : 0;
                    }

                    // 4) Deudor: depende del saldo resultante.
                    $r4 = $deudor->evaluarTodos();

                    $filas[] = [
                        $tenant->getTenantKey(),
                        $r1['suspendidas'],
                        $r1['perdidas'],
                        $r2,
                        $r3,
                        $r4,
                        'ok',
                    ];
                });
            } catch (Throwable $e) {
                // Un tenant caído no cancela el barrido de los demás.
                $fallidos[] = [$tenant->getTenantKey(), $e->getMessage()];
                $filas[] = [$tenant->getTenantKey(), '—', '—', '—', '—', '—', 'ERROR'];
            }
        }

        $this->table(
            ['Tenant', 'Becas susp.', 'Becas perd.', 'Recargos', 'Convenios cerrados', 'Cambios deudor', 'Estado'],
            $filas,
        );

        foreach ($fallidos as [$id, $mensaje]) {
            $this->error("{$id}: {$mensaje}");
        }

        return $fallidos === [] ? self::SUCCESS : self::FAILURE;
    }
}
