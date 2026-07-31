<?php

declare(strict_types=1);

namespace App\Models\Emision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * responsable_movimientos (TENANT) — un renglón de la bitácora de un responsable
 * de firma. Log append-only: solo se crea, nunca se edita ni se borra por sí
 * mismo (se va en cascada si se elimina la persona del historial).
 */
class ResponsableMovimiento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'responsable_movimientos';

    protected $fillable = [
        'responsable_id',
        'accion',
        'detalle',
        'realizado_por',
        'realizado_por_nombre',
    ];

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Responsable::class);
    }
}
