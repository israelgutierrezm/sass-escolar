<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * cuentas_por_pagar (TENANT) — una obligación de pago a un proveedor.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Es un COMPROMISO, todavía no un gasto: pagarla registra un `egreso` (ahí el
 * dinero «sale») y el saldo/estado se DERIVAN de esos egresos. La CxP nunca
 * cuenta como ejercido por sí sola.
 */
class CuentaPorPagar extends Model
{
    use TieneAuditoria;

    protected $table = 'cuentas_por_pagar';

    public const PENDIENTE = 'pendiente';

    public const PARCIAL = 'parcial';

    public const PAGADA = 'pagada';

    public const CANCELADA = 'cancelada';

    /** Estados en que todavía se debe dinero. */
    public const ABIERTAS = [self::PENDIENTE, self::PARCIAL];

    public const ETIQUETAS = [
        self::PENDIENTE => 'Pendiente',
        self::PARCIAL => 'Pago parcial',
        self::PAGADA => 'Pagada',
        self::CANCELADA => 'Cancelada',
    ];

    public const ORIGEN_CAPTURA = 'captura';

    public const ORIGEN_ORDEN_COMPRA = 'orden_compra';

    protected $attributes = ['estado' => self::PENDIENTE, 'origen' => self::ORIGEN_CAPTURA];

    protected $fillable = [
        'proveedor_id',
        'centro_costo_id',
        'partida_id',
        'ciclo_id',
        'concepto',
        'monto',
        'fecha',
        'vencimiento',
        'estado',
        'referencia',
        'comprobante_ruta',
        'comprobante_nombre',
        'origen',
        'origen_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'vencimiento' => 'date',
            'monto' => 'decimal:2',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function centro(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function partida(): BelongsTo
    {
        return $this->belongsTo(PartidaPresupuesto::class, 'partida_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    /**
     * Los pagos son los EGRESOS de esta CxP: no hay tabla de pagos aparte, el
     * egreso ES el pago (el dinero que salió). El enlace es `cuenta_por_pagar_id`
     * y no `origen_id`: el único de egresos (para la nómina) no dejaría convivir
     * las parcialidades si se reusara `origen_id`.
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Egreso::class, 'cuenta_por_pagar_id');
    }

    public function montoPagado(): float
    {
        return (float) $this->pagos()->sum('monto');
    }

    public function saldo(): float
    {
        return round((float) $this->monto - $this->montoPagado(), 2);
    }

    /** El estado que le corresponde a lo pagado. No lo escribe: lo calcula. */
    public function estadoSegunPagos(): string
    {
        if ($this->estado === self::CANCELADA) {
            return self::CANCELADA;
        }

        $pagado = $this->montoPagado();

        return match (true) {
            $pagado <= 0 => self::PENDIENTE,
            $pagado + 0.005 >= (float) $this->monto => self::PAGADA,
            default => self::PARCIAL,
        };
    }

    public function estaAbierta(): bool
    {
        return in_array($this->estado, self::ABIERTAS, true);
    }

    /** ¿Vencida y todavía con saldo? */
    public function estaVencida(): bool
    {
        return $this->estaAbierta() && $this->vencimiento !== null && $this->vencimiento->isPast();
    }

    /** @param  Builder<self>  $q */
    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->whereIn('estado', self::ABIERTAS);
    }
}
