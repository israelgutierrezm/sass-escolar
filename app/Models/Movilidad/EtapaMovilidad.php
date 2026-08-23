<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * etapas_movilidad (TENANT-CONFIG) — por dónde va una postulación.
 *
 * Dos banderas INDEPENDIENTES, que es lo que el código lee: `acepta` marca las
 * etapas que consumen cupo y habilitan abrir la estancia; `es_final`, las que
 * cierran el proceso. «Concluido» hace las dos cosas y «Rechazado» sólo la
 * segunda, así que un enum no serviría.
 */
class EtapaMovilidad extends Model
{
    use TieneAuditoria;

    protected $table = 'etapas_movilidad';

    protected $fillable = ['clave', 'nombre', 'acepta', 'es_final', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'acepta' => 'boolean', 'es_final' => 'boolean'];
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

    /** Las que dan por aceptado al postulante. */
    public function scopeQueAceptan(Builder $consulta): Builder
    {
        return $consulta->where('acepta', true);
    }
}
