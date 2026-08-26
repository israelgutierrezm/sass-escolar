<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Qué grupos empiezan sin quien les dé clase.
 *
 * ── Es una COLA DE TRABAJO, y por eso va filtrada ────────────────────────
 * Un listado de todos los grupos con una columna en cero enseña a ignorar la
 * columna. Aquí sólo salen los que tienen algo pendiente, así que una lista
 * vacía significa que no hay nada que hacer — que es la única forma de que
 * alguien la mire todos los días.
 */
class MateriasSinTitular extends DefinicionReporte
{
    public function clave(): string
    {
        return 'materias-sin-titular';
    }

    public function titulo(): string
    {
        return 'Materias sin titular';
    }

    public function descripcion(): string
    {
        return 'Los grupos con materias abiertas a las que todavía no se les asigna docente titular. '
            .'NO cuenta las asignaciones RETIRADAS como si siguieran vigentes, que es el error fácil '
            .'aquí. Y no dice a quién asignarle: para eso está el buscador de docentes del grupo.';
    }

    public function fuente(): string
    {
        return 'grupos';
    }

    public function areaSugerida(): string
    {
        return 'control-escolar';
    }

    public function filtrosFijos(): array
    {
        return ['solo_sin_titular' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave', 'nombre', 'ciclo', 'campus', 'plan', 'materias', 'sin_titular'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['sin_titular', 'desc'];
    }
}
