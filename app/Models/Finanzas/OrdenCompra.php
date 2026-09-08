<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ordenes_compra (TENANT) — un compromiso de compra a un proveedor.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Se arma en borrador, se autoriza (y ahí es un compromiso presupuestal), y al
 * RECIBIRSE genera la cuenta por pagar. No crea egreso: el egreso nace al pagar
 * la CxP. Lo recibido se DERIVA de las CxP que generó, no de un contador.
 */
class OrdenCompra extends Model
{
    use TieneAuditoria;

    protected $table = 'ordenes_compra';

    public const BORRADOR = 'borrador';

    public const AUTORIZADA = 'autorizada';

    public const RECIBIDA = 'recibida';

    public const CERRADA = 'cerrada';

    public const CANCELADA = 'cancelada';

    public const ETIQUETAS = [
        self::BORRADOR => 'Borrador',
        self::AUTORIZADA => 'Autorizada',
        self::RECIBIDA => 'Recibida (parcial)',
        self::CERRADA => 'Cerrada',
        self::CANCELADA => 'Cancelada',
    ];

    protected $attributes = ['estado' => self::BORRADOR];

    protected $fillable = [
        'proveedor_id',
        'centro_costo_id',
        'partida_id',
        'ciclo_id',
        'fecha',
        'estado',
        'autorizada_por',
        'autorizada_en',
        'referencia',
        'notas',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'autorizada_en' => 'datetime'];
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

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'autorizada_por');
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(OrdenCompraConcepto::class, 'orden_compra_id');
    }

    /** Las cuentas por pagar que esta OC ha generado al recibirse. */
    public function cuentasPorPagar(): HasMany
    {
        return $this->hasMany(CuentaPorPagar::class, 'orden_compra_id');
    }

    /** El total de la OC: la suma de sus conceptos. */
    public function total(): float
    {
        return round((float) $this->conceptos->sum(fn (OrdenCompraConcepto $c) => $c->importe()), 2);
    }

    /**
     * Lo ya recibido: la suma de las CxP que generó (sin las canceladas). Una CxP
     * cancelada deshace su recepción, así que no cuenta.
     */
    public function montoRecibido(): float
    {
        return round((float) $this->cuentasPorPagar()->where('estado', '!=', CuentaPorPagar::CANCELADA)->sum('monto'), 2);
    }

    public function porRecibir(): float
    {
        return round($this->total() - $this->montoRecibido(), 2);
    }

    public function esBorrador(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    public function estaAutorizada(): bool
    {
        return $this->estado === self::AUTORIZADA;
    }

    /** ¿Puede recibir todavía? Autorizada o recibida parcial, con saldo. */
    public function admiteRecepcion(): bool
    {
        return in_array($this->estado, [self::AUTORIZADA, self::RECIBIDA], true) && $this->porRecibir() > 0.005;
    }

    /** @param  Builder<self>  $q */
    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->whereIn('estado', [self::BORRADOR, self::AUTORIZADA, self::RECIBIDA]);
    }
}
