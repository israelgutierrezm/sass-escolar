<?php

declare(strict_types=1);

namespace App\Services\Banco;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\EstadoCuentaBancaria;
use App\Models\Finanzas\MovimientoBancario;
use Illuminate\Support\Facades\DB;

/**
 * Mete el estado de cuenta del banco en la base, una sola vez.
 *
 * ── El cuadre del saldo es lo que prueba que el archivo está completo ──────
 * `saldo_inicial + Σ movimientos = saldo_final`. Los dos saldos los captura
 * quien importa, leyéndolos del PDF del banco, y ése es justamente el punto:
 * son una comprobación INDEPENDIENTE del archivo. Derivándolos del propio CSV
 * no comprobarían nada.
 *
 * Y no cuadrar RECHAZA la importación, no la marca. Un estado de cuenta al que
 * le faltan renglones concilia impecable —los que faltan no reclaman nada— y
 * se entrega como si estuviera revisado. Mismo criterio que el paquete mensual
 * de CFDI, que se rehúsa antes que recortarse.
 *
 * ── Reimportar el mismo archivo no duplica nada ────────────────────────────
 * Los bancos no dan un identificador de renglón, así que la llave es una
 * HUELLA de lo que el renglón dice. Pero un único sobre esa huella sería un
 * error: dos familias transfiriendo $2,500 el mismo día, con la referencia en
 * blanco, son dos movimientos idénticos y LEGÍTIMOS, y el único se comería el
 * segundo en silencio — perdiendo dinero real de la conciliación.
 *
 * Por eso se cuentan OCURRENCIAS: de cada huella se insertan las que trae el
 * archivo menos las que ya están. Reimportar da cero; un archivo corregido que
 * agrega un movimiento agrega uno.
 */
class ImportadorEstadoCuenta
{
    public function __construct(private readonly LectorEstadoCuenta $lector) {}

    /**
     * @return array{estado: EstadoCuentaBancaria, nuevos: int, repetidos: int}
     */
    public function importar(
        CuentaBancaria $cuenta,
        string $rutaAbsoluta,
        string $periodoInicio,
        string $periodoFin,
        float $saldoInicial,
        float $saldoFinal,
        ?string $rutaGuardada = null,
        ?string $nombreArchivo = null,
    ): array {
        AvisoParaElUsuario::si(
            $periodoFin < $periodoInicio,
            422,
            'El periodo termina antes de empezar.',
        );

        $mapeo = MapeoEstadoCuenta::desde($cuenta->mapeo_estado_cuenta);
        $renglones = $this->lector->leer($rutaAbsoluta, $mapeo);

        $fuera = array_filter(
            $renglones,
            fn (array $r) => $r['fecha'] < $periodoInicio || $r['fecha'] > $periodoFin,
        );

        /*
         * Un movimiento fuera del periodo declarado significa que el periodo
         * está mal capturado o que el archivo es de otro mes. Importarlo
         * igualmente metería en «septiembre» dinero de agosto, y el cuadre del
         * saldo —que es la única red— no lo vería.
         */
        if ($fuera !== []) {
            // Se arma el mensaje DENTRO del `if`: PHP evalúa los argumentos
            // antes de llamar, así que `reset([])['fecha']` reventaba en todas
            // las importaciones buenas.
            $primero = reset($fuera);

            AvisoParaElUsuario::si(
                true,
                422,
                'Hay '.count($fuera).' movimiento(s) fuera del periodo que capturaste. El primero es del '
                .$primero['fecha'].'.',
            );
        }

        $neto = round(array_sum(array_column($renglones, 'monto')), 2);
        $descuadre = round($saldoInicial + $neto - $saldoFinal, 2);

        AvisoParaElUsuario::si(
            abs($descuadre) >= 0.005,
            422,
            'El archivo no cuadra con los saldos: '.number_format($saldoInicial, 2).' + '
            .number_format($neto, 2).' da '.number_format($saldoInicial + $neto, 2)
            .', y el saldo final que capturaste es '.number_format($saldoFinal, 2)
            .' (faltan '.number_format($descuadre, 2).'). Falta parte del estado de cuenta, o un saldo está mal.',
        );

        return DB::transaction(function () use (
            $cuenta, $renglones, $periodoInicio, $periodoFin,
            $saldoInicial, $saldoFinal, $rutaGuardada, $nombreArchivo
        ) {
            $estado = EstadoCuentaBancaria::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'periodo_inicio' => $periodoInicio,
                'periodo_fin' => $periodoFin,
                'saldo_inicial' => $saldoInicial,
                'saldo_final' => $saldoFinal,
                'archivo_ruta' => $rutaGuardada,
                'archivo_nombre' => $nombreArchivo,
            ]);

            $nuevos = 0;

            foreach ($this->porHuella($renglones) as $huella => $delArchivo) {
                // Las que ya existen de ESTA cuenta, vengan del estado de cuenta
                // que vengan: el mes anterior pudo traslapar sus últimos días.
                $yaEstan = MovimientoBancario::query()
                    ->where('cuenta_bancaria_id', $cuenta->id)
                    ->where('huella', $huella)
                    ->count();

                foreach (array_slice($delArchivo, $yaEstan) as $renglon) {
                    MovimientoBancario::create($renglon + [
                        'estado_cuenta_id' => $estado->id,
                        'cuenta_bancaria_id' => $cuenta->id,
                        'huella' => $huella,
                    ]);
                    $nuevos++;
                }
            }

            $estado->update(['movimientos' => $nuevos]);

            return [
                'estado' => $estado->fresh(),
                'nuevos' => $nuevos,
                'repetidos' => count($renglones) - $nuevos,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $renglones
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function porHuella(array $renglones): array
    {
        $agrupados = [];

        foreach ($renglones as $r) {
            $agrupados[$this->huella($r)][] = $r;
        }

        return $agrupados;
    }

    /**
     * La huella de un renglón.
     *
     * Entra TODO lo que el banco dijo —fecha, concepto, referencia e importe—,
     * porque es lo único que lo distingue de otro. Dejar fuera el concepto haría
     * que una comisión y un cobro del mismo importe el mismo día se contaran
     * como el mismo renglón.
     *
     * @param  array<string, mixed>  $r
     */
    private function huella(array $r): string
    {
        return hash('sha256', implode('|', [
            $r['fecha'],
            mb_strtolower(trim((string) $r['descripcion'])),
            mb_strtolower(trim((string) ($r['referencia'] ?? ''))),
            number_format((float) $r['monto'], 2, '.', ''),
        ]));
    }
}
