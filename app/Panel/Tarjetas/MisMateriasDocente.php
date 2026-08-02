<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Las materias que imparte el docente y cuántos alumnos tiene en cada una.
 *
 * El alcance sale de `docente_asignatura_grupo`, no del permiso: es la misma
 * regla de dos capas de toda la docencia.
 */
class MisMateriasDocente implements TarjetaPanel
{
    public function clave(): string
    {
        return 'mis-materias';
    }

    public function titulo(): string
    {
        return 'Mis materias';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-materias';
    }

    public function tipo(): string
    {
        return 'metrica';
    }

    public function ancho(): int
    {
        return 1;
    }

    public function icono(): string
    {
        return 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25';
    }

    /**
     * Cuántas materias imparte y a cuánta gente, con la puerta a su listado.
     *
     * ── Resumen y no lista ─────────────────────────────────────────────────
     * Listaba las ocho materias con su cuenta de alumnos, y ocupaba media
     * pantalla del panel para decir lo que el docente ya sabe: cuáles son sus
     * materias. Lo que un panel debe contestar es «¿cuánto tengo encima?» y «¿a
     * dónde voy?»; el detalle vive en `/docencia`, que además tiene sitio para
     * mostrarlo bien.
     */
    public function datos(Usuario $usuario): ?array
    {
        $materias = AsignaturaGrupo::query()
            ->withCount('inscripciones')
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $usuario->persona_id))
            ->get(['id']);

        if ($materias->isEmpty()) {
            return null;
        }

        $alumnos = (int) $materias->sum('inscripciones_count');

        return [
            'valor' => $materias->count(),
            'formato' => 'entero',
            // El número grande es de materias; los alumnos van debajo porque es
            // el tamaño del trabajo, no su cuenta.
            'contexto' => $materias->count() === 1
                ? "1 materia · {$alumnos} alumnos"
                : "{$alumnos} alumnos en total",
            'enlace' => '/docencia',
        ];
    }
}
