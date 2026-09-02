<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * pagos (TENANT) — lo que entró.
 *
 * Mismo titular dual que `adeudos` y por la misma razón: el aspirante paga
 * antes de tener matrícula.
 *
 * Un pago NO es un adeudo liquidado. Se relaciona con los adeudos que cubrió a
 * través de `pago_adeudo` con `monto_aplicado`, porque un depósito puede
 * liquidar tres mensualidades y un abono puede cubrir media.
 */
class Pago extends Model
{
    use TieneAuditoria;

    public const ESTATUS_PENDIENTE = 'pendiente';

    public const ESTATUS_COMPLETADO = 'completado';

    public const ESTATUS_FALLIDO = 'fallido';

    public const ESTATUS_REEMBOLSADO = 'reembolsado';

    protected $table = 'pagos';

    /** Mismo default que la migración; ver la nota en `Adeudo`. */
    protected $attributes = [
        'estatus' => self::ESTATUS_PENDIENTE,
    ];

    protected $fillable = [
        'matricula_oferta_id',
        'aspirante_id',
        'metodo_pago_id',
        // El turno de caja en el que se recibió, cuando lo hubo. Nulo en
        // lo que entra sin persona detrás —una pasarela, un comando—:
        // ese dinero no pasa por ningún cajón.
        'sesion_caja_id',
        'monto',
        'referencia',
        'pasarela',
        'pasarela_txn_id',
        'estatus',
        'momento',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'momento' => 'datetime',
        ];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function aspirante(): BelongsTo
    {
        return $this->belongsTo(Aspirante::class, 'aspirante_id');
    }

    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'metodo_pago_id');
    }

    /** Qué adeudos cubrió y con cuánto de este pago. */
    public function adeudos(): BelongsToMany
    {
        return $this->belongsToMany(Adeudo::class, 'pago_adeudo', 'pago_id', 'adeudo_id')
            ->using(PagoAdeudo::class)
            ->withPivot('monto_aplicado')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function titularValido(): bool
    {
        return ($this->matricula_oferta_id !== null) !== ($this->aspirante_id !== null);
    }

    /**
     * Quién pagó: la matrícula o el aspirante, el que sea de los dos.
     *
     * El titular es DUAL porque el aspirante paga su ficha antes de tener
     * matrícula, y exactamente uno de los dos está lleno —lo sostiene un CHECK
     * en la base y lo comprueba `titularValido()`—. Quien sólo quiere el nombre
     * de la persona no tiene por qué saber cuál de los dos caminos tocó.
     *
     * `ComprobantePago` ya lo tenía; que aquí faltara era una asimetría entre
     * dos tablas con la misma regla, y se pagó con un error al pedirlo.
     */
    public function titular(): MatriculaOferta|Aspirante|null
    {
        return $this->matriculaOferta ?? $this->aspirante;
    }

    /** Dinero de verdad: lo pendiente todavía puede no llegar. */
    public function estaCobrado(): bool
    {
        return $this->estatus === self::ESTATUS_COMPLETADO;
    }

    /** Lo que aún no se ha repartido entre adeudos (un anticipo, un excedente). */
    public function montoSinAplicar(): float
    {
        return round((float) $this->monto - (float) $this->adeudos()->sum('pago_adeudo.monto_aplicado'), 2);
    }

    public function scopeCobrados(Builder $query): Builder
    {
        return $query->where('estatus', self::ESTATUS_COMPLETADO);
    }
}
