<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * roles (TENANT-CONFIG) — catálogo único de roles: de dominio Y de permisos.
 *
 * Extiende el Role de spatie/laravel-permission, así que todo su API sigue
 * disponible (givePermissionTo, hasPermissionTo, etc.) y `name` guarda la
 * clave del rol.
 *
 * Jerarquía: un rol sin `rol_padre_id` es una FACETA (lo que la persona es:
 * administrativo, docente, alumno...). Los roles funcionales cuelgan de una
 * faceta (encargado_admisiones → administrativo) y HEREDAN sus permisos.
 */
class Rol extends SpatieRole
{
    protected $fillable = [
        'name',
        'nombre',
        'guard_name',
        'tiempo_sesion',
        'rol_padre_id',
    ];

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rol_padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'rol_padre_id');
    }

    /** Facetas: roles de primer nivel, los que agrupan a los funcionales. */
    public function scopeFacetas(Builder $query): Builder
    {
        return $query->whereNull('rol_padre_id');
    }

    /**
     * Cadena de ancestros, del padre inmediato hacia arriba.
     *
     * @return array<int, self>
     */
    public function ancestros(): array
    {
        $ancestros = [];
        $actual = $this->padre;
        $vistos = [$this->getKey() => true]; // corta ciclos por si la config quedara mal

        while ($actual !== null && ! isset($vistos[$actual->getKey()])) {
            $ancestros[] = $actual;
            $vistos[$actual->getKey()] = true;
            $actual = $actual->padre;
        }

        return $ancestros;
    }

    /**
     * Permisos efectivos: los propios más los heredados de toda la cadena de
     * ancestros. Es lo que hace que "encargado de admisiones" pueda hacer todo
     * lo de "administrativo" y además lo suyo.
     */
    public function permisosEfectivos(): Collection
    {
        $permisos = $this->permissions;

        foreach ($this->ancestros() as $ancestro) {
            $permisos = $permisos->merge($ancestro->permissions);
        }

        return $permisos->unique('id')->values();
    }

    /** ¿El rol (o alguno de sus ancestros) concede este permiso? */
    public function concede(string $permiso): bool
    {
        return $this->permisosEfectivos()->contains('name', $permiso);
    }
}
