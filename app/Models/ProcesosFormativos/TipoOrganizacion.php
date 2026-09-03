<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_organizacion (TENANT-CONFIG) — qué ES la receptora: una dependencia de
 * gobierno, una asociación civil, una empresa, otra escuela.
 *
 * Es otra pregunta que el SECTOR: un hospital puede ser público o privado —dos
 * tipos— y en los dos casos su sector es salud. Con una sola columna habría que
 * elegir cuál de las dos se pierde.
 */
class TipoOrganizacion extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_organizacion';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
