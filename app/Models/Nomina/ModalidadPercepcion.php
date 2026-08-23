<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * modalidades_percepcion (TENANT-CONFIG) — de qué se compone un sueldo.
 *
 * ── Las BANDERAS son el mecanismo; la clave sólo es su nombre ─────────────
 * Un catálogo cuyos valores el código reconoce por nombre no es configurable:
 * la escuela agrega una fila y no pasa nada, porque el motor no sabe qué hacer
 * con ella. Aquí cada modalidad declara qué componentes usa y el motor suma lo
 * que las banderas enciendan, así que «base más horas» se crea desde la
 * pantalla y funciona sin tocar código.
 *
 * Por eso «mixto» no es un cuarto caso especial: es una fila con dos banderas.
 */
class ModalidadPercepcion extends Model
{
    use TieneAuditoria;

    protected $table = 'modalidades_percepcion';

    protected $fillable = [
        'clave',
        'nombre',
        'usa_monto_base',
        'usa_tarifa_hora',
        'usa_tarifa_asignatura',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'usa_monto_base' => 'boolean',
            'usa_tarifa_hora' => 'boolean',
            'usa_tarifa_asignatura' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }

    /**
     * Qué campos del esquema exige esta modalidad.
     *
     * Vive aquí y no en el formulario porque lo consultan los dos lados: la
     * validación al guardar y el motor al calcular. Escrito dos veces, un día
     * la pantalla pediría un dato que el cálculo no usa, o al revés.
     *
     * @return array<int, string>
     */
    public function componentes(): array
    {
        return array_keys(array_filter([
            'monto_base' => $this->usa_monto_base,
            'tarifa_hora' => $this->usa_tarifa_hora,
            'tarifa_asignatura' => $this->usa_tarifa_asignatura,
        ]));
    }

    /** Una modalidad que no usa nada no puede pagar nada. */
    public function esUtilizable(): bool
    {
        return $this->componentes() !== [];
    }
}
