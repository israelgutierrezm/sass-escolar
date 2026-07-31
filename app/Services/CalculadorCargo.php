<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\Descuento;

/**
 * Cuánto le queda a pagar a un alumno por una línea del plan.
 *
 * Devuelve el monto base y el DESGLOSE de lo que lo modifica, no un número
 * suelto: cada beca o descuento aplicado sale como un renglón que después se
 * guarda en `adeudo_ajustes`. Es lo que permite que el estado de cuenta explique
 * su total en vez de pedir fe.
 *
 * No se calculan aquí dos cosas, a propósito:
 *  - El **recargo por mora**, porque no se sabe al generar sino al vencer
 *    (`CalculadorRecargos`).
 *  - El descuento por **pago anticipado**, porque depende de CUÁNDO pague, y eso
 *    solo se conoce al momento del pago.
 */
class CalculadorCargo
{
    /**
     * @return array{monto: float, ajustes: array<int, array<string, mixed>>, total: float}
     */
    public function para(ConceptoPlan $linea, MatriculaOferta $matricula, ?string $fecha = null): array
    {
        $fecha ??= now()->toDateString();
        $base = (float) $linea->monto;
        $ajustes = [];

        // --- Becas del alumno que cubren este concepto ---
        foreach ($this->becasDe($matricula, $fecha) as $becaAlumno) {
            $beca = $becaAlumno->beca;

            if ($beca === null || ! $beca->cubreConcepto($linea->concepto_id)) {
                continue;
            }

            $descuento = $beca->descuentoSobre($base);

            if ($descuento <= 0) {
                continue;
            }

            $ajustes[] = [
                'tipo' => AdeudoAjuste::TIPO_BECA,
                'origen_id' => $becaAlumno->id,
                'etiqueta' => $beca->nombre,
                'monto' => -$descuento, // con signo: resta
            ];
        }

        // --- Descuentos de campaña vigentes ---
        foreach ($this->descuentosDeCampana($fecha) as $descuento) {
            if (! $descuento->cubreConcepto($linea->concepto_id)) {
                continue;
            }

            $monto = $descuento->descuentoSobre($base);

            if ($monto <= 0) {
                continue;
            }

            $ajustes[] = [
                'tipo' => AdeudoAjuste::TIPO_DESCUENTO,
                'origen_id' => $descuento->id,
                'etiqueta' => $descuento->nombre,
                'monto' => -$monto,
            ];
        }

        // El total nunca baja de cero por más beneficios que se acumulen.
        $total = max(0.0, round($base + array_sum(array_column($ajustes, 'monto')), 2));

        return ['monto' => $base, 'ajustes' => $ajustes, 'total' => $total];
    }

    /** Becas activas y vigentes del alumno a esa fecha. */
    private function becasDe(MatriculaOferta $matricula, string $fecha)
    {
        return BecaAlumno::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->activas()
            ->with('beca.conceptos')
            ->get()
            ->filter(fn (BecaAlumno $b) => $b->aplicaEn($fecha));
    }

    /** Descuentos de campaña vigentes hoy. */
    private function descuentosDeCampana(string $fecha)
    {
        return Descuento::query()
            ->activos()
            ->where('tipo', Descuento::TIPO_CAMPANA)
            ->with('conceptos')
            ->get()
            ->filter(fn (Descuento $d) => $d->vigenteEn($fecha));
    }

    /**
     * Descuento por pago anticipado: se evalúa al pagar, comparando la fecha del
     * pago contra el límite del cargo.
     */
    public function descuentoPorAnticipacion(ConceptoPlan|int $concepto, string $fechaLimite, ?string $fechaPago = null): array
    {
        $fechaPago ??= now()->toDateString();
        $conceptoId = $concepto instanceof ConceptoPlan ? $concepto->concepto_id : $concepto;

        $diasDeAnticipo = (int) round(
            (strtotime($fechaLimite) - strtotime($fechaPago)) / 86400
        );

        if ($diasDeAnticipo <= 0) {
            return [];
        }

        return Descuento::query()
            ->activos()
            ->where('tipo', Descuento::TIPO_PAGO_ANTICIPADO)
            ->whereNotNull('dias_anticipacion')
            ->where('dias_anticipacion', '<=', $diasDeAnticipo)
            ->with('conceptos')
            ->get()
            ->filter(fn (Descuento $d) => $d->cubreConcepto($conceptoId) && $d->vigenteEn($fechaPago))
            ->values()
            ->all();
    }
}
