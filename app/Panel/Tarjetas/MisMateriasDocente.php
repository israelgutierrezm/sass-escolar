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
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function datos(Usuario $usuario): ?array
    {
        $materias = AsignaturaGrupo::query()
            ->with(['planMateria.asignatura:id,nombre', 'grupo:id,clave'])
            ->withCount('inscripciones')
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $usuario->persona_id))
            ->limit(8)
            ->get();

        if ($materias->isEmpty()) {
            return null;
        }

        return [
            'renglones' => $materias->map(fn (AsignaturaGrupo $ag) => [
                'etiqueta' => $ag->planMateria?->asignatura?->nombre ?? 'Materia',
                'detalle' => $ag->grupo?->clave,
                'valor' => $ag->inscripciones_count.' alumnos',
                'enlace' => '/docencia/materias/'.$ag->id,
            ])->values()->all(),
        ];
    }
}
