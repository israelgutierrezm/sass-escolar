<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * solicitudes_factura (TENANT) — un alumno o su familia pide su factura.
 *
 * No es una factura: es la constancia de que quiere una, con los datos con los
 * que la quiere. El `factura` nace cuando una persona con permiso la emite,
 * reusando `EmisorFactura`. Misma forma que `ComprobantePago` con el pago.
 */
class SolicitudFactura extends Model
{
    use TieneAuditoria;

    public const PENDIENTE = 'pendiente';

    public const EMITIDA = 'emitida';

    public const RECHAZADA = 'rechazada';

    protected $table = 'solicitudes_factura';

    protected $attributes = [
        'estado' => self::PENDIENTE,
    ];

    protected $fillable = [
        'matricula_oferta_id',
        'solicitada_por',
        'pago_ids',
        'receptor_rfc',
        'receptor_razon_social',
        'receptor_uso_cfdi',
        'receptor_regimen_fiscal',
        'receptor_cp',
        'receptor_correo',
        'estado',
        'motivo_rechazo',
        'revisado_por',
        'revisado_en',
        'factura_id',
    ];

    protected function casts(): array
    {
        return [
            'pago_ids' => 'array',
            'revisado_en' => 'datetime',
        ];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitada_por');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revisado_por');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'factura_id');
    }

    /**
     * ¿Ya la atendió alguien? Lo pregunta la revisión con la fila bloqueada:
     * dos personas en la bandeja no pueden emitir dos facturas por la misma
     * solicitud.
     */
    public function estaResuelta(): bool
    {
        return $this->estado !== self::PENDIENTE;
    }

    /** El receptor congelado, en la forma que espera `EmisorFactura::emitir`. */
    public function receptor(): array
    {
        return [
            'rfc' => $this->receptor_rfc,
            'razon_social' => $this->receptor_razon_social,
            'uso_cfdi' => $this->receptor_uso_cfdi,
            'regimen_fiscal' => $this->receptor_regimen_fiscal,
            'cp' => $this->receptor_cp,
        ];
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', self::PENDIENTE);
    }
}
