<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Academico\PlanMateria;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * regla_materias_previas (TENANT) — las materias que hay que traer aprobadas.
 *
 * Apunta a `plan_materias` y no a `asignaturas`: lo que se aprueba es la
 * materia DE UN PLAN, y el historial se lleva contra eso. Con la asignatura
 * suelta, una regla del plan 2020 alcanzaría a quien cursa el 2024 con otro
 * mapa de créditos.
 */
class ReglaMateriaPrevia extends Model
{
    use TieneAuditoria;

    protected $table = 'regla_materias_previas';

    protected $fillable = ['version_id', 'plan_materia_id'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReglaProcesoVersion::class, 'version_id');
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }
}
