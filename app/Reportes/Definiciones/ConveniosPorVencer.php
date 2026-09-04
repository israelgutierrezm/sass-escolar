<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Los convenios que hay que renovar.
 *
 * Un convenio que vence deja de amparar asignaciones NUEVAS —el alumno que ya
 * está no se interrumpe—, así que el daño no se ve el día que vence sino la
 * semana siguiente, cuando no se puede mandar a nadie. Esto es lo que da
 * tiempo a renovarlo.
 */
class ConveniosPorVencer extends DefinicionReporte
{
    public function clave(): string
    {
        return 'convenios-formativos-por-vencer';
    }

    public function titulo(): string
    {
        return 'Convenios por vencer';
    }

    public function descripcion(): string
    {
        return 'Los que vencen dentro de los próximos 60 días —la misma ventana que avisa la '
            .'pantalla de convenios—. Renovar crea una versión nueva; la anterior se conserva.';
    }

    public function fuente(): string
    {
        return 'convenios_formativos';
    }

    public function areaSugerida(): string
    {
        return 'procesos-formativos';
    }

    public function filtrosFijos(): array
    {
        return ['por_vencer' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['organizacion', 'folio', 'version', 'tipo', 'vigente_hasta', 'ampara'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['vigente_hasta', 'asc'];
    }
}
