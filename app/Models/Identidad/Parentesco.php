<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * parentescos (TENANT-CONFIG) — qué es un familiar del alumno.
 *
 * Era una cadena libre con la lista escrita a mano en el controlador y otra
 * lista de etiquetas en el Vue: dos copias del mismo enumerable en dos
 * lenguajes, y ninguna escuela podía agregar «abuela» sin tocar código.
 */
class Parentesco extends Model
{
    use TieneAuditoria;

    protected $table = 'parentescos';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * El nombre de un parentesco por su id, sin pegarle a la base cada vez.
     *
     * Hace falta porque `Persona::hijos()` es un `belongsToMany` con pivote
     * GENÉRICO: ahí `$hijo->pivot` no es un `TutorAlumno` y no resuelve la
     * relación, sólo trae `parentesco_id`. La alternativa era `->using()`, que
     * obliga a que `TutorAlumno` herede de `Pivot` —y lo usan también consultas
     * normales—, o una consulta por renglón, que es N+1 sobre una tabla de
     * cuatro filas.
     *
     * El mapa se arma una vez por petición. Un catálogo que cambia dos veces al
     * año no necesita releerse por cada hijo de cada padre.
     */
    public static function nombreDe(?int $id): ?string
    {
        static $mapa = null;

        if ($id === null) {
            return null;
        }

        $mapa ??= self::query()->pluck('nombre', 'id')->all();

        return $mapa[$id] ?? null;
    }

    /**
     * Los que se ofrecen al capturar.
     *
     * Se filtra a mano en cada desplegable y NO con un scope global: el global
     * filtraría también las lecturas POR ID, y entonces apagar un parentesco
     * dejaría a los vínculos que ya lo usan sin nada que mostrar. Es la misma
     * decisión que se tomó con los niveles de estudio.
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
