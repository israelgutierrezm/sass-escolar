<?php

declare(strict_types=1);

namespace App\Reportes;

/**
 * De qué es un filtro, y por lo tanto CÓMO SE VALIDA su valor.
 *
 * ── Se valida por TIPO, no por cadena ────────────────────────────────────
 * Es la diferencia entre un motor seguro y uno que confía en el desplegable.
 * El valor de un filtro llega del navegador, así que `entero` se valida como
 * entero y `lista` contra las opciones VIVAS del catálogo: quien escriba a mano
 * un id que no está en su alcance no obtiene una consulta más ancha, obtiene un
 * error de validación.
 */
enum TipoFiltro: string
{
    case Texto = 'texto';
    case Numero = 'numero';
    case RangoNumero = 'rango_numero';
    case Fecha = 'fecha';
    case RangoFecha = 'rango_fecha';
    case Lista = 'lista';
    case ListaMultiple = 'lista_multiple';
    case Booleano = 'booleano';

    /** ¿Su valor es un par [desde, hasta]? */
    public function esRango(): bool
    {
        return in_array($this, [self::RangoNumero, self::RangoFecha], true);
    }

    /** ¿Se elige de un catálogo? Entonces el valor se comprueba contra él. */
    public function esDeCatalogo(): bool
    {
        return in_array($this, [self::Lista, self::ListaMultiple], true);
    }
}
