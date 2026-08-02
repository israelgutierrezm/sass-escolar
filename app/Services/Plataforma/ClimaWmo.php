<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

/**
 * Traduce los códigos del servicio meteorológico a algo que se pueda leer.
 *
 * Open-Meteo devuelve códigos WMO —un 61 es «lluvia ligera»— y un índice de
 * aire en la escala estadounidense. Aquí se convierten a texto en español y a
 * un ícono, del lado del SERVIDOR: la vista no tiene por qué saber qué es un
 * código WMO, y el texto en español correcto se escribe una vez y en un lugar.
 */
class ClimaWmo
{
    /**
     * Qué está pasando allá afuera.
     *
     * Los códigos vienen agrupados por familias (5x llovizna, 6x lluvia, 7x
     * nieve, 8x chubascos, 9x tormenta) y se contestan por rango: enumerar los
     * cien códigos daría matices que nadie mira en una tarjeta del panel.
     */
    public static function texto(int $codigo): string
    {
        return match (true) {
            $codigo === 0 => 'Despejado',
            $codigo <= 2 => 'Parcialmente nublado',
            $codigo === 3 => 'Nublado',
            $codigo <= 48 => 'Neblina',
            $codigo <= 57 => 'Llovizna',
            $codigo <= 67 => 'Lluvia',
            $codigo <= 77 => 'Nieve',
            $codigo <= 82 => 'Chubascos',
            $codigo <= 86 => 'Nevadas',
            default => 'Tormenta eléctrica',
        };
    }

    /**
     * El emoji que acompaña.
     *
     * De noche cambia: un sol radiante a las diez de la noche se ve mal y se
     * lee peor.
     */
    public static function icono(int $codigo, bool $esDeDia): string
    {
        if ($codigo === 0) {
            return $esDeDia ? '☀️' : '🌙';
        }

        return match (true) {
            $codigo <= 2 => $esDeDia ? '⛅' : '☁️',
            $codigo === 3 => '☁️',
            $codigo <= 48 => '🌫️',
            $codigo <= 57 => '🌦️',
            $codigo <= 67 => '🌧️',
            $codigo <= 77 => '❄️',
            $codigo <= 82 => '🌧️',
            $codigo <= 86 => '🌨️',
            default => '⛈️',
        };
    }

    /**
     * Qué significa el índice de calidad del aire.
     *
     * Se usa la escala de la EPA (0-500) porque es la que devuelve el servicio.
     * Lo que importa aquí no es el número sino la RECOMENDACIÓN: una escuela
     * mira este dato para decidir si saca a los alumnos al patio.
     *
     * @return array{etiqueta: string, color: string, recomendacion: string}
     */
    public static function aire(int $indice): array
    {
        return match (true) {
            $indice <= 50 => [
                'etiqueta' => 'Buena',
                'color' => '#16a34a',
                'recomendacion' => 'Sin restricciones para actividades al aire libre.',
            ],
            $indice <= 100 => [
                'etiqueta' => 'Aceptable',
                'color' => '#ca8a04',
                'recomendacion' => 'Quien tenga asma o alergias puede resentirlo.',
            ],
            $indice <= 150 => [
                'etiqueta' => 'Mala para grupos sensibles',
                'color' => '#ea580c',
                'recomendacion' => 'Conviene acortar el esfuerzo físico al aire libre.',
            ],
            $indice <= 200 => [
                'etiqueta' => 'Mala',
                'color' => '#dc2626',
                'recomendacion' => 'Mejor mover a interiores las actividades deportivas.',
            ],
            $indice <= 300 => [
                'etiqueta' => 'Muy mala',
                'color' => '#9333ea',
                'recomendacion' => 'Suspender actividades al aire libre.',
            ],
            default => [
                'etiqueta' => 'Peligrosa',
                'color' => '#7f1d1d',
                'recomendacion' => 'Permanecer en interiores.',
            ],
        };
    }
}
