<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * conceptos_nomina (TENANT-CONFIG) — qué renglones puede llevar un recibo.
 *
 * `naturaleza` es columna y no catálogo: un renglón sólo puede SUMAR o RESTAR
 * del total, y no hay una tercera cosa que hacerle a una cuenta. Una tabla de
 * dos filas cerradas por la aritmética sería una tabla que nadie puede ampliar.
 *
 * Sin `clave_sat` todavía: es un mapeo al CFDI de nómina y llega con él, igual
 * que el régimen fiscal. `es_gravable` sí está: no es un mapeo, es una propiedad
 * que la escuela decide.
 */
class ConceptoNomina extends Model
{
    use TieneAuditoria;

    public const PERCEPCION = 'percepcion';

    public const DEDUCCION = 'deduccion';

    protected $table = 'conceptos_nomina';

    protected $fillable = ['clave', 'nombre', 'naturaleza', 'es_gravable', 'formula_id', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'es_gravable' => 'boolean',
        ];
    }

    /**
     * Cómo se calcula solo, si es que se calcula.
     *
     * En NULL se captura a mano, que es lo que le toca al ISR: su tarifa es por
     * rangos y no un factor plano.
     */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(FormulaNomina::class, 'formula_id');
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }

    public function scopePercepciones(Builder $consulta): Builder
    {
        return $consulta->where('naturaleza', self::PERCEPCION);
    }

    public function scopeDeducciones(Builder $consulta): Builder
    {
        return $consulta->where('naturaleza', self::DEDUCCION);
    }

    public function suma(): bool
    {
        return $this->naturaleza === self::PERCEPCION;
    }
}
