<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * origenes_aspirante (TENANT-CONFIG) — por dónde llegó el prospecto.
 *
 * Era un varchar libre. Pasa a catálogo porque de él dependen dos cosas que no
 * funcionan con texto a mano: reportar cuántos llegaron por cada vía, y
 * distinguir al que se registró SOLO desde la web del que capturó un promotor.
 */
class OrigenAspirante extends Model
{
    use TieneAuditoria;

    protected $table = 'origenes_aspirante';

    protected $attributes = ['autogestivo' => false, 'activo' => true];

    protected $fillable = ['clave', 'nombre', 'autogestivo', 'activo'];

    protected function casts(): array
    {
        return ['autogestivo' => 'boolean', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /** Los que representan un registro sin intervención de nadie. */
    public function scopeAutogestivos(Builder $query): Builder
    {
        return $query->where('autogestivo', true);
    }
}
