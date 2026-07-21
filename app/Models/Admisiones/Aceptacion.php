<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * aceptaciones (TENANT) — constancia de que una persona aceptó un documento
 * normativo en una versión concreta.
 */
class Aceptacion extends Model
{
    use TieneAuditoria;

    protected $table = 'aceptaciones';

    protected $fillable = [
        'persona_id',
        'documento_normativo_id',
        'version',
        'aceptado_en',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'aceptado_en' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoNormativo::class, 'documento_normativo_id');
    }

    /**
     * ¿La aceptación corresponde a la versión vigente del documento? Si el
     * documento subió de versión, hay que volver a pedirla.
     */
    public function estaVigente(): bool
    {
        return $this->version === $this->documento->version;
    }
}
