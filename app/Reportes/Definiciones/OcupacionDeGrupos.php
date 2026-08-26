<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Cuántos alumnos tiene cada grupo contra su cupo.
 *
 * Es la pregunta de planeación: qué grupos se van a desbordar y cuáles habría
 * que cerrar o fusionar. Ordenado por ocupación descendente porque lo que
 * reclama decisión es lo lleno, no lo vacío.
 */
class OcupacionDeGrupos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'ocupacion-de-grupos';
    }

    public function titulo(): string
    {
        return 'Ocupación de grupos';
    }

    public function descripcion(): string
    {
        return 'Cada grupo con sus alumnos y su cupo. Una fila es un GRUPO: no cuenta alumnos, así '
            .'que sumar la columna daría más que la matrícula real —un alumno inscrito en dos grupos '
            .'está en los dos—. La ocupación sale en blanco cuando el grupo no tiene cupo capturado: '
            .'eso NO es 0 %.';
    }

    public function fuente(): string
    {
        return 'grupos';
    }

    public function areaSugerida(): string
    {
        return 'control-escolar';
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave', 'nombre', 'ciclo', 'campus', 'turno', 'alumnos', 'cupo', 'ocupacion'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['alumnos', 'desc'];
    }
}
