<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** etiquetas_documento (TENANT) — clasificación de documentos. */
class EtiquetaDocumento extends Model
{
    use TieneAuditoria;

    protected $table = 'etiquetas_documento';

    protected $fillable = ['clave', 'nombre'];

    public function documentos(): BelongsToMany
    {
        return $this->belongsToMany(DocumentoRequerido::class, 'documento_etiqueta', 'etiqueta_id', 'documento_id')
            ->withTimestamps();
    }
}
