<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * actas (TENANT) — el acta de calificaciones de una materia-grupo.
 *
 * Nace ABIERTA al empezar a capturar y se CIERRA cuando el titular la firma:
 * ese cierre es el que vuelca las calificaciones al kárdex. Una vez cerrada no
 * se toca; para corregir se emite otra acta con `acta_origen_id` apuntando a
 * esta.
 */
class Acta extends Model
{
    use TieneAuditoria;

    public const ABIERTA = 'abierta';
    public const CERRADA = 'cerrada';
    public const CANCELADA = 'cancelada';

    protected $table = 'actas';

    protected $fillable = [
        'asignatura_grupo_id',
        'tipo_evaluacion_id',
        'folio',
        'situacion',
        'cerrada_por',
        'cerrada_en',
        'acta_origen_id',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'cerrada_en' => 'datetime',
        ];
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function tipoEvaluacion(): BelongsTo
    {
        return $this->belongsTo(TipoEvaluacion::class, 'tipo_evaluacion_id');
    }

    /** El docente titular que firmó. */
    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'cerrada_por');
    }

    /** El acta que esta corrige, si es un acta de corrección. */
    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'acta_origen_id');
    }

    /** Correcciones emitidas sobre esta acta. */
    public function correcciones(): HasMany
    {
        return $this->hasMany(self::class, 'acta_origen_id');
    }

    /** Los renglones del kárdex que asentó. */
    public function historial(): HasMany
    {
        return $this->hasMany(Historial::class, 'acta_id');
    }

    public function estaCerrada(): bool
    {
        return $this->situacion === self::CERRADA;
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('situacion', '!=', self::CANCELADA);
    }
}
