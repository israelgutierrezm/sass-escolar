<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Dónde nadie ha pasado lista.
 *
 * ── Es una cola de trabajo del DOCENTE, no un problema del alumno ────────
 * Y por eso importa ANTES que el reporte de riesgo: mientras una materia no
 * tenga lista pasada, su asistencia no es 0 % ni 100 % —sencillamente no
 * existe— y ningún alumno de esa materia puede aparecer en riesgo aunque no
 * haya ido nunca. Sin esta lista, «nadie está en riesgo» se lee como buena
 * noticia cuando puede significar que nadie está pasando lista.
 */
class MateriasSinListaPasada extends DefinicionReporte
{
    public function clave(): string
    {
        return 'materias-sin-lista';
    }

    public function titulo(): string
    {
        return 'Materias sin lista pasada';
    }

    public function descripcion(): string
    {
        return 'Inscripciones sin una sola sesión de asistencia registrada. NO significa que los '
            .'alumnos no hayan ido: significa que nadie ha pasado lista, y mientras eso siga así '
            .'ninguno de ellos puede aparecer en «Asistencia en riesgo» aunque no haya asistido nunca.';
    }

    public function fuente(): string
    {
        return 'asistencia-por-materia';
    }

    public function areaSugerida(): string
    {
        return 'control-escolar';
    }

    public function filtrosFijos(): array
    {
        return ['sin_lista' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'materia', 'grupo', 'ciclo', 'campus'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['matricula', 'asc'];
    }
}
