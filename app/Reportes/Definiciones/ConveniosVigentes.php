<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Los convenios que hoy amparan asignaciones.
 *
 * «Vigente» aquí cruza la FECHA y la SITUACIÓN: uno con la situación «vigente»
 * y la fecha pasada se ve bien en cualquier listado y no ampara a nadie. Es la
 * pregunta que hay que contestar antes de mandar a alguien.
 */
class ConveniosVigentes extends DefinicionReporte
{
    public function clave(): string
    {
        return 'convenios-formativos-vigentes';
    }

    public function titulo(): string
    {
        return 'Convenios vigentes';
    }

    public function descripcion(): string
    {
        return 'Los convenios que hoy amparan asignaciones: dentro de fecha Y con una situación que '
            .'las acepta. No se acota por campus, porque un convenio lo firma la dirección.';
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
        return ['vigentes' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['organizacion', 'folio', 'version', 'tipo', 'situacion', 'vigente_desde', 'vigente_hasta'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['vigente_hasta', 'asc'];
    }
}
