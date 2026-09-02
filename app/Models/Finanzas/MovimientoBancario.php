<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * movimientos_bancarios (TENANT) — un renglón del estado de cuenta.
 *
 * ── Es INMUTABLE ───────────────────────────────────────────────────────────
 * Es lo que dijo el banco. Si se pudiera corregir, la conciliación cuadraría
 * editando la evidencia. Lo que sí se le anota son cosas NUESTRAS: con qué
 * casó (`conciliacion_partidas`) y, cuando no es un cobro, qué es
 * (`clasificacion`).
 *
 * ── El monto va CON SIGNO ──────────────────────────────────────────────────
 * Positivo lo que entró, negativo lo que salió. Dos columnas cargo/abono son
 * la forma del ARCHIVO, no la del hecho; con una sola, la suma de la columna
 * es el movimiento neto del periodo y el cuadre del saldo es una resta.
 */
class MovimientoBancario extends Model
{
    use TieneAuditoria;

    /** Lo que no es un cobro nuestro, y por eso no tiene con qué casar. */
    public const COMISION = 'comision';

    public const INTERESES = 'intereses';

    /** Entre cuentas propias de la escuela: no es ingreso ni egreso real. */
    public const TRASPASO = 'traspaso';

    public const DEVOLUCION = 'devolucion';

    public const OTRO = 'otro';

    /** @return array<string, string> */
    public static function clasificaciones(): array
    {
        return [
            self::COMISION => 'Comisión del banco',
            self::INTERESES => 'Intereses',
            self::TRASPASO => 'Traspaso entre cuentas propias',
            self::DEVOLUCION => 'Devolución',
            self::OTRO => 'Otro (con nota)',
        ];
    }

    protected $table = 'movimientos_bancarios';

    protected $fillable = [
        'estado_cuenta_id',
        'cuenta_bancaria_id',
        'fecha',
        'descripcion',
        'referencia',
        'monto',
        'huella',
        'clasificacion',
        'nota',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }

    public function estadoCuenta(): BelongsTo
    {
        return $this->belongsTo(EstadoCuentaBancaria::class, 'estado_cuenta_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(ConciliacionPartida::class, 'movimiento_bancario_id');
    }

    public function esEntrada(): bool
    {
        return (float) $this->monto > 0;
    }

    /** Lo ya casado con movimientos del sistema. */
    public function conciliado(): float
    {
        return round((float) $this->partidas()->sum('monto_aplicado'), 2);
    }

    /**
     * Lo que queda por explicar de este renglón.
     *
     * No es «lo conciliado contra el monto» a secas: una liquidación de
     * pasarela llega NETA de comisión, así que doce pagos por 12,000 casan
     * contra un renglón de 11,700 y sobran 300 que son la comisión. Esa
     * diferencia se declara con `clasificacion`, no se ignora — si no,
     * «conciliado» acabaría significando «por ahí anda».
     */
    public function pendiente(): float
    {
        return round((float) $this->monto - $this->conciliado(), 2);
    }

    public function estaResuelto(): bool
    {
        return $this->clasificacion !== null || abs($this->pendiente()) < 0.005;
    }

    /** Los renglones que todavía no explica nadie. */
    public function scopeSinResolver(Builder $consulta): Builder
    {
        return $consulta
            ->whereNull('clasificacion')
            ->whereRaw(
                'ABS(monto - COALESCE((
                    SELECT SUM(p.monto_aplicado) FROM conciliacion_partidas p
                    WHERE p.movimiento_bancario_id = movimientos_bancarios.id
                      AND p.deleted_at IS NULL
                ), 0)) >= 0.005'
            );
    }

    public function scopeEntradas(Builder $consulta): Builder
    {
        return $consulta->where('monto', '>', 0);
    }
}
