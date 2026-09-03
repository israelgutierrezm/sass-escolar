<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * descuentos (TENANT) — lo contrario al recargo.
 *
 * A diferencia de la beca, un descuento no depende de QUIÉN es el alumno sino de
 * CUÁNDO o CÓMO paga: pagar antes del límite, o caer dentro de una campaña. Por
 * eso no se otorga a nadie; se evalúa al momento de calcular el cargo.
 */
class Descuento extends Model
{
    use TieneAuditoria;

    /** Paga N días antes del límite. */
    public const TIPO_PAGO_ANTICIPADO = 'pago_anticipado';

    /** Vigente en una ventana de fechas. */
    public const TIPO_CAMPANA = 'campana';

    /*
     * Hubo un tercer tipo, `manual`, y se RETIRÓ.
     *
     * La pantalla lo ofrecía y la validación lo aceptaba, pero
     * `CalculadorCargo` sólo lee estos dos: un descuento «manual» se creaba, se
     * guardaba, se veía en la lista y no descontaba NADA. Es la misma familia
     * que `ver-personas` y `crear-personas` — una opción que se elige creyendo
     * que hace algo.
     *
     * Lo que de verdad hacía falta —un descuento acotado a ciertas familias— lo
     * resuelven las becas y los convenios de descuento, que sí se aplican.
     */

    public const MODO_PORCENTAJE = 'porcentaje';

    public const MODO_MONTO_FIJO = 'monto_fijo';

    protected $table = 'descuentos';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'tipo',
        'modo',
        'valor',
        'tope_monto',
        'dias_anticipacion',
        'vigente_desde',
        'vigente_hasta',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:4',
            'tope_monto' => 'decimal:2',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function conceptos(): BelongsToMany
    {
        return $this->belongsToMany(ConceptoPago::class, 'descuento_concepto', 'descuento_id', 'concepto_id');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /** ¿Aplica a este concepto? Sin restricción, aplica a todos. */
    public function cubreConcepto(int $conceptoId): bool
    {
        $ids = $this->relationLoaded('conceptos')
            ? $this->conceptos->pluck('id')->all()
            : $this->conceptos()->pluck('conceptos_pago.id')->all();

        return $ids === [] || in_array($conceptoId, $ids, true);
    }

    /** ¿Está dentro de su ventana de campaña? Sin fechas, siempre. */
    public function vigenteEn(string $fecha): bool
    {
        if ($this->vigente_desde !== null && $this->vigente_desde->toDateString() > $fecha) {
            return false;
        }

        return $this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= $fecha;
    }

    public function descuentoSobre(float $base): float
    {
        $bruto = $this->modo === self::MODO_PORCENTAJE
            ? $base * (float) $this->valor
            : (float) $this->valor;

        if ($this->tope_monto !== null) {
            $bruto = min($bruto, (float) $this->tope_monto);
        }

        return round(min($bruto, $base), 2);
    }
}
