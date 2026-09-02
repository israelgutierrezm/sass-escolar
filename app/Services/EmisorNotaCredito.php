<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\TimbrarFactura;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\FacturaConcepto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emite la nota de crédito (CFDI de egreso) que reduce una factura.
 *
 * ── Por qué existe, si ya estaba `refacturar()` ────────────────────────────
 * Refacturar es «esta factura estaba mal, aquí está la buena»: cancela la
 * anterior y emite otra por el importe correcto. No sirve cuando el importe
 * estaba BIEN el día que se emitió y cambió después —una beca autorizada
 * tarde, un descuento pactado luego, un cobro de más que se descubre en la
 * conciliación—, y deja de estar disponible en cuanto vence el plazo del SAT
 * para cancelar o cuando el receptor se niega a aceptar la cancelación.
 *
 * La nota de crédito no cancela nada: la factura original sigue vigente, sigue
 * amparando su dinero, y este documento dice cuánto de ella dejó de cobrarse.
 *
 * ── Se acredita RENGLÓN POR RENGLÓN, no por un importe total ───────────────
 * Es la razón por la que la factura desglosa el IVA por concepto: en un mismo
 * comprobante conviven la colegiatura exenta y la constancia gravada. Un
 * importe global no diría cuánto impuesto se está reversando, y el CFDI de
 * egreso tiene que decirlo. Cada renglón se acredita contra SU renglón de
 * origen, con la misma tasa.
 *
 * ── Lo que NO hace, a propósito ────────────────────────────────────────────
 * No toca la cartera. El dinero ya entró; que una nota de crédito se convierta
 * en una devolución o en un saldo a favor es una decisión de cobranza, con su
 * propio movimiento y su propia autorización. Escribirlo aquí crearía una
 * segunda verdad sobre lo que el alumno debe, a partir de un documento fiscal
 * que sólo habla de lo declarado al SAT.
 */
class EmisorNotaCredito
{
    /**
     * @param  array<int, array{concepto_id: int, importe: float}>  $renglones
     *
     * @throws RuntimeException si la factura no admite nota de crédito
     */
    public function emitir(Factura $original, array $renglones, string $motivo): Factura
    {
        $this->comprobarQueSePuede($original);

        $acreditar = $this->renglonesAcreditables($original, $renglones);

        if ($acreditar->isEmpty()) {
            throw new RuntimeException('Hay que acreditar al menos un concepto con importe mayor que cero.');
        }

        return DB::transaction(function () use ($original, $acreditar, $motivo) {
            $subtotal = round($acreditar->sum('importe'), 2);
            $iva = round($acreditar->sum('iva'), 2);

            $nota = Factura::create([
                'matricula_oferta_id' => $original->matricula_oferta_id,
                'tipo' => Factura::TIPO_EGRESO,
                'factura_origen_id' => $original->id,
                'motivo_egreso' => $motivo,
                // El emisor se COPIA del original y no se vuelve a resolver: una
                // nota de crédito la emite forzosamente la misma razón social
                // que facturó. Re-resolviendo, una escuela que cambiara la
                // asignación de emisores acreditaría desde una persona moral
                // que nunca cobró ese dinero.
                'emisor_id' => $original->emisor_id,
                'emisor_rfc' => $original->emisor_rfc,
                'emisor_razon_social' => $original->emisor_razon_social,
                'emisor_regimen_fiscal' => $original->emisor_regimen_fiscal,
                'emisor_cp' => $original->emisor_cp,
                // Y el receptor igual: acreditar a otro RFC sería regalarle una
                // deducción a quien no pagó.
                'receptor_rfc' => $original->receptor_rfc,
                'receptor_razon_social' => $original->receptor_razon_social,
                'receptor_uso_cfdi' => $original->receptor_uso_cfdi,
                'receptor_regimen_fiscal' => $original->receptor_regimen_fiscal,
                'receptor_cp' => $original->receptor_cp,
                'forma_pago_sat' => $original->forma_pago_sat,
                'metodo_pago_sat' => $original->metodo_pago_sat,
                'moneda' => $original->moneda,
                'subtotal' => $subtotal,
                'iva' => $iva,
                'total' => round($subtotal + $iva, 2),
                'pac' => $original->pac,
                'estatus' => Factura::ESTATUS_BORRADOR,
            ]);

            foreach ($acreditar as $renglon) {
                $nota->conceptos()->create($renglon);
            }

            TimbrarFactura::dispatch($nota->id);

            return $nota;
        });
    }

