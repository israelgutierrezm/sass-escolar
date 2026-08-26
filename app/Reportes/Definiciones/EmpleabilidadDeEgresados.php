<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * El padrón de egresados con su colocación: la tabla que pide una acreditadora.
 *
 * Sin filtros fijos a propósito: es el DENOMINADOR completo, y lo que se
 * presenta es la proporción. Filtrar por «sólo colocados» daría una lista de
 * éxitos sin contra qué compararla.
 */
class EmpleabilidadDeEgresados extends DefinicionReporte
{
    public function clave(): string
    {
        return 'empleabilidad-de-egresados';
    }

    public function titulo(): string
    {
        return 'Empleabilidad de egresados';
    }

    public function descripcion(): string
    {
        return 'Cada matrícula egresada con su colocación, si la tiene. Una fila es una MATRÍCULA: '
            .'quien egresó de dos carreras sale dos veces y quien cambió de trabajo tres veces sale '
            .'UNA. NO incluye colocaciones sin matrícula señalada ni de quien todavía no egresa —una '
            .'práctica profesional—: esas dos cifras las da el tablero de empleabilidad, y por eso el '
            .'total de aquí puede no cuadrar con el número de colocaciones registradas.';
    }

    public function fuente(): string
    {
        return 'egresados-colocacion';
    }

    public function areaSugerida(): string
    {
        return 'bolsa';
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'egresado', 'carrera', 'generacion', 'colocado', 'empresa', 'puesto', 'en_su_area'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['generacion', 'desc'];
    }
}
