<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * reglas_recargo (TENANT) — cuánto se recarga un cargo vencido.
 *
 * Hay una regla base por plan (`concepto_plan_id` NULL) y, opcionalmente, un
 * override por concepto. Se modela así porque las escuelas difieren en el
 * comportamiento —unas cobran una penalización única al vencer, otras suman un
 * monto por cada mes de atraso— y eso debe ser DATO, no una rama del código.
 */
class ReglaRecargo extends Model
{
    use TieneAuditoria;

    public const MODO_MONTO_FIJO = 'monto_fijo';

    public const MODO_PORCENTAJE = 'porcentaje';

    /** Se aplica una sola vez al vencer. */
    public const FRECUENCIA_UNICA = 'unica';

    /** Se vuelve a aplicar por cada mes de atraso. */
    public const FRECUENCIA_MENSUAL = 'mensual_acumulativa';

    protected $table = 'reglas_recargo';

    protected $fillable = [
        'plan_cobro_id',
        'concepto_plan_id',
        'modo',
        'valor',
        'frecuencia',
        'dias_gracia',
        'tope_monto',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:4',
            'tope_monto' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanCobro::class, 'plan_cobro_id');
    }

    public function conceptoPlan(): BelongsTo
    {
        return $this->belongsTo(ConceptoPlan::class, 'concepto_plan_id');
    }

    /**
     * Recargo sobre `$base` para `$periodos` de atraso (1 = el primero).
     * El tope, si existe, acota el total acumulado.
     */
    public function calcular(float $base, int $periodos = 1): float
    {
        $veces = $this->frecuencia === self::FRECUENCIA_MENSUAL ? max(1, $periodos) : 1;

        $unitario = $this->modo === self::MODO_PORCENTAJE
            ? $base * (float) $this->valor
            : (float) $this->valor;

        $total = round($unitario * $veces, 2);

        if ($this->tope_monto !== null) {
            $total = min($total, (float) $this->tope_monto);
        }

        return $total;
    }
}
