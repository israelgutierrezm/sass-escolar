<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * sesiones_caja (TENANT) — un turno de caja, y su corte al cerrarlo.
 *
 * ── El estado, y por qué son tres y no dos ─────────────────────────────────
 * `abierta` → `cerrada`, salvo cuando el arqueo no cuadra: entonces pasa por
 * `por_autorizar`. Hace falta el estado intermedio porque el cajero TIENE que
 * poder terminar su turno —no se le puede dejar la caja abierta hasta que
 * aparezca un supervisor—, y a la vez la diferencia no puede darse por buena
 * sola. Con dos estados habría que elegir entre esas dos cosas.
 */
class SesionCaja extends Model
{
    use TieneAuditoria;

    protected $table = 'sesiones_caja';

    /** El turno está corriendo: se pueden registrar cobros. */
    public const ABIERTA = 'abierta';

    /** Se cerró con una diferencia que alguien tiene que explicar. */
    public const POR_AUTORIZAR = 'por_autorizar';

    /** Cerrada y cuadrada, o con su diferencia ya autorizada. */
    public const CERRADA = 'cerrada';

    protected $attributes = ['estatus' => self::ABIERTA];

    protected $fillable = [
        'caja_id',
        'usuario_id',
        'abierta_en',
        'fondo_inicial',
        'cerrada_en',
        'cerrada_por_usuario_id',
        'efectivo_esperado',
        'efectivo_contado',
        'diferencia',
        'estatus',
        'motivo_diferencia',
        'notas',
        'autorizada_por_usuario_id',
        'autorizada_en',
        'deposito_caja_id',
    ];

    protected function casts(): array
    {
        return [
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
            'autorizada_en' => 'datetime',
            'fondo_inicial' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'efectivo_contado' => 'decimal:2',
            'diferencia' => 'decimal:2',
        ];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizada_por_usuario_id');
    }

    /** El depósito en el que se llevó su efectivo al banco, si ya se llevó. */
    public function deposito(): BelongsTo
    {
        return $this->belongsTo(DepositoCaja::class, 'deposito_caja_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'sesion_caja_id');
    }

    public function estaAbierta(): bool
    {
        return $this->cerrada_en === null;
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->whereNull('cerrada_en');
    }

    public function scopePorAutorizar(Builder $query): Builder
    {
        return $query->where('estatus', self::POR_AUTORIZAR);
    }

    /**
     * Sobrante o faltante, dicho en palabras.
     *
     * El signo solo no se lee: «-150» no dice si a la escuela le falta dinero o
     * le sobra, y son dos conversaciones muy distintas.
     */
    public function sentidoDeLaDiferencia(): ?string
    {
        if ($this->diferencia === null || (float) $this->diferencia === 0.0) {
            return null;
        }

        return (float) $this->diferencia > 0 ? 'sobrante' : 'faltante';
    }
}
