<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_informe_proceso (TENANT-CONFIG) — parcial, bimestral, final,
 * memoria…
 *
 * `es_final` es la bandera que consulta la LIBERACIÓN para saber si el informe
 * que cierra el proceso está entregado. Es justo el sitio donde equivocarse
 * sale más caro —se liberaría a alguien sin su informe— así que no se pregunta
 * por la clave.
 */
class TipoInformeProceso extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_informe_proceso';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'es_final', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['es_final' => 'boolean', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
