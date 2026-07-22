<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reparte 100% entre N rubros de forma equitativa.
 *
 * El detalle que importa: 100 entre 3 no da un número exacto de centésimas.
 * Redondear cada parte por su cuenta produce 33.33 × 3 = 99.99, y un esquema
 * que no suma 100 es un esquema que el motor de calificaciones rechaza. O sea
 * que el reparto "automático" dejaría la materia sin poder calificarse.
 *
 * Se usa el método del resto mayor: se reparte el piso en centésimas y los
 * centavos sobrantes se entregan de uno en uno a los primeros rubros. Así la
 * suma es exactamente 100 y la diferencia entre el rubro más alto y el más bajo
 * nunca pasa de 0.01.
 *
 *   3 rubros → 33.34, 33.33, 33.33
 *   7 rubros → 14.29, 14.29, 14.29, 14.29, 14.28, 14.28, 14.28
 */
class RepartidorPorcentajes
{
    /**
     * @return array<int, float> Porcentajes en el mismo orden de los rubros.
     */
    public function equitativo(int $cuantos, float $total = 100.0): array
    {
        if ($cuantos < 1) {
            return [];
        }

        // Se trabaja en centésimas enteras para no arrastrar el error del punto
        // flotante: el reparto tiene que cuadrar al centavo.
        $centesimas = (int) round($total * 100);
        $base = intdiv($centesimas, $cuantos);
        $sobrantes = $centesimas - ($base * $cuantos);

        $reparto = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $reparto[] = ($base + ($i < $sobrantes ? 1 : 0)) / 100;
        }

        return $reparto;
    }

    /**
     * Lo que falta para llegar a 100 desde lo ya asignado. Negativo si se pasó.
     */
    public function disponible(float $asignado, float $total = 100.0): float
    {
        return round($total - $asignado, 2);
    }
}
