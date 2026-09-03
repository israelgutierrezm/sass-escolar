<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * situaciones_convenio_formativo (TENANT-CONFIG) — en qué punto está un
 * convenio: en trámite, vigente, suspendido, terminado.
 *
 * `ampara_asignaciones` es la bandera que decide si bajo ese convenio se le
 * puede seguir mandando gente a la organización. Preguntar por
 * `clave === 'vigente'` se equivoca justo donde importa: una escuela que agregue
 * «en renovación» decide ella misma de qué lado cae, y no se llama «vigente».
 *
 * Es OTRA pregunta que la fecha. Un convenio puede estar vigente en el papel y
 * suspendido por la escuela; y puede tener la situación «vigente» con la fecha
 * ya pasada. `ConvenioFormativo::estaVigente()` cruza las dos.
 */
class SituacionConvenioFormativo extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_convenio_formativo';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'ampara_asignaciones', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['ampara_asignaciones' => 'boolean', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
