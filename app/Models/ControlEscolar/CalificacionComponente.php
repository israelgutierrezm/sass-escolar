<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Concerns\ReviveAlGuardar;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
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
    use ReviveAlGuardar;
    use TieneAuditoria;

    protected $table = 'calificaciones_componente';

    protected $fillable = [
        'inscripcion_id',
        'esquema_evaluacion_id',
        'calificacion',
        // De dónde salió: `manual` la capturó una persona, `calculado` la
        // dedujo el LMS de las actividades del componente. El calculador NO
        // pisa lo manual, para que un ajuste del docente sobreviva al
        // siguiente recálculo.
        'fuente',
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

    /**
     * Las que de verdad traen un número.
     *
     * Una fila con `calificacion` en NULL no es una calificación: es el rastro
     * de que el docente guardó la hoja sin llegar a ese componente. Es la misma
     * regla de siempre —NULL no es cero—, aplicada a la pregunta «¿esto ya está
     * capturado?».
     *
     * Importa porque de esa respuesta cuelgan dos decisiones que congelan
     * trabajo ajeno: si un componente se puede retirar y si una plantilla se
     * puede volver a aplicar. Contando los blancos, abrir la pantalla de
     * captura y guardar bastaría para congelar la materia entera sin que nadie
     * haya calificado a nadie.
     */
    public function scopeCapturadas(Builder $consulta): Builder
    {
        return $consulta->whereNotNull('calificacion');
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
