<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * intenciones_cobro (TENANT) — un intento de pago en línea.
 *
 * No es un pago: es la promesa que se le hizo a la pasarela y el hilo que
 * permite reconocer su aviso cuando vuelve. El `pago` nace después, y sólo si
 * hubo dinero.
 */
class IntencionCobro extends Model
{
    use TieneAuditoria;

    public const PENDIENTE = 'pendiente';

    public const PAGADA = 'pagada';

    public const FALLIDA = 'fallida';

    public const CANCELADA = 'cancelada';

    public const EXPIRADA = 'expirada';

    protected $table = 'intenciones_cobro';

    protected $attributes = [
        'estado' => self::PENDIENTE,
    ];

    protected $fillable = [
        'matricula_oferta_id',
        'aspirante_id',
        'pasarela',
        'ambiente',
        'monto',
        'adeudo_ids',
        'referencia_externa',
        'estado',
        'pago_id',
        'respuesta',
        'resuelta_en',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'adeudo_ids' => 'array',
            'respuesta' => 'array',
            'resuelta_en' => 'datetime',
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

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    /** De quién es este cobro. */
    public function titular(): MatriculaOferta|Aspirante|null
    {
        return $this->matriculaOferta ?? $this->aspirante;
    }

    /**
     * ¿Ya se resolvió?
     *
     * Lo pregunta la conciliación antes de tocar nada: un aviso que llega dos
     * veces —y llegan, los webhooks se reintentan por diseño— no debe volver a
     * registrar el dinero.
     */
    public function estaResuelta(): bool
    {
        return $this->estado !== self::PENDIENTE;
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', self::PENDIENTE);
    }
}
