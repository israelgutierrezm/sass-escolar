<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Enums\EstadoLoteTitulacion;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * lotes_titulacion (TENANT) — un bloque de egresados a titular juntos. Espejo del
 * lote de certificación, con una diferencia clave: la ETAPA (pruebas/producción)
 * con que se creó, que decide a qué endpoint del web service de la SEP se envía.
 *
 * Se arma en `borrador`, se cierra («en espera de firma»), lo firma el responsable
 * de titulación (cada renglón produce su XML sellado) y finalmente se envía al WS.
 */
class LoteTitulacion extends Model
{
    use TieneAuditoria;

    protected $table = 'lotes_titulacion';

    protected $fillable = [
        'folio',
        'nombre',
        'etapa',
        'estado',
        'responsable_id',
        'certificado_responsable_id',
        'cerrado_en',
        'firmado_en',
        'enviado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoLoteTitulacion::class,
            'cerrado_en' => 'datetime',
            'firmado_en' => 'datetime',
            'enviado_en' => 'datetime',
        ];
    }

    public function titulaciones(): HasMany
    {
        return $this->hasMany(Titulacion::class, 'lote_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Responsable::class);
    }

    public function certificado(): BelongsTo
    {
        return $this->belongsTo(CertificadoResponsable::class, 'certificado_responsable_id');
    }

    /**
     * ¿La etapa del lote coincide con la etapa activa del WS? Es la salvaguarda
     * central: un lote de producción no debe enviarse al endpoint de pruebas ni
     * viceversa.
     */
    public function etapaCoincideConActiva(): bool
    {
        return $this->etapa === TitulacionWsConfig::actual()->etapa_activa;
    }

    /** @param  Builder<LoteTitulacion>  $query */
    public function scopeAbiertos($query)
    {
        return $query->where('estado', EstadoLoteTitulacion::Borrador->value);
    }
}
