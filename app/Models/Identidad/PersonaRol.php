<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * persona_rol (TENANT) — asignación de un rol a una persona, opcionalmente
 * acotada a un campus.
 */
class PersonaRol extends Model
{
    use TieneAuditoria;

    protected $table = 'persona_rol';

    protected $fillable = [
        'persona_id',
        'rol_id',
        'campus_id',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    /** Campus al que se acota el rol; NULL = alcance global. */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /** ¿Este rol aplica en el campus dado? El alcance global aplica en todos. */
    public function aplicaEnCampus(int $campusId): bool
    {
        return $this->campus_id === null || $this->campus_id === $campusId;
    }
}
