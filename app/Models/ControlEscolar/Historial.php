<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * historial (TENANT) — el kárdex, por matricula_oferta.
 */
class Historial extends Model
{
    use TieneAuditoria;

    protected $table = 'historial';

    protected $fillable = [
        'matricula_oferta_id',
        'plan_materia_id',
        'ciclo_id',
        'asignatura_grupo_id',
        'tipo_evaluacion_id',
        'estatus_id',
        'calificacion',
        'situacion_reprobatoria_id',
        'acta_folio',
        'observacion_id',
    ];

    protected function casts(): array
    {
        return [
            'calificacion' => 'decimal:2',
        ];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function tipoEvaluacion(): BelongsTo
    {
        return $this->belongsTo(TipoEvaluacion::class, 'tipo_evaluacion_id');
    }

    public function estatus(): BelongsTo
    {
        return $this->belongsTo(EstatusHistorial::class, 'estatus_id');
    }

    public function situacionReprobatoria(): BelongsTo
    {
        return $this->belongsTo(SituacionReprobatoria::class, 'situacion_reprobatoria_id');
    }

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(ObservacionHistorial::class, 'observacion_id');
    }

    /** Kárdex de una inscripción a oferta concreta. */
    public function scopeDeMatricula(Builder $query, int $matriculaOfertaId): Builder
    {
        return $query->where('matricula_oferta_id', $matriculaOfertaId);
    }

    /** Sirve para evaluar seriación de tipo "aprobada". */
    public function scopeAprobadas(Builder $query): Builder
    {
        return $query->whereHas('estatus', fn ($q) => $q->where('clave', 'aprobada'));
    }
}
