<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * plantillas_evaluacion (TENANT) — un criterio de evaluación con nombre.
 *
 * Se define una vez y se aplica al plan completo; sus componentes se
 * materializan en el `esquema_evaluacion` de cada materia.
 */
class PlantillaEvaluacion extends Model
{
    use TieneAuditoria;

    protected $table = 'plantillas_evaluacion';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(PlantillaComponente::class, 'plantilla_id')->orderBy('orden');
    }

    /** Planes que la usan como criterio por defecto. */
    public function planes(): HasMany
    {
        return $this->hasMany(PlanEstudio::class, 'plantilla_evaluacion_id');
    }

    /** Materias cuyo esquema salió de esta plantilla. */
    public function materias(): HasMany
    {
        return $this->hasMany(PlanMateria::class, 'plantilla_evaluacion_id');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    public function sumaPorcentajes(): float
    {
        return (float) $this->componentes()->sum('porcentaje');
    }

    /** Solo una plantilla que suma 100% puede aplicarse a una materia. */
    public function estaCompleta(): bool
    {
        return abs($this->sumaPorcentajes() - 100.0) < 0.01;
    }

    /**
     * Cuántos parciales distintos define. Cero significa que todo va directo al
     * curso, sin cortes.
     */
    public function numeroDeParciales(): int
    {
        return $this->componentes()->whereNotNull('parcial')->distinct()->count('parcial');
    }
}
