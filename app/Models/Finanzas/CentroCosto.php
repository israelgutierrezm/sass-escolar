<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * centros_costo (TENANT-CONFIG) — contra qué se carga el gasto.
 *
 * Es una DIMENSIÓN, no una cuenta contable: sirve para preguntar «¿en qué se
 * está yendo el dinero del campus norte?», no para cuadrar un balance.
 *
 * `campus_id` va nullable a propósito. Hay gasto que no es de ningún plantel
 * —licencias, dirección general, un despacho externo— y obligarlo a elegir uno
 * repartiría a ojo lo que no se reparte, que es la forma más rápida de que un
 * presupuesto por campus deje de significar nada.
 */
class CentroCosto extends Model
{
    use TieneAuditoria;

    protected $table = 'centros_costo';

    protected $fillable = ['clave', 'nombre', 'campus_id', 'notas', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }
}
