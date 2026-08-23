<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * postulacion_bitacora (TENANT) — un movimiento de etapa.
 *
 * Existe para MEDIR, no para auditar: es lo que permite contestar «cuánto tarda
 * un egresado en colocarse» y «en qué etapa se atoran», que es el indicador que
 * piden las acreditadoras. Guardar sólo la etapa actual daría la foto y perdería
 * la película.
 */
class PostulacionBitacora extends Model
{
    use TieneAuditoria;

    protected $table = 'postulacion_bitacora';

    protected $fillable = [
        'postulacion_id',
        'etapa_origen_id',
        'etapa_destino_id',
        'movida_por',
        'nota',
        'momento',
    ];

    protected function casts(): array
    {
        return ['momento' => 'datetime'];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class, 'postulacion_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(EtapaPostulacion::class, 'etapa_origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(EtapaPostulacion::class, 'etapa_destino_id');
    }

    public function movidaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'movida_por');
    }
}
