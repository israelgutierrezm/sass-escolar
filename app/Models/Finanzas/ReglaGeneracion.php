<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * reglas_generacion (TENANT) — cada cuánto y cuánto se cobra de un concepto.
 *
 * Es el corazón de que el motor sea configurable: "semanal sin inscripción",
 * "mensual con inscripción" y "pago único de titulación" son FILAS, no ramas
 * del código. El servicio de generación (entrega 7.2) las recorre.
 */
class ReglaGeneracion extends Model
{
    use TieneAuditoria;

    public const PERIODICIDAD_UNICO = 'unico';

    public const PERIODICIDAD_SEMANAL = 'semanal';

    public const PERIODICIDAD_QUINCENAL = 'quincenal';

    public const PERIODICIDAD_MENSUAL = 'mensual';

    public const PERIODICIDAD_POR_CICLO = 'por_ciclo';

    public const PERIODICIDAD_POR_MATERIA = 'por_materia';

    protected $table = 'reglas_generacion';

    protected $fillable = [
        'plan_cobro_id',
        'concepto_id',
        'periodicidad',
        'monto_base',
        'dia_generacion',
        'dia_limite',
        'obligatorio',
        'num_parcialidades',
        'prorratea',
        'concepto_prerequisito_id',
    ];

    protected function casts(): array
    {
        return [
            'monto_base' => 'decimal:2',
            'obligatorio' => 'boolean',
            'prorratea' => 'boolean',
        ];
    }

    public function planCobro(): BelongsTo
    {
        return $this->belongsTo(PlanCobro::class, 'plan_cobro_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }

    /** El concepto que hay que traer pagado para que este se genere. */
    public function conceptoPrerequisito(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_prerequisito_id');
    }

    public function adeudos(): HasMany
    {
        return $this->hasMany(Adeudo::class, 'regla_id');
    }
}
