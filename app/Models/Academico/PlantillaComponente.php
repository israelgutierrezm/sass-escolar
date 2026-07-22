<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plantilla_componentes (TENANT) — un rubro de una plantilla de evaluación.
 *
 * Espeja a `esquema_evaluacion`: `parcial` en NULL significa que el rubro va
 * directo al curso, sin pertenecer a ningún corte.
 */
class PlantillaComponente extends Model
{
    use TieneAuditoria;

    protected $table = 'plantilla_componentes';

    protected $fillable = [
        'plantilla_id',
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

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaEvaluacion::class, 'plantilla_id');
    }
}
