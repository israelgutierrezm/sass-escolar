<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Por qué se cayeron, y cuándo.
 *
 * El motivo es OBLIGATORIO al descartar precisamente para que este reporte
 * signifique algo: «Rechazado» a secas no dice ni cuándo ni por qué, que es la
 * única pregunta que se hace al revisar un embudo que no cierra.
 */
class ProspectosDescartados extends DefinicionReporte
{
    public function clave(): string
    {
        return 'prospectos-descartados';
    }

    public function titulo(): string
    {
        return 'Prospectos descartados';
    }

    public function descripcion(): string
    {
        return 'Los que salieron del embudo sin inscribirse, con su motivo y su fecha. NO incluye a '
            .'quienes siguen abiertos aunque lleven meses sin moverse: eso es otra cosa y se ve en '
            .'«Prospectos sin contactar».';
    }

    public function fuente(): string
    {
        return 'aspirantes';
    }

    public function areaSugerida(): string
    {
        return 'admisiones';
    }

    public function filtrosFijos(): array
    {
        return ['desenlace' => 'descartado'];
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave_aspirante', 'nombre', 'campus', 'programa', 'etapa', 'motivo_descarte', 'descartado_en'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['descartado_en', 'desc'];
    }
}
