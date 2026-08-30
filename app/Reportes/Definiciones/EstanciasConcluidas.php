<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién volvió, y qué se le revalidó.
 *
 * ── Por qué merece reporte propio ────────────────────────────────────────
 * Una estancia concluida sin revalidar es trabajo pendiente que nadie ve: el
 * alumno ya volvió y sus materias siguen sin asentarse en su historial. Y sólo
 * con la estancia CONCLUIDA se puede revalidar —mientras siga en curso, las
 * calificaciones de allá todavía pueden cambiar—, así que esta lista es
 * exactamente la de lo que ya se puede hacer.
 */
class EstanciasConcluidas extends DefinicionReporte
{
    public function clave(): string
    {
        return 'estancias-concluidas';
    }

    public function titulo(): string
    {
        return 'Estancias concluidas';
    }

    public function descripcion(): string
    {
        return 'Alumnos que ya volvieron de su estancia, con cuántas materias se les han revalidado. '
            .'Las que traen CERO son trabajo pendiente: sus materias siguen sin asentarse en el '
            .'historial. Las revalidaciones REVOCADAS no cuentan aquí, aunque se conserven: se dan '
            .'de baja lógica porque son historia escolar.';
    }

    public function fuente(): string
    {
        return 'movilidad-saliente';
    }

    public function areaSugerida(): string
    {
        return 'movilidad';
    }

    public function filtrosFijos(): array
    {
        return ['solo_concluidas' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'institucion', 'estancia_inicio', 'estancia_concluida', 'revalidaciones'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['revalidaciones', 'asc'];
    }
}
