<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * metodos_pago (TENANT-CONFIG) — con qué se paga.
 *
 * `requiere_confirmacion` es la diferencia entre cobrar y prometer: un pago en
 * ventanilla se da por cobrado al registrarlo; uno por pasarela o transferencia
 * no lo está hasta que llega la confirmación. Sin esa bandera el sistema daría
 * por pagado un adeudo con dinero que nunca llegó.
 */
class MetodoPago extends Model
{
    use TieneAuditoria;

    protected $table = 'metodos_pago';

    protected $fillable = [
        'clave',
        'nombre',
        'clave_sat',
        'requiere_confirmacion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_confirmacion' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** Con qué estatus nace un pago hecho por este método. */
    public function estatusInicialDePago(): string
    {
        return $this->requiere_confirmacion ? Pago::ESTATUS_PENDIENTE : Pago::ESTATUS_COMPLETADO;
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
