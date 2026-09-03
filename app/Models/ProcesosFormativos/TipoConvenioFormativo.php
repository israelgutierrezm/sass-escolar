<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_convenio_formativo (TENANT-CONFIG) — marco, específico, carta
 * compromiso…
 *
 * Aparte de `tipos_convenio` de Movilidad: aquéllos son convenios ACADÉMICOS
 * con instituciones aliadas —doble titulación, intercambio— y viven detrás de
 * otro módulo apagable.
 */
class TipoConvenioFormativo extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_convenio_formativo';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
