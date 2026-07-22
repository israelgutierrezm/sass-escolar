<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * factura_conceptos (TENANT) — los renglones del CFDI.
 *
 * `pago_id` no es decorativo: es lo que permite responder "¿este pago ya se
 * facturó?" sin adivinar por importes, y por tanto lo que impide facturar dos
 * veces el mismo dinero.
 *
 * La descripción y la clave del SAT se COPIAN del concepto de pago en vez de
 * leerse en vivo: si mañana la escuela renombra "Colegiatura" a "Cuota
 * mensual", la factura ya timbrada debe seguir diciendo lo que se timbró.
 */
class FacturaConcepto extends Model
{
    use TieneAuditoria;

    protected $table = 'factura_conceptos';

    protected $attributes = [
        'cantidad' => 1,
        'clave_unidad_sat' => 'E48',
        'iva' => 0,
    ];

    protected $fillable = [
        'factura_id',
        'pago_id',
        'clave_sat',
        'clave_unidad_sat',
        'descripcion',
        'cantidad',
        'valor_unitario',
        'importe',
        'iva',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'valor_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
            'iva' => 'decimal:2',
        ];
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'factura_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
