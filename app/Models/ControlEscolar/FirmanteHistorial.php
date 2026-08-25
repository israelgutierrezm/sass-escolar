<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quién rubrica el historial impreso.
 *
 * Antes era un solo `responsable_nombre` en el diseño, así que una escuela que
 * exige la firma del director Y la de control escolar —lo normal en un
 * documento escolar— no lo podía expresar.
 *
 * Cada uno trae su IMAGEN de firma, que es un archivo del disco privado con su
 * propio ciclo de vida; por eso es tabla y no un JSON dentro del diseño.
 */
class FirmanteHistorial extends Model
{
    use TieneAuditoria;

    protected $table = 'firmantes_historial';

    protected $fillable = ['diseno_id', 'nombre', 'cargo', 'firma_imagen', 'orden'];

    protected function casts(): array
    {
        return ['orden' => 'integer'];
    }

    public function diseno(): BelongsTo
    {
        return $this->belongsTo(DisenoHistorial::class, 'diseno_id');
    }

    /** En el orden en que se imprimen, de izquierda a derecha. */
    public function scopeEnOrden(Builder $consulta): Builder
    {
        return $consulta->orderBy('orden')->orderBy('id');
    }
}
