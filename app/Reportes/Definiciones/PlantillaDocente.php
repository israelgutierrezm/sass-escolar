<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién da clase, de qué tipo y en qué campus.
 *
 * Es el padrón que una acreditadora pide primero. Sin filtros fijos: es el
 * catálogo de docentes hecho archivo, con la carga del ciclo al lado.
 */
class PlantillaDocente extends DefinicionReporte
{
    public function clave(): string
    {
        return 'plantilla-docente';
    }

    public function titulo(): string
    {
        return 'Plantilla docente';
    }

    public function descripcion(): string
    {
        return 'Todos los docentes con su tipo, situación, campus y carga. Una fila es un DOCENTE: '
            .'quien imparte ocho materias sale una vez con un ocho, y quien da clase en dos campus '
            .'sale una vez con los dos. Sin elegir ciclo, la carga cuenta TODO su historial.';
    }

    public function fuente(): string
    {
        return 'docentes';
    }

    public function areaSugerida(): string
    {
        return 'docentes';
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave_profesor', 'docente', 'tipo', 'situacion', 'campus', 'cedula_profesional', 'materias', 'grupos'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['clave_profesor', 'asc'];
    }
}
