<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un área de reportes: una CARPETA que la escuela renombra y reordena.
 *
 * No lleva permiso ni módulo a propósito: quién ve un reporte lo decide su
 * FUENTE. Si el área filtrara, mover un reporte de finanzas a un área llamada
 * «Dirección» concedería acceso a la cartera con un gesto de acomodo.
 */
class AreaReporte extends Model
{
    use TieneAuditoria;

    protected $table = 'areas_reporte';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'activo' => 'boolean'];
    }

    public function ubicaciones(): HasMany
    {
        return $this->hasMany(UbicacionReporte::class, 'area_id');
    }

    public function scopeActivas(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
