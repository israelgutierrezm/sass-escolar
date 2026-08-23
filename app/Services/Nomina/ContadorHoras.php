<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\Asistencia\Checada;
use Illuminate\Support\Carbon;

/**
 * Cuántas horas trabajó una persona en un rango, según el reloj checador.
 *
 * ── La tabla se llama `checadas`, no `marcas_reloj` ───────────────────────
 * La spec del módulo 10 la nombra así y no existe con ese nombre. Es la trampa
 * que la bitácora del proyecto ya tenía anotada —el nombre de una tabla se
 * pregunta, no se adivina— y en este módulo estaba servida otra vez.
 *
 * ── Una entrada sin salida NO se paga, y se REPORTA ───────────────────────
 * Es la decisión que gobierna este servicio. A alguien se le olvida checar la
 * salida todo el tiempo, y hay exactamente tres formas de tratarlo:
 *
 *  - Contarla hasta el final del día: se le pagarían horas que no trabajó, y el
 *    error es a favor del empleado, así que nadie lo reclama nunca.
 *  - Ignorarla en silencio: se le paga de menos y lo reclama al día siguiente,
 *    pero nadie sabe por qué faltaron horas.
 *  - No pagarla y DECIRLO. Es la única que se puede corregir antes de pagar.
 *
 * Por eso `contar()` devuelve las horas Y los huecos, y el recibo se los anota.
 */
class ContadorHoras
{
    /**
     * @return array{horas: float, sin_cerrar: array<int, string>}
     */
    public function contar(int $personaId, string $desde, string $hasta): array
    {
        $checadas = Checada::query()
            ->where('persona_id', $personaId)
            ->whereBetween('momento', [$desde.' 00:00:00', $hasta.' 23:59:59'])
            ->orderBy('momento')
            ->get(['tipo_movimiento', 'momento']);

        $minutos = 0;
        $abierta = null;
        $sinCerrar = [];

        foreach ($checadas as $checada) {
            if ($checada->tipo_movimiento === Checada::ENTRADA) {
                /*
                 * Dos entradas seguidas: la primera se quedó sin salida. Se
                 * reporta y se descarta, en vez de emparejar la primera con la
                 * salida de la segunda —que pagaría el hueco de en medio—.
                 */
                if ($abierta !== null) {
                    $sinCerrar[] = $abierta->format('Y-m-d H:i');
                }

                $abierta = $checada->momento;

                continue;
            }

            // Una salida sin entrada no se puede medir desde ningún lado.
            if ($abierta === null) {
                $sinCerrar[] = $checada->momento->format('Y-m-d H:i').' (salida sin entrada)';

                continue;
            }

            $minutos += $abierta->diffInMinutes($checada->momento);
            $abierta = null;
        }

        // La última entrada del rango puede quedar abierta.
        if ($abierta instanceof Carbon) {
            $sinCerrar[] = $abierta->format('Y-m-d H:i');
        }

        return [
            // Dos decimales: media hora es 0.5, no 0.4999999.
            'horas' => round($minutos / 60, 2),
            'sin_cerrar' => $sinCerrar,
        ];
    }
}
