<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Finanzas\ConvenioPago;
use App\Models\Tenant;
use App\Services\CalculadorRecargos;
use App\Services\ConvenioDePago;
use App\Services\EvaluadorBecas;
use App\Services\EvaluadorDeudor;
use App\Services\Finanzas\ConvenioDeDescuento;
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
 *  3. **Convenios de descuento vencidos.** Al pasarse su fecha se cierran, y
 *     con ellos TODAS sus becas a la vez: un acuerdo terminado que siguiera
 *     descontando sería dinero que la escuela deja de cobrar sin que nadie lo
 *     haya decidido. Va DESPUÉS de las becas y los recargos: cerrar un
 *     convenio recompone los cargos de sus beneficiarios, y ese recálculo tiene
 *     que ser el último que los toque.
 *
 *  4. **Convenios de pago cumplidos.** Uno pagado por completo se reconoce
 *     solo —que esté pagado es aritmética, no una decisión— y va ANTES del
 *     evaluador de deudores, porque cierra el convenio y eso cambia la
 *     situación que le toca al alumno.
 *  5. **Deudor.** Al final, porque depende de lo que quedó debiendo.
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
        ConvenioDeDescuento $descuentos,
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
                $tenant->run(function () use ($tenant, $becas, $recargos, $deudor, $convenios, $descuentos, $seco, &$filas) {
                    if ($seco) {
                        $filas[] = [$tenant->getTenantKey(), '—', '—', '—', '—', '—', '—', 'simulado'];

                        return;
                    }

                    // 1) Becas: castiga los atrasos antes de calcular la mora.
                    $r1 = $becas->evaluarAtrasos();

                    // 2) Recargos: ya sobre el monto correcto.
                    $r2 = $recargos->recalcularCartera();

                    // 3) Convenios de descuento vencidos: se cierran con todas
                    //    sus becas, antes de decidir la situación del alumno.
                    $rc = $descuentos->cerrarVencidos();

                    // 4) Convenios de pago pagados: se cierran solos.
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
                        $rc['convenios'].' ('.$rc['becas'].' becas)',
                        $r3,
                        $r4,
                        'ok',
                    ];
                });
            } catch (Throwable $e) {
                // Un tenant caído no cancela el barrido de los demás.
                $fallidos[] = [$tenant->getTenantKey(), $e->getMessage()];
                $filas[] = [$tenant->getTenantKey(), '—', '—', '—', '—', '—', '—', 'ERROR'];
            }
        }

        $this->table(
            ['Tenant', 'Becas susp.', 'Becas perd.', 'Recargos', 'Convenios venc.', 'Convenios pago', 'Cambios deudor', 'Estado'],
            $filas,
        );

        foreach ($fallidos as [$id, $mensaje]) {
            $this->error("{$id}: {$mensaje}");
        }

        return $fallidos === [] ? self::SUCCESS : self::FAILURE;
    }
}
