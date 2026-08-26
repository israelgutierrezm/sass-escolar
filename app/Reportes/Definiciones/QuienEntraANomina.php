<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * A quién se le va a pagar este periodo.
 *
 * ── Es distinto de «quién está contratado», y ahí está el valor ──────────
 * Una licencia SIN goce sigue contratada y no cobra; una comisión sí cobra. Lo
 * decide la bandera `entra_a_nomina` del catálogo, no la clave de la situación,
 * y esa diferencia no se nota hasta el día de pago — cuando ya se depositó.
 */
class QuienEntraANomina extends DefinicionReporte
{
    public function clave(): string
    {
        return 'quien-entra-a-nomina';
    }

    public function titulo(): string
    {
        return 'Quién entra a nómina';
    }

    public function descripcion(): string
    {
        return 'El personal al que le toca cobrar, por la bandera de su situación. NO es «quién está '
            .'contratado»: una licencia sin goce sigue contratada y no aparece aquí, y una comisión sí. '
            .'Sirve para revisar la lista ANTES de calcular el periodo, que es cuando todavía se puede '
            .'corregir. NO trae importes: eso vive detrás del permiso de percepciones.';
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
        return ['solo_vigentes' => true, 'solo_en_nomina' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['numero_empleado', 'empleado', 'puesto', 'campus', 'tipo_contrato', 'situacion', 'cobra'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['numero_empleado', 'asc'];
    }
}
