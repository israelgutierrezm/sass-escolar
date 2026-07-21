<?php

declare(strict_types=1);

namespace App\Models\Asistencia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * asistencia_clase (TENANT) — presencia académica del alumno en una materia.
 */
class AsistenciaClase extends Model
{
    use TieneAuditoria;

    public const PRESENTE = 'presente';
    public const AUSENTE = 'ausente';
    public const JUSTIFICADA = 'justificada';
    public const RETARDO = 'retardo';

    protected $table = 'asistencia_clase';

    protected $fillable = [
        'inscripcion_id',
        'fecha',
        'estatus',
        'registrada_por',
        'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class);
    }

    /** Docente que pasó lista. */
    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'registrada_por');
    }

    /**
     * Faltas que cuentan para efectos de reprobación por inasistencia: las
     * justificadas y los retardos NO cuentan como falta.
     */
    public function scopeFaltas(Builder $query): Builder
    {
        return $query->where('estatus', self::AUSENTE);
    }

    public function scopeDeInscripcion(Builder $query, int $inscripcionId): Builder
    {
        return $query->where('inscripcion_id', $inscripcionId);
    }
}