    /**
     * Cuánto queda por acreditar de cada renglón de una factura.
     *
     * Se expone porque lo pregunta la PANTALLA, para no ofrecer un tope que el
     * servidor vaya a rechazar. La cuenta es la misma en los dos sitios, así
     * que vive en uno.
     *
     * @return array<int, float> concepto_id => importe (base, sin IVA) disponible
     */
    public function disponiblePorConcepto(Factura $original): array
    {
        $original->loadMissing('conceptos');

        // Lo ya acreditado se agrupa por el renglón de origen. Las notas
        // canceladas NO cuentan: una cancelada dejó de reducir nada, y seguir
        // descontándola dejaría renglones imposibles de volver a acreditar.
        $acreditado = FacturaConcepto::query()
            ->whereIn('factura_id', $original->notasCredito()->vivas()->select('id'))
            ->selectRaw('concepto_origen_id, SUM(importe) as importe')
            ->groupBy('concepto_origen_id')
            ->pluck('importe', 'concepto_origen_id');

        return $original->conceptos
            ->mapWithKeys(fn (FacturaConcepto $c) => [
                $c->id => round((float) $c->importe - (float) ($acreditado[$c->id] ?? 0), 2),
            ])
            ->all();
    }

    private function comprobarQueSePuede(Factura $original): void
    {
        if ($original->esNotaCredito()) {
            throw new RuntimeException('Una nota de crédito no se acredita: no ampara ingreso que reducir.');
        }

        if (! $original->estaVigente()) {
            throw new RuntimeException(
                'Solo se acredita una factura timbrada y vigente. Un borrador se corrige antes de timbrarlo, '
                .'y una cancelada ya no ampara nada.'
            );
        }
    }

    /**
     * @param  array<int, array{concepto_id: int, importe: float}>  $renglones
     * @return Collection<int, array<string, mixed>>
     */
    private function renglonesAcreditables(Factura $original, array $renglones)
    {
        $original->loadMissing('conceptos');
        $disponible = $this->disponiblePorConcepto($original);

        return collect($renglones)
            ->map(function (array $renglon) use ($original, $disponible) {
                $importe = round((float) $renglon['importe'], 2);

                if ($importe <= 0) {
                    return null;
                }

                $concepto = $original->conceptos->firstWhere('id', (int) $renglon['concepto_id']);

                // El id del renglón viaja en la petición, así que se comprueba
                // que sea DE ESTA factura: con sólo creerle, se acreditaría un
                // concepto de la factura de otra persona.
                if ($concepto === null) {
                    throw new RuntimeException('Ese concepto no pertenece a la factura que se está acreditando.');
                }

                $tope = $disponible[$concepto->id] ?? 0.0;

                if ($importe > $tope) {
                    throw new RuntimeException(
                        "«{$concepto->descripcion}» solo admite acreditar hasta ".number_format($tope, 2)
                        .'. Acreditar de más declararía al SAT un ingreso negativo que nunca existió.'
                    );
                }

                // El IVA se reversa con la MISMA tasa del renglón original, no
                // con la del catálogo de hoy: si la escuela corrige la tasa de
                // un concepto, la nota tiene que reversar lo que se timbró.
                $tasa = (float) $concepto->importe > 0
                    ? (float) $concepto->iva / (float) $concepto->importe
                    : 0.0;

                return [
                    // Sin `pago_id`: una nota de crédito no ampara un pago, y
                    // ponérselo la haría figurar en `pagosOcupados` y bloquearía
                    // ese dinero como «ya facturado» por segunda vez.
                    'pago_id' => null,
                    'concepto_origen_id' => $concepto->id,
                    'clave_sat' => $concepto->clave_sat,
                    'clave_unidad_sat' => $concepto->clave_unidad_sat,
                    'descripcion' => $concepto->descripcion,
                    'cantidad' => 1,
                    'valor_unitario' => $importe,
                    'importe' => $importe,
                    'iva' => round($importe * $tasa, 2),
                    'objeto_impuesto' => $concepto->objeto_impuesto,
                ];
            })
            ->filter()
            ->values();
    }
}
