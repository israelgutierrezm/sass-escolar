<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué se hace con lo que no cabe en la precisión del plan.
 *
 * Un plan que califica con enteros tiene que decidir qué es un promedio de 8.5:
 * unas escuelas lo suben a 9, otras exigen 8.6 para subir y otras nunca suben.
 * No es un detalle de presentación —decide quién se titula con mención y quién
 * conserva una beca—, así que se configura y no se supone.
 *
 * ── Un solo algoritmo ──────────────────────────────────────────────────────
 * Los tres modos son el mismo cálculo con distinto umbral: se corta a la
 * precisión del plan y se sube si lo que sobra llega al umbral. «Hacia abajo»
 * es un umbral que no se alcanza nunca. Escribirlos como tres algoritmos
 * distintos sería tres sitios donde el redondeo puede discrepar.
 */
enum ModoRedondeo: string
{
    /** 8.5 → 9. Lo que casi todo el mundo entiende por «redondear». */
    case MEDIO_ARRIBA = 'medio_arriba';

    /** 8.5 → 8, pero 8.6 → 9. Para quien pide algo más que la mitad. */
    case SEIS_ARRIBA = 'seis_arriba';

    /** 8.9 → 8. Nunca sube: lo que no cabe, se pierde. */
    case ABAJO = 'abajo';

    /**
     * Desde qué sobrante se sube. `null` = nunca.
     */
    public function umbral(): ?float
    {
        return match ($this) {
            self::MEDIO_ARRIBA => 0.5,
            self::SEIS_ARRIBA => 0.6,
            self::ABAJO => null,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::MEDIO_ARRIBA => 'De 0.5 en adelante sube (8.5 → 9)',
            self::SEIS_ARRIBA => 'De 0.6 en adelante sube (8.5 → 8, 8.6 → 9)',
            self::ABAJO => 'Nunca sube (8.9 → 8)',
        };
    }

    /**
     * Aplica el redondeo a `$valor` dejándolo con `$decimales` cifras.
     *
     * ── Por qué no basta `round()` ─────────────────────────────────────────
     * `round()` sólo sabe hacer medio-arriba. Los otros dos modos necesitan
     * comparar contra un umbral propio, así que el corte se hace a mano.
     *
     * ── La tolerancia no es adorno ─────────────────────────────────────────
     * En coma flotante 8.5 escalado puede valer 8.4999999999, y sin tolerancia
     * un promedio de 8.5 se quedaría en 8 con el modo que debería subirlo —un
     * error de un punto entero, decidido por el último bit de un float. La
     * comparación se hace con un margen para que el sobrante que «es» el
     * umbral cuente como tal.
     */
    public function aplicar(float $valor, int $decimales): float
    {
        $factor = 10 ** $decimales;
        $escalado = $valor * $factor;

        // El negativo no se espera en una calificación, pero si llega, se corta
        // hacia el cero en vez de inventar un resultado.
        $piso = $valor < 0 ? ceil($escalado) : floor($escalado);
        $sobrante = abs($escalado - $piso);

        $umbral = $this->umbral();
        $sube = $umbral !== null && $sobrante >= $umbral - 1e-9;

        $entero = $sube
            ? $piso + ($valor < 0 ? -1 : 1)
            : $piso;

        return $entero / $factor;
    }
}
