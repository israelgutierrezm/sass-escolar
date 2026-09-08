<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * orden_compra_conceptos (TENANT) — un renglón de una orden de compra.
 */
class OrdenCompraConcepto extends Model
{
    use TieneAuditoria;

    protected $table = 'orden_compra_conceptos';

    protected $fillable = ['orden_compra_id', 'descripcion', 'cantidad', 'precio_unitario'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2', 'precio_unitario' => 'decimal:2'];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function importe(): float
    {
        return round((float) $this->cantidad * (float) $this->precio_unitario, 2);
    }
}
