<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * situaciones_convenio (TENANT-CONFIG) — en qué está el acuerdo.
 *
 * ── Aquí NO hay «vencido» ─────────────────────────────────────────────────
 * Eso lo dice `convenios.vigente_hasta`. Con las dos cosas, un convenio podría
 * decir «vigente» con la fecha pasada y nadie sabría cuál manda; es la misma
 * trampa que ya mordió con las vacantes de la bolsa.
 *
 * Lo que el código lee es `permite_convocar`, no la clave: mañana la escuela
 * agrega «en renovación» y el sistema tiene que saber qué hacer con ella.
 */
class SituacionConvenio extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_convenio';

    protected $fillable = ['clave', 'nombre', 'permite_convocar', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'permite_convocar' => 'boolean'];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
