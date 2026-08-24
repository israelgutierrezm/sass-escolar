<?php

declare(strict_types=1);

namespace App\Models\Disciplina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_sancion (TENANT-CONFIG) — el catálogo de sanciones.
 *
 * `tiene_vigencia` es la bandera que gobierna el formulario: una suspensión
 * pide desde/hasta, una amonestación no. Es lo que hace del catálogo algo
 * configurable en vez de cuatro casos cableados.
 */
class TipoSancion extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_sancion';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'tiene_vigencia', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['tiene_vigencia' => 'boolean', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
