<?php

declare(strict_types=1);

namespace App\Models\Disciplina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_incidencia (TENANT-CONFIG) — el catálogo de conductas a registrar.
 *
 * `nivel` es la gravedad que fija la escuela, un número y no un enum: así una
 * escuela puede tener tres niveles y otra cinco sin tocar código.
 */
class TipoIncidencia extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_incidencia';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'nivel', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['nivel' => 'integer', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    /** Sólo los encendidos, para los desplegables. */
    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
