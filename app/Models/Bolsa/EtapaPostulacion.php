<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * etapas_postulacion (TENANT-CONFIG) — por dónde va una postulación.
 *
 * Lleva `orden` porque es un recorrido: la secuencia es lo que permite medir en
 * qué etapa se atoran los postulantes.
 */
class EtapaPostulacion extends Model
{
    use TieneAuditoria;

    protected $table = 'etapas_postulacion';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }

    /** La primera del recorrido: con la que nace toda postulación. */
    public static function inicial(): ?self
    {
        return self::query()->activos()->first();
    }
}
