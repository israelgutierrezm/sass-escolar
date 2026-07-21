<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Carrera;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** documentos_requeridos (TENANT) — catálogo de qué se pide. */
class DocumentoRequerido extends Model
{
    use TieneAuditoria;

    protected $table = 'documentos_requeridos';

    protected $fillable = ['nombre', 'descripcion', 'obligatorio'];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
        ];
    }

    /** Carreras que exigen este documento. */
    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'documento_carrera', 'documento_id', 'carrera_id')
            ->withTimestamps();
    }

    public function etiquetas(): BelongsToMany
    {
        return $this->belongsToMany(EtiquetaDocumento::class, 'documento_etiqueta', 'documento_id', 'etiqueta_id')
            ->withTimestamps();
    }
}
