<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * comprobantes_pago (TENANT) — una transferencia que alguien dice haber hecho.
 *
 * No es un pago: es una imagen esperando que una persona la valide. El `pago`
 * nace al aprobarla.
 */
class ComprobantePago extends Model
{
    use TieneAuditoria;

    public const PENDIENTE = 'pendiente';

    public const APROBADO = 'aprobado';

    public const RECHAZADO = 'rechazado';

    protected $table = 'comprobantes_pago';

    protected $attributes = [
        'estado' => self::PENDIENTE,
    ];

    protected $fillable = [
        'matricula_oferta_id',
        'aspirante_id',
        'cuenta_bancaria_id',
        'monto',
        'fecha_transferencia',
        'referencia',
        'archivo',
        'adeudo_ids',
        'estado',
        'motivo_rechazo',
        'revisado_por',
        'revisado_en',
        'pago_id',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_transferencia' => 'date',
            'adeudo_ids' => 'array',
            'revisado_en' => 'datetime',
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

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revisado_por');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    /** De quién es este comprobante. */
    public function titular(): MatriculaOferta|Aspirante|null
    {
        return $this->matriculaOferta ?? $this->aspirante;
    }

    /**
     * ¿Ya lo revisó alguien?
     *
     * Lo pregunta la revisión antes de tocar nada: dos personas mirando la cola
     * a la vez podrían aprobar el mismo comprobante dos veces, y eso serían dos
     * pagos por un solo depósito.
     */
    public function estaResuelto(): bool
    {
        return $this->estado !== self::PENDIENTE;
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', self::PENDIENTE);
    }
}
