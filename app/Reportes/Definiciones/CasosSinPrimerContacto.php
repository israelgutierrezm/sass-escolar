<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Los que se pasaron del plazo y a los que nadie ha llamado.
 *
 * ── Es el fallo que este módulo existe para impedir ───────────────────────
 * Un caso abierto sobre alguien con quien nadie ha hablado no acompaña a nadie:
 * es una carpeta. Por eso tiene su propio reporte y no es un filtro escondido —
 * quien mira esta lista sabe exactamente qué hay que destrabar hoy.
 */
class CasosSinPrimerContacto extends DefinicionReporte
{
    public function clave(): string
    {
        return 'casos-sin-primer-contacto';
    }

    public function titulo(): string
    {
        return 'Fuera de plazo de primer contacto';
    }

    public function descripcion(): string
    {
        return 'Casos abiertos con un plazo fijado y sin ningún contacto registrado. Uno atendido '
            .'a tiempo no aparece aquí aunque siga abierto.';
    }

    public function fuente(): string
    {
        return 'casos_permanencia';
    }

    public function areaSugerida(): string
    {
        return 'permanencia';
    }

    public function filtrosFijos(): array
    {
        return ['solo_fuera_de_plazo' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['folio', 'alumno', 'campus', 'estado', 'prioridad', 'responsable', 'abierto_en'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['abierto_en', 'asc'];
    }
}
