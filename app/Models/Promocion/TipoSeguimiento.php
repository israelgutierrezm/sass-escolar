<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_seguimiento (TENANT-CONFIG) — cómo se contactó al prospecto.
 *
 * `exige_proximo_contacto` es la bandera que evita el hoyo clásico del CRM: una
 * llamada registrada sin siguiente paso es un prospecto que nadie va a volver a
 * marcar. Cada escuela decide en qué tipos lo exige.
 */
class TipoSeguimiento extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_seguimiento';

    protected $attributes = ['exige_proximo_contacto' => false, 'activo' => true];

    protected $fillable = ['clave', 'nombre', 'exige_proximo_contacto', 'activo'];

    protected function casts(): array
    {
        return ['exige_proximo_contacto' => 'boolean', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
