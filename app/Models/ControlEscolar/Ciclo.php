<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ciclos (TENANT) — periodo escolar. `campus_id` NULL = ciclo global.
 */
class Ciclo extends Model
{
    use TieneAuditoria;

    protected $table = 'ciclos';

    protected $fillable = [
        'campus_id',
        'clave',
        'nombre',
        'fecha_inicio',
        'fecha_fin',
        'situacion_id',
        'inscripcion_desde',
        'inscripcion_hasta',
        'altas_bajas_hasta',
        'captura_calif_hasta',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'inscripcion_desde' => 'date',
            'inscripcion_hasta' => 'date',
            'altas_bajas_hasta' => 'date',
            'captura_calif_hasta' => 'date',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionCiclo::class, 'situacion_id');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class, 'ciclo_id');
    }

    /** Ciclos del campus dado más los globales (campus_id NULL). */
    public function scopeParaCampus(Builder $query, int $campusId): Builder
    {
        return $query->where(fn ($q) => $q->where('campus_id', $campusId)->orWhereNull('campus_id'));
    }

    /** ¿La ventana de inscripción está abierta en la fecha dada? */
    public function inscripcionAbierta(?string $fecha = null): bool
    {
        $fecha = $fecha ?? now()->toDateString();

        if ($this->inscripcion_desde === null || $this->inscripcion_hasta === null) {
            return false;
        }

        return $fecha >= $this->inscripcion_desde->toDateString()
            && $fecha <= $this->inscripcion_hasta->toDateString();
    }
}
