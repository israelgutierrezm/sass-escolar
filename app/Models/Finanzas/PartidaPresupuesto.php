<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * partidas_presupuesto (TENANT-CONFIG) — en qué se gasta.
 *
 * Sueldos, mantenimiento, materiales, servicios. Es catálogo de verdad: una
 * fila nueva aparece en el presupuesto, en la captura de egresos y en el
 * panorama, sin tocar código.
 */
class PartidaPresupuesto extends Model
{
    use TieneAuditoria;

    protected $table = 'partidas_presupuesto';

    protected $fillable = ['clave', 'nombre', 'notas', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }
}
