<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * recargos_descuentos (TENANT) — lo que modifica el monto de un adeudo.
 *
 * Un solo catálogo para las tres cosas (recargo por mora, descuento por pronto
 * pago, beca) porque las tres se calculan igual: un porcentaje o un monto fijo,
 * con tope opcional. Lo que cambia es cuándo se aplican, y eso lo dice `tipo`.
 */
class RecargoDescuento extends Model
{
    use TieneAuditoria;

    public const TIPO_RECARGO = 'recargo';

    public const TIPO_DESCUENTO = 'descuento';

    public const TIPO_BECA = 'beca';

    public const MODO_PORCENTAJE = 'porcentaje';

    public const MODO_MONTO_FIJO = 'monto_fijo';

    protected $table = 'recargos_descuentos';

    protected $fillable = [
        'tipo',
        'nombre',
        'modo',
        'valor',
        'dias_gracia',
        'tope_monto',
        'requiere_beca',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:4',
            'tope_monto' => 'decimal:2',
            'requiere_beca' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /**
     * Cuánto modifica a un monto dado, respetando el tope. Devuelve siempre un
     * valor positivo: el signo lo pone quien lo aplica, según sea recargo o
     * descuento.
     */
    public function calcularSobre(float $monto): float
    {
        $bruto = $this->modo === self::MODO_PORCENTAJE
            ? $monto * (float) $this->valor / 100
            : (float) $this->valor;

        if ($this->tope_monto !== null) {
            $bruto = min($bruto, (float) $this->tope_monto);
        }

        return round($bruto, 2);
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeDeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }
}
