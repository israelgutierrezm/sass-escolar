<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * situaciones_organizacion (TENANT-CONFIG) — en qué punto está la receptora.
 *
 * `acepta_asignaciones` es la bandera que decide si se le puede mandar un
 * alumno. Preguntar por `clave === 'activa'` se equivoca en los dos casos que
 * importan: una escuela que agregue «en trámite» o «con convenio en firma»
 * decide ella misma de qué lado cae, y ninguno de los dos se llama «activa».
 *
 * Se APAGA, no se borra: sus expedientes históricos son la prueba de dónde
 * prestó su servicio alguien, y borrarla se los llevaría.
 */
class SituacionOrganizacion extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_organizacion';

    protected $fillable = ['clave', 'nombre', 'acepta_asignaciones', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['acepta_asignaciones' => 'boolean', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
