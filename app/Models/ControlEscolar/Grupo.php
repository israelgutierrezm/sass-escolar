<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Campus;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\Turno;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * grupos (TENANT) — contenedor de materias en un ciclo.
 */
class Grupo extends Model
{
    use TieneAuditoria;

    protected $table = 'grupos';

    protected $fillable = [
        'ciclo_id',
        'campus_id',
        'plan_id',
        'clave',
        'nombre',
        'cupo',
        'turno_id',
        'situacion_id',
        'grupo_origen_id',
    ];

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_id');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionGrupo::class, 'situacion_id');
    }

    /** Grupo del que se clonó éste, si aplica. */
    public function grupoOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'grupo_origen_id');
    }

    public function asignaturas(): HasMany
    {
        return $this->hasMany(AsignaturaGrupo::class, 'grupo_id');
    }
}
