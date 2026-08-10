<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * servicios (TENANT-CONFIG) — un producto o servicio que la escuela vende
 * suelto: una constancia, un examen extraordinario, la credencial de repuesto.
 *
 * Finanzas le pone el precio y su concepto fiscal; Control Escolar decide si el
 * alumno puede pedirlo y con qué instrucciones.
 */
class Servicio extends Model
{
    use TieneAuditoria;

    protected $table = 'servicios';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'concepto_id',
        'precio',
        'solicitable',
        'instrucciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'solicitable' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }

    /**
     * Si al pedirlo hay que pagar algo.
     *
     * Se pregunta por el importe y no por «tiene concepto»: el concepto es el
     * dato fiscal de lo que se cobra, no la razón por la que se cobra. Un
     * servicio con concepto y precio cero sigue siendo gratuito.
     */
    public function tieneCosto(): bool
    {
        return (float) $this->precio > 0;
    }

    /** Los que el alumno puede pedir hoy. */
    public function scopeOfrecidos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->where('solicitable', true)->orderBy('nombre');
    }
}
