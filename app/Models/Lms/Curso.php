<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Academico\PlanMateria;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\AsignaturaGrupo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * cursos (TENANT) — el contenido de una materia.
 *
 * La misma tabla sirve de PLANTILLA (cuelga de `plan_materia`: el armado en
 * línea que prepara el administrador) y de INSTANCIA (cuelga de
 * `asignatura_grupo`: lo que de verdad cursa un grupo). Se distinguen por cuál
 * de los dos padres tiene, nunca por un campo «tipo» que se puede desincronizar.
 */
class Curso extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'cursos';

    protected $fillable = [
        'plan_materia_id',
        'asignatura_grupo_id',
        'plantilla_origen_id',
        'titulo',
        'presentacion',
        'docente_puede_agregar',
        'docente_puede_ponderar',
        'publicado',
    ];

    protected function casts(): array
    {
        return [
            'docente_puede_agregar' => 'boolean',
            'docente_puede_ponderar' => 'boolean',
            'publicado' => 'boolean',
        ];
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function plantillaOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'plantilla_origen_id');
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(Actividad::class, 'curso_id')->orderBy('orden')->orderBy('id');
    }

    /** Es el armado reutilizable de una materia del plan, no lo de un grupo. */
    public function esPlantilla(): bool
    {
        return $this->plan_materia_id !== null;
    }
}
