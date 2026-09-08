<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * proveedores (TENANT) — a quién se le compra. Ver `docs/plan-compras-cxp.md`.
 *
 * Estructura el `beneficiario` del egreso; el RFC es opcional pero ÚNICO —el
 * mismo proveedor capturado dos veces reparte sus egresos entre duplicados—. Se
 * APAGA, no se borra: sus egresos y cuentas por pagar son historia.
 */
class Proveedor extends Model
{
    use TieneAuditoria;

    protected $table = 'proveedores';

    protected $fillable = [
        'nombre',
        'rfc',
        'razon_social',
        'contacto_nombre',
        'telefono',
        'correo',
        'domicilio',
        'activo',
        'notas',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function egresos(): HasMany
    {
        return $this->hasMany(Egreso::class, 'proveedor_id');
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }
}
