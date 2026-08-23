<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** motivos_baja_laboral (TENANT-CONFIG) — Por qué terminó el vínculo. Se pide al dar de baja: una baja sin razón no sirve para nada después. */
class MotivoBajaLaboral extends Model
{
    use TieneAuditoria;

    protected $table = 'motivos_baja_laboral';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
