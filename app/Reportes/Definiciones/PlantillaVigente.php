<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién está contratado hoy, en qué puesto y en qué campus.
 *
 * Es el organigrama hecho tabla. Lo pide una acreditadora, lo pide el IMSS y lo
 * pide la dirección al presupuestar.
 */
class PlantillaVigente extends DefinicionReporte
{
    public function clave(): string
    {
        return 'plantilla-vigente';
    }

    public function titulo(): string
    {
        return 'Plantilla vigente';
    }

    public function descripcion(): string
    {
        return 'El personal con vínculo laboral abierto, con su puesto y su antigüedad. Una fila es un '
            .'EXPEDIENTE: quien fue recontratado tiene dos, y son dos historias distintas. NO trae '
            .'sueldos —eso vive detrás del permiso de percepciones— y NO es lo mismo que el catálogo '
            .'de docentes, que es identidad académica y la tiene también quien no está contratado.';
    }

    public function fuente(): string
    {
        return 'plantilla-laboral';
    }

    public function areaSugerida(): string
    {
        return 'rh';
    }

    public function filtrosFijos(): array
    {
        return ['solo_vigentes' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['numero_empleado', 'empleado', 'puesto', 'campus', 'tipo_contrato', 'situacion', 'fecha_ingreso', 'antiguedad_anios'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha_ingreso', 'asc'];
    }
}
