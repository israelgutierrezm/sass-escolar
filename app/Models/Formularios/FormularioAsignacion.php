<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * formulario_asignacion (TENANT) — a qué nivel/carrera/oferta/rol aplica un
 * formulario. Referencia polimórfica sin FK (el destino vive en tablas
 * distintas, incluso en la landlord).
 */
class FormularioAsignacion extends Model
{
    use TieneAuditoria;

    protected $table = 'formulario_asignacion';

    protected $fillable = [
        'formulario_id',
        'aplica_a_tipo',
        'aplica_a_id',
        'obligatorio',
    ];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
        ];
    }

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class);
    }

    /** Filtra las asignaciones que apuntan a un destino concreto. */
    public function scopeParaDestino(Builder $query, string $tipo, int $id): Builder
    {
        return $query->where('aplica_a_tipo', $tipo)->where('aplica_a_id', $id);
    }
}
