<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * asignaturas (TENANT) — catálogo puro de materias.
 */
class Asignatura extends Model
{
    use TieneAuditoria;

    protected $table = 'asignaturas';

    protected $fillable = [
        'identificador',
        'clave',
        'nombre',
        'creditos',
        'tipo_asignatura_id',
        'clasificacion_id',
        'area_id',
        'horas_teoria',
        'horas_practica',
        'horas_acompanamiento',
        'horas_independientes',
        'objetivos_desc',
        'bibliografia_desc',
    ];

    protected function casts(): array
    {
        return [
            'creditos' => 'float',
        ];
    }

    public function tipoAsignatura(): BelongsTo
    {
        return $this->belongsTo(TipoAsignatura::class);
    }

    public function clasificacion(): BelongsTo
    {
        return $this->belongsTo(ClasificacionAsignatura::class, 'clasificacion_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    /** Planes en los que se imparte esta asignatura (una fila por plan). */
    public function planMaterias(): HasMany
    {
        return $this->hasMany(PlanMateria::class, 'asignatura_id');
    }
}
