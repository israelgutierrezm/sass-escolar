<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * adeudos (TENANT) — lo que se debe.
 *
 * Su titular es una `matricula_oferta` O un `aspirante`, exactamente uno de los
 * dos: el aspirante paga su ficha e inscripción antes de que exista matrícula
 * alguna. Al convertirse en alumno, `ReligadorFinanzas` mueve sus adeudos a la
 * matrícula nueva dentro de la misma transacción.
 *
 * En la práctica es append-only: cancelar y condonar son cambios de `estatus`
 * que dejan el renglón, no borrados. El estado de cuenta de una escuela tiene
 * que poder explicarse años después.
 */
class Adeudo extends Model
{
    use TieneAuditoria;

    public const ESTATUS_PENDIENTE = 'pendiente';

    public const ESTATUS_PARCIAL = 'parcial';

    public const ESTATUS_PAGADO = 'pagado';

    public const ESTATUS_CANCELADO = 'cancelado';

    public const ESTATUS_CONDONADO = 'condonado';

    /**
     * Lo cubre un convenio de pago: se cobra por sus parcialidades, no por
     * aquí.
     *
     * No es «cancelado» —el cargo existió y sigue explicando qué se debía y
     * desde cuándo— ni «pagado», porque nadie lo ha pagado todavía. Todo el
     * motor de cartera filtra por la lista blanca `[pendiente, parcial]`, así
     * que este valor no se cuenta dos veces, no acumula mora y no se puede
     * pagar por la puerta de atrás.
     */
    public const ESTATUS_EN_CONVENIO = 'en_convenio';

    protected $table = 'adeudos';

    /**
     * Los mismos valores por defecto que la migración, para que el modelo recién
     * creado diga lo que la base va a guardar. Sin esto, un `Adeudo::create()`
     * devuelve `estatus` en NULL hasta que alguien lo relee, y todo lo que
     * pregunta por el estatus —`porCobrar`, `estaVencido`— se equivoca en
     * silencio sobre un renglón que sí existe bien en la base.
     */
    protected $attributes = [
        'estatus' => self::ESTATUS_PENDIENTE,
        'monto_recargos' => 0,
        'monto_descuentos' => 0,
    ];

    protected $fillable = [
        'matricula_oferta_id',
        'aspirante_id',
        'concepto_id',
        'concepto_plan_id',
        'ciclo_id',
        'periodo_etiqueta',
        'monto',
        'monto_recargos',
        'monto_descuentos',
        'monto_total',
        'fecha_generacion',
        'fecha_vencimiento',
        'estatus',
        // Puesto sólo en las PARCIALIDADES: este cargo lo creó ese convenio.
        // Los cargos que el convenio cubre se identifican por el pivote.
        'convenio_id',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'monto_recargos' => 'decimal:2',
            'monto_descuentos' => 'decimal:2',
            'monto_total' => 'decimal:2',
            'fecha_generacion' => 'date',
            'fecha_vencimiento' => 'date',
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

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }

    /** La línea del plan de la que salió este cargo (null si se capturó a mano). */
    public function conceptoPlan(): BelongsTo
    {
        return $this->belongsTo(ConceptoPlan::class, 'concepto_plan_id');
    }

    /** Desglose de becas, descuentos y recargos que movieron el monto. */
    public function ajustes(): HasMany
    {
        return $this->hasMany(AdeudoAjuste::class, 'adeudo_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    /** Los pagos que lo cubrieron, con cuánto aportó cada uno. */
    public function pagos(): BelongsToMany
    {
        return $this->belongsToMany(Pago::class, 'pago_adeudo', 'adeudo_id', 'pago_id')
            ->using(PagoAdeudo::class)
            ->withPivot('monto_aplicado')
            // El pivote tiene borrado lógico y la relación no lo filtra sola:
            // sin esto, una aplicación retirada seguiría sumando al saldo.
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /**
     * Exactamente un titular: o matrícula, o aspirante. La base lo impone con
     * un CHECK en MySQL; esto es lo que permite explicarlo antes de reventar.
     */
    public function titularValido(): bool
    {
        return ($this->matricula_oferta_id !== null) !== ($this->aspirante_id !== null);
    }

    /** Cuánto se le ha aplicado ya, contando solo pagos que de verdad entraron. */
    public function montoAplicado(): float
    {
        return (float) $this->pagos()
            ->where('pagos.estatus', Pago::ESTATUS_COMPLETADO)
            ->sum('pago_adeudo.monto_aplicado');
    }

    public function saldo(): float
    {
        return round((float) $this->monto_total - $this->montoAplicado(), 2);
    }

    /** Vencido es no haberlo cubierto a tiempo; cancelado o condonado ya no lo está. */
    public function estaVencido(?string $fecha = null): bool
    {
        if (! in_array($this->estatus, [self::ESTATUS_PENDIENTE, self::ESTATUS_PARCIAL], true)) {
            return false;
        }

        return $this->fecha_vencimiento?->lt($fecha ?? now()->startOfDay()) ?? false;
    }

    /** Lo que todavía pesa en el estado de cuenta. */
    public function scopePorCobrar(Builder $query): Builder
    {
        return $query->whereIn('estatus', [self::ESTATUS_PENDIENTE, self::ESTATUS_PARCIAL]);
    }

    /** El convenio del que este cargo es parcialidad, si lo es. */
    public function convenio(): BelongsTo
    {
        return $this->belongsTo(ConvenioPago::class, 'convenio_id');
    }

    public function scopeDeAspirante(Builder $query, int $aspiranteId): Builder
    {
        return $query->where('aspirante_id', $aspiranteId);
    }

    public function scopeDeMatricula(Builder $query, int $matriculaOfertaId): Builder
    {
        return $query->where('matricula_oferta_id', $matriculaOfertaId);
    }
}
