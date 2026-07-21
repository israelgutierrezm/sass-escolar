<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tema_tokens (TENANT-CONFIG) — un color/token por fila.
 */
class TemaToken extends Model
{
    use TieneAuditoria;

    protected $table = 'tema_tokens';

    protected $fillable = [
        'tema_id',
        'token',
        'valor',
    ];

    public function tema(): BelongsTo
    {
        return $this->belongsTo(Tema::class);
    }
}
