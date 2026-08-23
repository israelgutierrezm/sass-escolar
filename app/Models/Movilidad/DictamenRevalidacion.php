<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * dictamenes_revalidacion (TENANT-CONFIG) — qué se resolvió sobre una materia
 * traída de fuera.
 *
 * `asienta` es lo que el código lee: dice cuál de ellos ESCRIBE en el historial
 * académico. La clave no sirve, porque una escuela puede agregar «aprobada
 * parcialmente» y tiene que poder decidir si esa fila asienta o no.
 */
class DictamenRevalidacion extends Model
{
    use TieneAuditoria;

    protected $table = 'dictamenes_revalidacion';

    protected $fillable = ['clave', 'nombre', 'asienta', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'asienta' => 'boolean'];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }

    /** El que deja la revalidación esperando dictamen. */
    public static function pendiente(): ?self
    {
        return self::query()->where('asienta', false)->orderByDesc('orden')->first();
    }
}
