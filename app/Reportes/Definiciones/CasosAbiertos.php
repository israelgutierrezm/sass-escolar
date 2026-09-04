<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Models\Permanencia\EstadoCaso;
use App\Reportes\DefinicionReporte;

/**
 * El acompañamiento en curso.
 *
 * Cola de trabajo: se ordena por la fecha de apertura, lo más viejo primero.
 * Ordenada por prioridad, un caso bajo abierto hace dos meses no se mira nunca.
 */
class CasosAbiertos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'casos-abiertos';
    }

    public function titulo(): string
    {
        return 'Casos abiertos';
    }

    public function descripcion(): string
    {
        return 'Los acompañamientos en curso, con su responsable y su plazo de primer contacto. '
            .'No es un expediente disciplinario: nada de lo que se escribe en un caso cambia la '
            .'situación del alumno.';
    }

    public function fuente(): string
    {
        return 'casos_permanencia';
    }

    public function areaSugerida(): string
    {
        return 'permanencia';
    }

    /**
     * Todos los estados MENOS el cerrado, y no una lista escrita a mano.
     *
     * Con la lista a mano, el estado que alguien agregue mañana se quedaría
     * fuera de este reporte sin que nadie lo notara — y el caso dejaría de
     * aparecer en la única pantalla que lo enumera.
     */
    public function filtrosFijos(): array
    {
        return ['estado' => array_values(array_diff(
            EstadoCaso::claves(),
            [EstadoCaso::Cerrado->value],
        ))];
    }

    public function columnasPorOmision(): ?array
    {
        return ['folio', 'alumno', 'programa_academico', 'campus', 'estado', 'prioridad',
            'responsable', 'abierto_en', 'sla_vencido'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['abierto_en', 'asc'];
    }
}
