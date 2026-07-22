<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * documentos_docente (TENANT) — un comprobante del expediente del docente.
 */
class DocumentoDocente extends Model
{
    use TieneAuditoria;

    protected $table = 'documentos_docente';

    protected $fillable = [
        'persona_id',
        'documento_id',
        'descripcion',
        'url',
        'estado_documento_id',
        'vigencia',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'vigencia' => 'date',
        ];
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class, 'persona_id', 'persona_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRequerido::class, 'documento_id');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoDocumento::class, 'estado_documento_id');
    }

    /** Un documento con vigencia pasada ya no acredita nada. */
    public function estaVencido(?string $fecha = null): bool
    {
        if ($this->vigencia === null) {
            return false;
        }

        return ($fecha ?? now()->toDateString()) > $this->vigencia->toDateString();
    }
}
