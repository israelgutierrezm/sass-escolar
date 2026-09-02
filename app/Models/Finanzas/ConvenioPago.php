<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * convenios_pago (TENANT) — lo acordado con quien no puede pagar de golpe.
 *
 * ── Los cuatro estados, y por qué son cuatro ───────────────────────────────
 * `vigente` mientras se cumple. `cumplido` cuando ya se pagó todo — se
 * reconoce solo, no hace falta que nadie lo declare. `cancelado` es «me
 * equivoqué al capturarlo» y sólo cabe mientras no haya entrado un peso:
 * deshace, y los cargos originales vuelven. `incumplido` es otra cosa: ACELERA
 * lo que falta, no lo deshace.
 *
 * La diferencia importa. Al incumplir no se pueden devolver los cargos
 * originales, porque el convenio ya cobró parte de ellos y devolverlos
 * completos cobraría dos veces el mismo dinero; repartir lo abonado entre los
 * originales sería inventar un reparto. Lo que queda vivo son las
 * parcialidades no pagadas, vencidas de inmediato — que es además lo que dice
 * cualquier convenio real con su cláusula de vencimiento anticipado.
 */
class ConvenioPago extends Model
{
    use TieneAuditoria;

    public const VIGENTE = 'vigente';

    public const CUMPLIDO = 'cumplido';

    /** Se rompió: lo que falta se venció de golpe. */
    public const INCUMPLIDO = 'incumplido';

    /** Se capturó mal y no había cobrado nada. Los cargos originales vuelven. */
    public const CANCELADO = 'cancelado';

    protected $table = 'convenios_pago';

    protected $attributes = ['estatus' => self::VIGENTE];

    protected $fillable = [
        'matricula_oferta_id',
        'concepto_id',
        'motivo',
        'firmado_en',
        'monto_cubierto',
        'estatus',
        'autorizado_por',
        'cerrado_en',
        'motivo_cierre',
    ];

    protected function casts(): array
    {
        return [
            'firmado_en' => 'date',
            'monto_cubierto' => 'decimal:2',
            'cerrado_en' => 'datetime',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizado_por');
    }

    /** Los cargos que el convenio CUBRE (los viejos, ahora `en_convenio`). */
    public function cubiertos(): BelongsToMany
    {
        return $this->belongsToMany(Adeudo::class, 'convenio_adeudo', 'convenio_id', 'adeudo_id')
            ->withPivot('saldo_cubierto')
            ->withTimestamps();
    }

    /** Los cargos que el convenio CREÓ: sus parcialidades. */
    public function parcialidades(): HasMany
    {
        return $this->hasMany(Adeudo::class, 'convenio_id')->orderBy('fecha_vencimiento');
    }

    public function estaVigente(): bool
    {
        return $this->estatus === self::VIGENTE;
    }

    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->where('estatus', self::VIGENTE);
    }

    /** Lo que falta por cobrar de las parcialidades. */
    public function saldo(): float
    {
        return round(
            $this->parcialidades()
                ->porCobrar()
                ->get()
                ->sum(fn (Adeudo $a) => $a->saldo()),
            2
        );
    }

    /** ¿Hay alguna parcialidad vencida y sin pagar? */
    public function tieneAtraso(?string $hoy = null): bool
    {
        $hoy ??= now()->toDateString();

        return $this->parcialidades()
            ->porCobrar()
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->exists();
    }

    /** ¿Ya entró dinero? Es lo que decide si todavía se puede cancelar. */
    public function tieneAbonos(): bool
    {
        return $this->parcialidades()
            ->get()
            ->contains(fn (Adeudo $a) => $a->estatus === Adeudo::ESTATUS_PAGADO || $a->estatus === Adeudo::ESTATUS_PARCIAL);
    }
}
