<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * menus_rol (TENANT) — la disposición del menú lateral de un rol.
 *
 * `estructura` es un árbol de claves del catálogo del frontend:
 *   [{ clave, hijos: [{ clave, hijos: [...] }] }]
 * Guardar solo claves (no etiquetas ni permisos) mantiene el catálogo como única
 * fuente de verdad: si mañana cambia una etiqueta o un permiso, el menú guardado
 * lo hereda sin tocar nada aquí.
 */
class MenuRol extends Model
{
    use TieneAuditoria;

    protected $table = 'menus_rol';

    protected $fillable = ['rol_id', 'estructura'];

    protected function casts(): array
    {
        return ['estructura' => 'array'];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}
