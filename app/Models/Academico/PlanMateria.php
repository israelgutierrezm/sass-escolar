<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * plan_materias (TENANT) — la asignatura dentro de un plan.
 */
class PlanMateria extends Model
{
    use TieneAuditoria;

    protected $table = 'plan_materias';

    protected $fillable = [
        'plan_id',
        'asignatura_id',
        'clave_en_plan',
        'periodo',
        'tipo',
        'creditos_en_plan',
    ];

    protected function casts(): array
    {
        return [
            'creditos_en_plan' => 'float',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_id');
    }

    public function asignatura(): BelongsTo
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function esquemaEvaluacion(): HasMany
    {
        return $this->hasMany(EsquemaEvaluacion::class, 'plan_materia_id');
    }

    /** Prerrequisitos de esta materia (filas de seriación donde es la que exige). */
    public function seriacion(): HasMany
    {
        return $this->hasMany(Seriacion::class, 'plan_materia_id');
    }
}
