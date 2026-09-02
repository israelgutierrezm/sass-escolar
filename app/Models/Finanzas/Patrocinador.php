<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * patrocinadores (TENANT-CONFIG) — de qué bolsa sale cada beca.
 *
 * «La escuela» es uno más y viene sembrado: las becas que absorbe de su propio
 * ingreso se administran igual que las de una fundación, con su presupuesto y
 * su ejercido. Dejarlas sin patrocinador habría hecho imposible ponerles tope.
 *
 * NO es alguien a quien se le factura: dice de dónde sale el descuento, no a
 * quién se le cobra. Ver la migración.
 */
class Patrocinador extends Model
{
    use TieneAuditoria;

    protected $table = 'patrocinadores';

    protected $fillable = ['clave', 'nombre', 'contacto', 'correo', 'telefono', 'notas', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'protegido' => 'boolean'];
    }

    public function becas(): HasMany
    {
        return $this->hasMany(Beca::class, 'patrocinador_id');
    }

    public function presupuestos(): HasMany
    {
        return $this->hasMany(PresupuestoBeca::class, 'patrocinador_id');
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }
}
