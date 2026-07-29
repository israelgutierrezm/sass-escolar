<?php

declare(strict_types=1);

namespace App\Models\Emision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * certificados_responsable (TENANT) — el historial de certificados de un
 * responsable. Cada renovación agrega uno; el `vigente` es con el que firma hoy.
 * `serie` es única en toda la escuela (un cert no se registra dos veces).
 *
 * `cer_pem` (público) y `key_encriptado` (privado, cifrado) se guardan solo si
 * el usuario lo pide; nunca se serializan al frontend.
 */
class CertificadoResponsable extends Model
{
    protected $table = 'certificados_responsable';

    protected $fillable = [
        'responsable_id',
        'serie',
        'titular',
        'vigencia_inicio',
        'vigencia_fin',
        'vigente',
    ];

    protected $hidden = ['cer_pem', 'key_encriptado'];

    protected function casts(): array
    {
        return [
            'vigencia_inicio' => 'date',
            'vigencia_fin' => 'date',
            'vigente' => 'boolean',
        ];
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Responsable::class);
    }
}
