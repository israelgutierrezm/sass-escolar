<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** tamanos_empresa (TENANT-CONFIG) — catálogo del módulo de bolsa de trabajo. */
class TamanoEmpresa extends Model
{
    use TieneAuditoria;

    protected $table = 'tamanos_empresa';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * Los que se ofrecen al capturar.
     *
     * A mano y no como scope global: el global filtraría también las lecturas
     * POR ID, y apagar una opción dejaría sin nombre a los registros que ya la
     * usan. Es la misma decisión que en el resto de catálogos del proyecto.
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
