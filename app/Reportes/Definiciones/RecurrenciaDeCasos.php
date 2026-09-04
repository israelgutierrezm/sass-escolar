<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Las situaciones que volvieron después de darse por atendidas.
 *
 * ── Sólo existe porque reabrir crea un caso NUEVO ─────────────────────────
 * Si reabrir devolviera el cerrado a «abierto», esta lista no se podría hacer:
 * el cierre y su motivo se habrían reescrito. Es la razón por la que
 * `reabierto` NO es un estado, y este reporte es lo que la justifica.
 *
 * Y es un indicador que se lee CON el de desenlaces: una escuela que cierra
 * mucho y reabre mucho no está resolviendo, está cerrando pronto.
 */
class RecurrenciaDeCasos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'recurrencia-de-casos';
    }

    public function titulo(): string
    {
        return 'Situaciones que reaparecieron';
    }

    public function descripcion(): string
    {
        return 'Casos abiertos sobre alguien a quien ya se había acompañado y cuyo caso anterior '
            .'se había cerrado. Léelo junto al de desenlaces: cerrar mucho y reabrir mucho no es '
            .'resolver, es cerrar pronto.';
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
        return ['solo_reaperturas' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['folio', 'alumno', 'programa_academico', 'campus', 'generacion', 'estado',
            'nivel_apertura', 'abierto_en', 'situacion_actual'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['abierto_en', 'desc'];
    }
}
