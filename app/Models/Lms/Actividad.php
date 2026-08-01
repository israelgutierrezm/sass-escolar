<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Enums\TipoActividad;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * actividades (TENANT) — una pieza de contenido dentro de un curso.
 *
 * Ponderada o formativa según tenga o no `esquema_evaluacion_id`. Esa es toda
 * la distinción: no hay un campo «cuenta/no cuenta» que pueda contradecir al
 * amarre.
 */
class Actividad extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'actividades';

    protected $fillable = [
        'curso_id',
        'tipo',
        'titulo',
        'instrucciones',
        'esquema_evaluacion_id',
        'puntos',
        'abre_en',
        'cierra_en',
        'permite_tarde',
        'orden',
        'publicada',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoActividad::class,
            'puntos' => 'decimal:2',
            'abre_en' => 'datetime',
            'cierra_en' => 'datetime',
            'permite_tarde' => 'boolean',
            'publicada' => 'boolean',
            'config' => 'array',
        ];
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class, 'curso_id');
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(EsquemaEvaluacion::class, 'esquema_evaluacion_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class, 'actividad_id');
    }

    /** Las reglas de aplicación, solo si la actividad es un examen. */
    public function examen(): HasOne
    {
        return $this->hasOne(Examen::class, 'actividad_id');
    }

    /** Cuenta para la calificación del parcial. */
    public function pondera(): bool
    {
        return $this->esquema_evaluacion_id !== null;
    }

    /**
     * Si el alumno puede entregar AHORA.
     *
     * Cerrada es cerrada aunque `permite_tarde` esté activo: «tarde» significa
     * que se acepta después de la fecha y queda marcado, no que no haya fecha.
     */
    public function abierta(): bool
    {
        $ahora = now();

        if ($this->abre_en !== null && $ahora->lt($this->abre_en)) {
            return false;
        }

        if ($this->cierra_en !== null && $ahora->gt($this->cierra_en)) {
            return $this->permite_tarde;
        }

        return true;
    }

    /** Lo que el alumno ve: publicadas y ya abiertas (o sin fecha de apertura). */
    public function scopeVisibles(Builder $query): Builder
    {
        return $query->where('publicada', true)
            ->where(fn ($q) => $q->whereNull('abre_en')->orWhere('abre_en', '<=', now()));
    }
}
