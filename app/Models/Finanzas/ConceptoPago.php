<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * conceptos_pago (TENANT-CONFIG) — QUÉ se cobra.
 *
 * Nace con sus datos fiscales (`clave_sat`, `clave_unidad_sat`, `gravado`,
 * `tasa_iva`) aunque el CFDI sea la entrega 7.3: agregarlos después obligaría a
 * rellenar a mano las claves de conceptos que ya tienen adeudos y pagos
 * históricos colgando.
 */
class ConceptoPago extends Model
{
    use TieneAuditoria;

    protected $table = 'conceptos_pago';

    protected $fillable = [
        'clave',
        'nombre',
        'clave_sat',
        'clave_unidad_sat',
        'gravado',
        'tasa_iva',
        'cuenta_contable',
    ];

    protected function casts(): array
    {
        return [
            'gravado' => 'boolean',
            'tasa_iva' => 'decimal:4',
        ];
    }
}
