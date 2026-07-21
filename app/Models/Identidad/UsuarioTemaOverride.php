<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * usuario_tema_override (TENANT) — un token de tema personalizado por fila.
 */
class UsuarioTemaOverride extends Model
{
    use TieneAuditoria;

    protected $table = 'usuario_tema_override';

    protected $fillable = [
        'usuario_id',
        'token',
        'valor',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
