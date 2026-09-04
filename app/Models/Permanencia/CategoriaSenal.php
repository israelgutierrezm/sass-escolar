<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * categorias_senal (TENANT-CONFIG) — en qué se agrupan las señales.
 *
 * ── Aquí vive la capa de privacidad por CATEGORÍA ──────────────────────────
 * El pedido del cliente es explícito: «un docente ordinario no debería conocer
 * montos o detalles de deuda». Esa regla se puede escribir de dos maneras:
 * repartida por cada pantalla que enseñe una alerta, o declarada una vez en el
 * catálogo. Repartida falla el día que alguien agregue la séptima pantalla y se
 * olvide — y no falla ruidosamente, sino enseñando el monto.
 *
 * Por eso la categoría dice si es sensible y qué permiso abre su detalle, y las
 * pantallas preguntan aquí. Es el mismo criterio con el que
 * `situaciones_pago.bloquea` decide quién no se puede inscribir: una bandera de
 * catálogo consultada en un solo sitio.
 *
 * ── SENSIBLE no significa INVISIBLE ────────────────────────────────────────
 * Quien no la alcanza sigue viendo QUE HAY una señal de esta categoría, y eso
 * es deliberado: le permite saber que el caso tiene un frente administrativo y
 * llamar a quien corresponde. Lo que no ve es el valor observado, el umbral y
 * la evidencia. Esconder la existencia entera dejaría a un tutor interviniendo
 * sobre la mitad del problema sin saber que hay otra mitad.
 */
class CategoriaSenal extends Model
{
    use TieneAuditoria;

    protected $table = 'categorias_senal';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'sensible',
        'permiso_detalle',
        'color',
        'orden',
        'activo',
    ];

    protected $attributes = [
        'sensible' => false,
        'color' => 'gris',
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'sensible' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivas(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }

    /**
     * ¿Esta persona puede ver el DETALLE de una señal de esta categoría?
     *
     * La única definición. Las pantallas, el motor de exportación y las
     * notificaciones preguntan aquí; escrito en cada una, la que se equivoque
     * enseñaría el monto de una deuda a un docente y nadie se enteraría.
     *
     * Una categoría no sensible la ve cualquiera que ya haya llegado hasta la
     * alerta —para eso está `ver-alertas`—; una sensible exige además su
     * permiso propio. Y una sensible SIN permiso declarado no la alcanza nadie:
     * es el lado seguro, aunque el modelo lo impide al guardar.
     */
    public function alcanzaElDetalle(?Usuario $usuario): bool
    {
        if (! $this->sensible) {
            return true;
        }

        if ($this->permiso_detalle === null || $usuario === null) {
            return false;
        }

        return $usuario->can($this->permiso_detalle);
    }
}
