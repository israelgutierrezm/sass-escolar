<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * planes_cobro (TENANT) — el esquema de cobro de un ciclo.
 *
 * El alcance NO es polimórfico: un plan vive en un CICLO y aplica a los CAMPUS
 * y CARRERAS que se le marcan (las que realmente se ofertan en esos campus). Se
 * cambió el modelo anterior (carrera/plan/oferta/global) porque la pregunta real
 * de la escuela es "¿qué cobro este ciclo, en qué campus y a qué carreras?".
 *
 * Los cargos NO nacen del plan por sí solos: nacen cuando el plan se le vincula
 * a un alumno (`plan_cobro_alumno`), que es lo que dispara la generación masiva.
 */
class PlanCobro extends Model
{
    use TieneAuditoria;

    /** La mora empieza el mismo día marcado. */
    public const LIMITE_EXACTA = 'exacta';

    /** La mora empieza al día siguiente del marcado. */
    public const LIMITE_DIA_SIGUIENTE = 'dia_siguiente';

    protected $table = 'planes_cobro';

    protected $fillable = [
        'nombre',
        'ciclo_id',
        'moneda',
        'tiene_fecha_limite',
        'fecha_limite_modo',
        'aplica_recargos',
        'afecta_estatus_deudor',
        'vigente_desde',
        'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'tiene_fecha_limite' => 'boolean',
            'aplica_recargos' => 'boolean',
            'afecta_estatus_deudor' => 'boolean',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    public function campus(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'plan_cobro_campus', 'plan_cobro_id', 'campus_id');
    }

    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'plan_cobro_carrera', 'plan_cobro_id', 'carrera_id')
            ->withPivot('nivel_estudios_id');
    }

    /** Las líneas fechadas que este plan cobra. */
    public function conceptos(): HasMany
    {
        return $this->hasMany(ConceptoPlan::class, 'plan_cobro_id')->orderBy('orden')->orderBy('fecha_limite');
    }

    /** Reglas de recargo: la del plan (concepto_plan_id NULL) y los overrides. */
    public function reglasRecargo(): HasMany
    {
        return $this->hasMany(ReglaRecargo::class, 'plan_cobro_id');
    }

    /** Alumnos con este plan vinculado. */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(PlanCobroAlumno::class, 'plan_cobro_id');
    }

    public function alumnos(): BelongsToMany
    {
        return $this->belongsToMany(MatriculaOferta::class, 'plan_cobro_alumno', 'plan_cobro_id', 'matricula_oferta_id')
            ->withPivot(['estatus', 'asignado_en']);
    }

    /** La regla de recargo por defecto del plan (la que no es override). */
    public function reglaRecargoBase(): ?ReglaRecargo
    {
        return $this->reglasRecargo()->whereNull('concepto_plan_id')->where('activo', true)->first();
    }

    /** Un plan sin fecha de fin sigue vigente: la ausencia es "hasta nuevo aviso". */
    public function scopeVigentes(Builder $query, ?string $fecha = null): Builder
    {
        $fecha ??= now()->toDateString();

        return $query->whereDate('vigente_desde', '<=', $fecha)
            ->where(fn (Builder $q) => $q
                ->whereNull('vigente_hasta')
                ->orWhereDate('vigente_hasta', '>=', $fecha));
    }
}
