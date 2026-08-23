<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * recibo_conceptos (TENANT) — un renglón del recibo.
 *
 * `manual` distingue lo que el cálculo puso de lo que alguien agregó después.
 * Hace falta porque recalcular rehace el recibo desde cero: sin la marca, el
 * recálculo se llevaría en silencio un descuento capturado a mano y le pagaría
 * de más a alguien sin que nadie se enterara.
 *
 * `cantidad` va en NULL cuando el renglón es un importe a secas. Un «1»
 * inventado haría creer que se contó una unidad de algo.
 */
class ReciboConcepto extends Model
{
    use TieneAuditoria;

    protected $table = 'recibo_conceptos';

    protected $fillable = [
        'recibo_nomina_id',
        'concepto_nomina_id',
        'importe',
        'cantidad',
        'detalle',
        'manual',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'importe' => 'decimal:2',
            'cantidad' => 'decimal:2',
            'manual' => 'boolean',
        ];
    }

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(ReciboNomina::class, 'recibo_nomina_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoNomina::class, 'concepto_nomina_id');
    }
}
