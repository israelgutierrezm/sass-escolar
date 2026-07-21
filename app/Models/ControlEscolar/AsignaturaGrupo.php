<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\PlanMateria;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * asignatura_grupo (TENANT) — la materia abierta en un grupo.
 */
class AsignaturaGrupo extends Model
{
    use TieneAuditoria;

    protected $table = 'asignatura_grupo';

    protected $fillable = [
        'grupo_id',
        'plan_materia_id',
        'fecha_inicio',
        'fecha_fin',
        'situacion_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
        ];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAsignaturaGrupo::class, 'situacion_id');
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioAsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    /** Docentes de la materia, con su tipo (titular/adjunto) en el pivote. */
    public function docentes(): BelongsToMany
    {
        return $this->belongsToMany(
            Docente::class,
            'docente_asignatura_grupo',
            'asignatura_grupo_id',
            'persona_id'
        )->withPivot('tipo')->withTimestamps();
    }

    /** El docente titular: el único que puede firmar el acta. */
    public function titular(): ?Docente
    {
        return $this->docentes()->wherePivot('tipo', 'titular')->first();
    }

    /** Tutores académicos asignados a la materia. */
    public function tutores(): HasMany
    {
        return $this->hasMany(TutorAsignaturaGrupo::class, 'asignatura_grupo_id');
    }
}
