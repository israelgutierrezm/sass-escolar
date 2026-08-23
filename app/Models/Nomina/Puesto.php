<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** puestos (TENANT-CONFIG) — El organigrama de la escuela. NO es `cargos`, que es el catálogo oficial de la SEP para firmar certificados y no se toca. */
class Puesto extends Model
{
    use TieneAuditoria;

    protected $table = 'puestos';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
