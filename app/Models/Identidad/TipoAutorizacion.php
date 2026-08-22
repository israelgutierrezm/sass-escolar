<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** tipos_autorizacion (TENANT-CONFIG) — qué clase de permiso se le pide a la familia. */
class TipoAutorizacion extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_autorizacion';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * Los que se ofrecen al emitir.
     *
     * A mano y no como scope global, por lo mismo que en el resto de catálogos
     * del proyecto: el global filtraría también las lecturas POR ID, y apagar un
     * tipo dejaría sin nombre a las autorizaciones que ya se emitieron con él.
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
