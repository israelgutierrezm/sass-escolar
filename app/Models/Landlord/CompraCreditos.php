<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * emision_compras (LANDLORD) — una escuela dice haber pagado unos créditos.
 *
 * Mismo principio que el comprobante de transferencia de una colegiatura, un
 * nivel más arriba: no es un abono, es una imagen esperando que alguien la
 * valide. Los créditos se acreditan al aprobarla.
 *
 * Quien la revisa es un administrador de la ORGANIZACIÓN, no de la escuela: la
 * escuela es la que paga, y dejarle validar su propio pago sería regalarle
 * créditos.
 */
class CompraCreditos extends Model
{
    use CentralConnection;

    public const PENDIENTE = 'pendiente';

    public const APROBADA = 'aprobada';

    public const RECHAZADA = 'rechazada';

    protected $table = 'emision_compras';

    protected $attributes = [
        'estado' => self::PENDIENTE,
    ];

    protected $fillable = [
        'tenant_id',
        'creditos',
        'monto',
        'referencia',
        'comprobante',
        'estado',
        'motivo_rechazo',
        'revisado_por',
        'revisado_en',
    ];

    protected function casts(): array
    {
        return [
            'creditos' => 'integer',
            'monto' => 'decimal:2',
            'revisado_en' => 'datetime',
        ];
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'revisado_por');
    }

    /**
     * ¿Ya la revisó alguien?
     *
     * Se pregunta antes de acreditar: dos administradores mirando la cola a la
     * vez darían los créditos dos veces por un solo pago.
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
