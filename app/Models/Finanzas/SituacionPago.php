<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * situaciones_pago (TENANT-CONFIG) — cómo está financieramente un alumno.
 *
 * `bloquea` dice si esa situación impide reinscribirse o ver calificaciones.
 * Lo decide cada escuela: hay quien bloquea al primer adeudo y quien no bloquea
 * nunca.
 */
class SituacionPago extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_pago';

    protected $fillable = ['clave', 'nombre', 'bloquea'];

    protected function casts(): array
    {
        return ['bloquea' => 'boolean'];
    }

    public function scopeQueBloquean(Builder $query): Builder
    {
        return $query->where('bloquea', true);
    }
}
