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

    /** Días que faltan para que venza (negativo si ya venció); null si no hay fecha. */
    public function diasRestantes(): ?int
    {
        if ($this->vigencia_fin === null) {
            return null;
        }

        // Se compara por día (startOfDay) para que «hoy» cuente como 0, no como
        // vencido por unas horas.
        return (int) now()->startOfDay()->diffInDays($this->vigencia_fin->startOfDay(), false);
    }

    /** True si el certificado sigue vigente hoy. */
    public function estaVigente(): bool
    {
        $dias = $this->diasRestantes();

        return $dias === null ? true : $dias >= 0;
    }
}
