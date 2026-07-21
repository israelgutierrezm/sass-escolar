<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * esquema_evaluacion (TENANT) — un componente de calificación por fila.
 */
class EsquemaEvaluacion extends Model
{
    use TieneAuditoria;

    protected $table = 'esquema_evaluacion';

    protected $fillable = [
        'plan_materia_id',
        'componente',
        'parcial',
        'porcentaje',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:2',
        ];
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }
}
