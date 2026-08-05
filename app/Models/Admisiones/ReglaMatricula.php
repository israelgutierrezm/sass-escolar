<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Carrera;
use App\Models\Academico\PlanEstudio;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * reglas_matricula (TENANT-CONFIG) — formato de matrícula de la escuela.
 *
 * Cada escuela arma su matrícula distinto y ninguna se parece a la de al lado:
 *   ClaveNivel+ClaveCarrera+ClavePlan+Año+consecutivo del año
 *   Año+ClaveCarrera+consecutivo histórico de la carrera
 *   ClaveCarrera+Año+consecutivo del campus por año
 * Por eso la regla es DATO y no código, y se configura en Admisiones.
 */
class ReglaMatricula extends Model
{
    use TieneAuditoria;

    /** Ámbitos donde puede definirse una regla, del más específico al más general. */
    public const AMBITOS = ['plan', 'carrera', 'global'];

    /**
     * Sobre qué se cuenta. NULL —«general»— es un solo contador para toda la
     * escuela; el resto abre un contador por cada campus, nivel, carrera o plan.
     */
    public const CONSECUTIVO_POR = ['campus', 'nivel', 'carrera', 'plan'];

    /**
     * Los tokens que la plantilla puede usar, con lo que significan.
     *
     * Viven aquí y no en el generador porque la PANTALLA los enseña: quien
     * configura la regla no tiene por qué adivinarlos.
     *
     * @var array<string, string>
     */
    public const TOKENS = [
        '{AAAA}' => 'Año en cuatro dígitos (2026)',
        '{AA}' => 'Año en dos dígitos (26)',
        '{NIVEL}' => 'Clave del nivel de estudios (LIC, MAE…)',
        '{CARRERA}' => 'Clave de la carrera',
        '{PLAN}' => 'Clave del plan de estudios',
        '{CAMPUS}' => 'Clave del campus',
        '{####}' => 'Consecutivo. Tantos dígitos como «#» pongas: {####} → 0007',
    ];

    protected $table = 'reglas_matricula';

    protected $fillable = [
        'nombre',
        'ambito',
        'ambito_id',
        'plantilla',
        'consecutivo_por',
        'consecutivo_anual',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'consecutivo_anual' => 'boolean',
        ];
    }

    /**
     * A qué se aplica, dicho en palabras.
     *
     * `ambito_id` apunta a una carrera o a un plan según `ambito`, así que no
     * hay una relación de Eloquent que sirva para los dos: se resuelve aquí.
     */
    public function alcance(): string
    {
        return match ($this->ambito) {
            'plan' => 'Plan: '.(PlanEstudio::find($this->ambito_id)?->nombre ?? '—'),
            'carrera' => 'Carrera: '.(Carrera::find($this->ambito_id)?->nombre ?? '—'),
            default => 'Toda la escuela',
        };
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'ambito_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'ambito_id');
    }
}
