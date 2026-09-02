<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'objeto_impuesto',
        // ¿Es enseñanza deducible? Lo que decide si la factura puede llevar el
        // complemento IEDU. Nace apagado: una credencial de reposición o un
        // examen extraordinario no son colegiatura.
        'deducible_iedu',
        'cuenta_contable',
    ];

    protected function casts(): array
    {
        return [
            'gravado' => 'boolean',
            'deducible_iedu' => 'boolean',
            'tasa_iva' => 'decimal:4',
        ];
    }

    public function adeudos(): HasMany
    {
        return $this->hasMany(Adeudo::class, 'concepto_id');
    }

    /** Líneas de planes de cobro que usan este concepto. */
    public function lineasDePlan(): HasMany
    {
        return $this->hasMany(ConceptoPlan::class, 'concepto_id');
    }
}
