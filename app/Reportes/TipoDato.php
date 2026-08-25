<?php

declare(strict_types=1);

namespace App\Reportes;

/**
 * De qué es una celda del reporte.
 *
 * ── No es cosmético ──────────────────────────────────────────────────────
 * El tipo decide tres cosas a la vez: cómo se alinea en la pantalla, cómo se
 * FORMATEA la celda del Excel —una fecha que viaja como texto no se puede
 * ordenar ni restar en la hoja de cálculo, que es la mitad de para qué alguien
 * pide un Excel— y qué agregaciones tienen sentido. Sin él, todo sale como
 * texto y el archivo sirve para mirar, no para trabajar.
 */
enum TipoDato: string
{
    case Texto = 'texto';
    case Entero = 'entero';
    case Decimal = 'decimal';
    case Dinero = 'dinero';
    case Fecha = 'fecha';
    case FechaHora = 'fecha_hora';
    case Booleano = 'booleano';
    case Porcentaje = 'porcentaje';

    /** Los números y las fechas se leen a la derecha; el texto a la izquierda. */
    public function alineacion(): string
    {
        return match ($this) {
            self::Texto => 'izquierda',
            self::Booleano => 'centro',
            default => 'derecha',
        };
    }

    /** ¿Se puede sumar o promediar? Sumar folios de acta no significa nada. */
    public function esNumerico(): bool
    {
        return in_array($this, [self::Entero, self::Decimal, self::Dinero, self::Porcentaje], true);
    }
}
