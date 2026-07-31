<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * titulo_servicio_social (TENANT) — datos del servicio social de una
 * carrera-alumno para el título. Alimenta los atributos de servicio social del
 * nodo Expedicion del XML del título electrónico.
 */
class TituloServicioSocial extends Model
{
    use TieneAuditoria;

    protected $table = 'titulo_servicio_social';

    protected $fillable = [
        'matricula_oferta_id',
        'cumplio_servicio_social',
        'fundamento_legal_ss_id',
    ];

    protected function casts(): array
    {
        return ['cumplio_servicio_social' => 'boolean'];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function fundamento(): BelongsTo
    {
        return $this->belongsTo(FundamentoLegalServicioSocial::class, 'fundamento_legal_ss_id');
    }
}
