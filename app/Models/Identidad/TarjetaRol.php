<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tarjetas_rol (TENANT) — qué tarjetas del panel enciende un rol.
 *
 * `activas` guarda las CLAVES de tarjeta prendidas. Sin fila para el rol, el
 * panel muestra todas las que el permiso permita (default). Con fila, solo las
 * de la lista (y siempre filtradas por permiso: encender no da acceso).
 */
class TarjetaRol extends Model
{
    use TieneAuditoria;

    protected $table = 'tarjetas_rol';

    protected $fillable = ['rol_id', 'activas'];

    protected function casts(): array
    {
        return ['activas' => 'array'];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}
