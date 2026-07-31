<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * titulaciones (TENANT) — un egresado dentro de un lote de titulación. Mientras
 * el lote no se firma es `pendiente`; al firmar pasa a `titulado` con su XML de
 * título sellado y una foto (`datos_json`) de su expediente en ese momento. Al
 * enviarse al web service de la SEP se guarda el folio de proceso y la respuesta.
 */
class Titulacion extends Model
{
    use TieneAuditoria;

    protected $table = 'titulaciones';

    public const PENDIENTE = 'pendiente';
    public const TITULADO = 'titulado';
    public const ERROR = 'error';

    protected $fillable = [
        'lote_id',
        'matricula_oferta_id',
        'estado',
        'folio',
        'no_certificado',
        'cadena_original',
        'sello',
        'xml_path',
        'datos_json',
        'fecha_titulacion',
        'error_mensaje',
        'folio_proceso_ws',
        'estado_ws',
        'respuesta_ws',
        'enviado_ws_en',
    ];

    protected function casts(): array
    {
        return [
            'datos_json' => 'array',
            'fecha_titulacion' => 'datetime',
            'enviado_ws_en' => 'datetime',
        ];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteTitulacion::class, 'lote_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function estaTitulado(): bool
    {
        return $this->estado === self::TITULADO;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Titulacion>  $query */
    public function scopeEmitidas($query)
    {
        return $query->where('estado', self::TITULADO);
    }
}
