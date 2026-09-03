<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * sectores_organizacion (TENANT-CONFIG) — a qué se dedica la receptora.
 *
 * Aparte de `sectores_economicos` de la bolsa de trabajo, por lo mismo que el
 * padrón: aquélla vive detrás del módulo `bolsa_trabajo` y una escuela que lo
 * apague se quedaría sin poder clasificar a sus receptoras. Y los sectores no
 * son los mismos: aquí pesan gobierno, salud pública y asistencia social, que
 * en un padrón de empleadores casi no aparecen.
 */
class SectorOrganizacion extends Model
{
    use TieneAuditoria;

    protected $table = 'sectores_organizacion';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
