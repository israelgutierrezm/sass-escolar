<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * planes_estudio (TENANT).
 */
class PlanEstudio extends Model
{
    use TieneAuditoria;

    protected $table = 'planes_estudio';

    protected $fillable = [
        'carrera_id',
        'clave',
        'abreviacion',
        'nombre',
        'rvoe',
        'fecha_rvoe',
        'autorizacion_reconocimiento_id',
        'tipo_periodo_id',
        'total_periodos',
        'calificacion_minima',
        'calificacion_maxima',
        'calificacion_minima_aprobatoria',
        'minimo_creditos',
        'minimo_asignaturas',
        'total_creditos',
        'curp_responsable',
        'clave_matricula',
        'clave_matricula_consecutivo',
        'vigente',
    ];

    protected function casts(): array
    {
        return [
            'fecha_rvoe' => 'date',
            'vigente' => 'boolean',
            'minimo_creditos' => 'float',
            'total_creditos' => 'float',
        ];
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class);
    }

    public function autorizacionReconocimiento(): BelongsTo
    {
        return $this->belongsTo(AutorizacionReconocimiento::class);
    }

    public function tipoPeriodo(): BelongsTo
    {
        return $this->belongsTo(TipoPeriodo::class);
    }

    public function planMaterias(): HasMany
    {
        return $this->hasMany(PlanMateria::class, 'plan_id');
    }
}
