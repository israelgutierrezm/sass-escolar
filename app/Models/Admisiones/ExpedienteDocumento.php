<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Carrera;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * expediente_documentos (TENANT) — documento entregado por el aspirante.
 */
class ExpedienteDocumento extends Model
{
    use TieneAuditoria;

    protected $table = 'expediente_documentos';

    protected $fillable = [
        'aspirante_id',
        'documento_id',
        'carrera_id',
        'descripcion',
        'url',
        'estado_documento_id',
        'copia_certificada',
        'documento_fisico',
    ];

    protected function casts(): array
    {
        return [
            'copia_certificada' => 'boolean',
            'documento_fisico' => 'boolean',
        ];
    }

    public function aspirante(): BelongsTo
    {
        return $this->belongsTo(Aspirante::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRequerido::class, 'documento_id');
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class);
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoDocumento::class, 'estado_documento_id');
    }
}
