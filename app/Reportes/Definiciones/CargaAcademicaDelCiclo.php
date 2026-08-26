<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién imparte qué, materia por materia.
 *
 * ── EXIGE ciclo, y no por comodidad ──────────────────────────────────────
 * Sin él barrería todos los ciclos de la escuela y un docente aparecería con
 * las materias de los últimos cinco años juntas. No es que el resultado sea
 * grande: es que la pregunta no está hecha.
 */
class CargaAcademicaDelCiclo extends DefinicionReporte
{
    public function clave(): string
    {
        return 'carga-academica';
    }

    public function titulo(): string
    {
        return 'Carga académica del ciclo';
    }

    public function descripcion(): string
    {
        return 'La tabla de quién imparte qué: un renglón por docente y materia, con su grupo y sus '
            .'inscritos. Una materia con titular y adjunto son DOS renglones, así que contar filas '
            .'cuenta asignaciones y no docentes —para eso está «Plantilla docente»—. El campus es '
            .'donde SE IMPARTE, no donde el docente está adscrito.';
    }

    public function fuente(): string
    {
        return 'carga-academica';
    }

    public function areaSugerida(): string
    {
        return 'docentes';
    }

    public function filtrosObligatorios(): array
    {
        return ['ciclo_id'];
    }

    public function columnasPorOmision(): ?array
    {
        return ['docente', 'tipo', 'materia', 'grupo', 'ciclo', 'campus', 'inscritos'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['tipo', 'asc'];
    }
}
