<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * calificaciones_componente (TENANT) — un número capturado por el docente.
 *
 * Una fila = un alumno (por su inscripción) en un componente del
 * `esquema_evaluacion` de su materia-en-plan.
 */
class CalificacionComponente extends Model
{
    use TieneAuditoria;

    protected $table = 'calificaciones_componente';

    protected $fillable = [
        'inscripcion_id',
        'esquema_evaluacion_id',
        'calificacion',
        'capturado_por',
        'capturado_en',
    ];

    protected function casts(): array
    {
        return [
            'calificacion' => 'decimal:2',
            'capturado_en' => 'datetime',
        ];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class, 'inscripcion_id');
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(EsquemaEvaluacion::class, 'esquema_evaluacion_id');
    }

    /** La persona que capturó, no el usuario: la cuenta puede desaparecer. */
    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'capturado_por');
    }
}
