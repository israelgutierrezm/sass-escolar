<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * seriacion (TENANT) — arista del DAG de prerequisitos entre plan_materias.
 */
class Seriacion extends Model
{
    use TieneAuditoria;

    protected $table = 'seriacion';

    protected $fillable = [
        'plan_materia_id',
        'requiere_plan_materia_id',
        'tipo',
        'minimo_creditos',
    ];

    protected function casts(): array
    {
        return [
            'minimo_creditos' => 'float',
        ];
    }

    /** La materia que tiene el requisito. */
    public function materia(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }

    /** La materia que debe llevarse antes. */
    public function requiere(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'requiere_plan_materia_id');
    }
}
