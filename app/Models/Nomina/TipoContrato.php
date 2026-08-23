<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** tipos_contrato (TENANT-CONFIG) — Con qué contrato está: base, honorarios, tiempo determinado, por asignatura. */
class TipoContrato extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_contrato';

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
