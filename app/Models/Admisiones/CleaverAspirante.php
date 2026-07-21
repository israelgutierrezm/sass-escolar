<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * cleaver_aspirante (TENANT) — respuesta del aspirante a un reactivo DISC.
 */
class CleaverAspirante extends Model
{
    use TieneAuditoria;

    protected $table = 'cleaver_aspirante';

    protected $fillable = [
        'aspirante_id',
        'reactivo_cleaver_id',
        'respuesta_id',
    ];

    public function aspirante(): BelongsTo
    {
        return $this->belongsTo(Aspirante::class);
    }

    public function reactivo(): BelongsTo
    {
        return $this->belongsTo(ReactivoCleaver::class, 'reactivo_cleaver_id');
    }
}
