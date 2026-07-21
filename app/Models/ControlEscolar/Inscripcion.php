<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * inscripcion (TENANT) — un alumno en UNA materia-grupo. Nivel único.
 */
class Inscripcion extends Model
{
    use TieneAuditoria;

    public const TIPO_ORDINARIA = 'ordinaria';
    public const TIPO_RECURSAMIENTO = 'recursamiento';

    public const FORMA_AUTOGESTIVA = 'autogestiva';
    public const FORMA_ADMINISTRATIVA = 'administrativa';

    protected $table = 'inscripcion';

    protected $fillable = [
        'matricula_oferta_id',
        'asignatura_grupo_id',
        'ciclo_id',
        'tipo',
        'forma_inscripcion',
        'situacion_id',
        'calificacion_final',
    ];

    protected function casts(): array
    {
        return [
            'calificacion_final' => 'decimal:2',
        ];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionInscripcion::class, 'situacion_id');
    }

    public function scopeDelCiclo(Builder $query, int $cicloId): Builder
    {
        return $query->where('ciclo_id', $cicloId);
    }

    public function scopeRecursamientos(Builder $query): Builder
    {
        return $query->where('tipo', self::TIPO_RECURSAMIENTO);
    }
}
