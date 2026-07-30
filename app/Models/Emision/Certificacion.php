<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * certificaciones (TENANT) — un alumno-carrera dentro de un lote. Mientras el
 * lote no se firma es `pendiente`; al firmar pasa a `certificado` con su XML
 * sellado y una foto (`datos_json`) de su expediente académico en ese momento.
 */
class Certificacion extends Model
{
    use TieneAuditoria;

    protected $table = 'certificaciones';

    public const PENDIENTE = 'pendiente';
    public const CERTIFICADO = 'certificado';
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
        'fecha_certificacion',
        'error_mensaje',
    ];

    protected function casts(): array
    {
        return [
            'datos_json' => 'array',
            'fecha_certificacion' => 'datetime',
        ];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteCertificacion::class, 'lote_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function estaCertificado(): bool
    {
        return $this->estado === self::CERTIFICADO;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Certificacion>  $query */
    public function scopeEmitidas($query)
    {
        return $query->where('estado', self::CERTIFICADO);
    }
}
