<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Enums\EstadoLoteCertificacion;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * lotes_certificacion (TENANT) — un bloque de alumnos a certificar juntos. Se
 * arma en `borrador`, se cierra («en espera de firma») y lo firma el responsable
 * de certificación: cada renglón (Certificacion) produce su XML sellado.
 */
class LoteCertificacion extends Model
{
    use TieneAuditoria;

    protected $table = 'lotes_certificacion';

    protected $fillable = [
        'folio',
        'nombre',
        'estado',
        'responsable_id',
        'certificado_responsable_id',
        'cerrado_en',
        'firmado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoLoteCertificacion::class,
            'cerrado_en' => 'datetime',
            'firmado_en' => 'datetime',
        ];
    }

    public function certificaciones(): HasMany
    {
        return $this->hasMany(Certificacion::class, 'lote_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Responsable::class);
    }

    public function certificado(): BelongsTo
    {
        return $this->belongsTo(CertificadoResponsable::class, 'certificado_responsable_id');
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<LoteCertificacion>  $query */
    public function scopeAbiertos($query)
    {
        return $query->where('estado', EstadoLoteCertificacion::Borrador->value);
    }
}
