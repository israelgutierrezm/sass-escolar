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
        'plantilla_evaluacion_id',
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

    /** Criterio de evaluación por defecto para las materias de este plan. */
    public function plantillaEvaluacion(): BelongsTo
    {
        return $this->belongsTo(PlantillaEvaluacion::class, 'plantilla_evaluacion_id');
    }

    public function autorizacionReconocimiento(): BelongsTo
    {
        return $this->belongsTo(AutorizacionReconocimiento::class);
    }

    public function tipoPeriodo(): BelongsTo
    {
        return $this->belongsTo(TipoPeriodo::class);
    }

    /**
     * Etiqueta singular con la que se numera la malla según el tipo de periodo
     * del plan: «Semestre», «Cuatrimestre», etc. Los tipos que son adjetivo
     * (MODULAR, ANUAL) se traducen a su sustantivo. Sin tipo cae a «Periodo».
     */
    public function unidadPeriodo(): string
    {
        $nombre = $this->tipoPeriodo?->nombre;

        if ($nombre === null || $nombre === '') {
            return 'Periodo';
        }

        $especiales = ['MODULAR' => 'Módulo', 'ANUAL' => 'Año'];

        return $especiales[mb_strtoupper($nombre)]
            ?? mb_strtoupper(mb_substr($nombre, 0, 1)).mb_strtolower(mb_substr($nombre, 1));
    }

    public function planMaterias(): HasMany
    {
        return $this->hasMany(PlanMateria::class, 'plan_id');
    }
}
