<?php

declare(strict_types=1);

namespace App\Models\Asistencia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * checadas (TENANT) — fichaje del reloj. Lo consumirá Nómina (Fase 4).
 */
class Checada extends Model
{
    use TieneAuditoria;

    public const ENTRADA = 'entrada';
    public const SALIDA = 'salida';

    protected $table = 'checadas';

    protected $fillable = [
        'persona_id',
        'dispositivo_id',
        'tipo_movimiento',
        'momento',
        'origen',
        'lat',
        'lng',
    ];

    protected function casts(): array
    {
        return [
            'momento' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoChecador::class, 'dispositivo_id');
    }

    /** Fichajes de una persona dentro de un rango, para calcular horas. */
    public function scopeDeLaPersonaEntre(Builder $query, int $personaId, string $desde, string $hasta): Builder
    {
        return $query->where('persona_id', $personaId)
            ->whereBetween('momento', [$desde, $hasta])
            ->orderBy('momento');
    }
}
