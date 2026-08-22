<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** situaciones_vacante (TENANT-CONFIG) — catálogo del módulo de bolsa de trabajo. */
class SituacionVacante extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_vacante';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** Los que se ofrecen al capturar; apagar uno no borra lo que ya lo usa. */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
