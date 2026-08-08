<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ControlEscolar\Historial;

/**
 * Cuántos créditos suman unas materias aprobadas.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * La misma suma estaba escrita tres veces —el expediente del alumno, el portal
 * del padre y el certificado electrónico— y con distinta precisión: dos
 * decimales en dos sitios y uno en el otro. El mismo alumno tenía 295 créditos
 * en una pantalla y 295.3 en otra, sin que nada fallara.
 *
 * ── Dos decimales, y no los del plan ───────────────────────────────────────
 * Un crédito no es una calificación: su precisión no sale de
 * `decimales_calificacion`. SATCA llega a medios créditos y algunos planes usan
 * cuartos, así que dos decimales cubren lo que existe y sobra para lo que se ve
 * en pantalla.
 */
class Creditos
{
    /**
     * @param  iterable<int, Historial>  $aprobadas
     */
    public static function sumar($aprobadas): float
    {
        $total = 0.0;

        foreach ($aprobadas as $renglon) {
            $total += (float) ($renglon->planMateria?->asignatura?->creditos ?? 0);
        }

        return round($total, 2);
    }
}
