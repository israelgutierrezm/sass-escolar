<?php

declare(strict_types=1);

namespace App\Historial;

/**
 * La calificación escrita con letra: 8 → «OCHO».
 *
 * ── Para qué sirve de verdad ──────────────────────────────────────────────
 * Para que no se pueda alterar el número a mano. Un «7» impreso se convierte en
 * un «9» con un bolígrafo; un «SIETE» al lado, no. Por eso los historiales
 * oficiales la traen, y por eso es una columna del catálogo y no un adorno.
 *
 * ── Por qué no se usa un paquete ──────────────────────────────────────────
 * Porque el rango que hace falta es minúsculo y conocido: las escalas del
 * sistema van de 0 a 100 y con hasta tres decimales. Un conversor general de
 * números a letras traería miles, millones y el género gramatical, todo para no
 * usarlo nunca. Y cuando el número cae fuera de lo que esto sabe decir —una
 * escala rara, un valor con decimales— se devuelve vacío en vez de inventar una
 * palabra: una columna en blanco se nota; un «SETENTA Y CINCO PUNTO CINCO» mal
 * escrito en un documento oficial, no.
 */
class CalificacionConLetra
{
    private const UNIDADES = [
        0 => 'CERO', 1 => 'UNO', 2 => 'DOS', 3 => 'TRES', 4 => 'CUATRO',
        5 => 'CINCO', 6 => 'SEIS', 7 => 'SIETE', 8 => 'OCHO', 9 => 'NUEVE',
        10 => 'DIEZ', 11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE',
        15 => 'QUINCE', 16 => 'DIECISÉIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO',
        19 => 'DIECINUEVE', 20 => 'VEINTE', 21 => 'VEINTIUNO', 22 => 'VEINTIDÓS',
        23 => 'VEINTITRÉS', 24 => 'VEINTICUATRO', 25 => 'VEINTICINCO',
        26 => 'VEINTISÉIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO',
        29 => 'VEINTINUEVE', 30 => 'TREINTA',
    ];

    private const DECENAS = [
        30 => 'TREINTA', 40 => 'CUARENTA', 50 => 'CINCUENTA', 60 => 'SESENTA',
        70 => 'SETENTA', 80 => 'OCHENTA', 90 => 'NOVENTA',
    ];

    public static function de(int|float|string|null $calificacion): string
    {
        if ($calificacion === null || $calificacion === '') {
            return '';
        }

        $numero = (float) $calificacion;

        // Sólo enteros: un «OCHO PUNTO CINCO» es más difícil de leer que el
        // propio 8.5 y en un documento oficial se presta a confusión.
        if ($numero < 0 || $numero > 100 || floor($numero) !== $numero) {
            return '';
        }

        return self::entero((int) $numero);
    }

    private static function entero(int $n): string
    {
        if (isset(self::UNIDADES[$n])) {
            return self::UNIDADES[$n];
        }

        if ($n === 100) {
            return 'CIEN';
        }

        $decena = intdiv($n, 10) * 10;
        $unidad = $n % 10;

        if (! isset(self::DECENAS[$decena])) {
            return '';
        }

        return $unidad === 0
            ? self::DECENAS[$decena]
            : self::DECENAS[$decena].' Y '.self::UNIDADES[$unidad];
    }
}
