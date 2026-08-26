<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dónde vive un reporte y cómo se llama en ESTA escuela.
 *
 * `reporte` es la clave de una clase, sin foránea: apunta a código. Y `nombre`
 * en null significa «el título que declara la clase», no «sin nombre»: así un
 * reporte que se renombre en el código sigue actualizándose solo para quien no
 * lo haya rebautizado.
 */
class UbicacionReporte extends Model
{
    use TieneAuditoria;

    protected $table = 'ubicaciones_reporte';

    protected $fillable = ['reporte', 'area_id', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'activo' => 'boolean'];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AreaReporte::class, 'area_id');
    }
}
