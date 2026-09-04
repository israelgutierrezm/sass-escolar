<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * motivos_cierre_caso (TENANT-CONFIG) — por qué se cerró un caso.
 *
 * ── `cuenta_como_exito` tiene TRES valores, no dos ─────────────────────────
 * Encendida: la intervención sirvió. Apagada: no sirvió. **NULL: ni una cosa ni
 * otra** — el alumno cambió de plantel, el caso se abrió por error, la señal
 * resultó ser de otra persona. Contar un traslado como fracaso castigaría a
 * quien atendió bien un caso que dejó de ser suyo, y contarlo como éxito
 * inflaría el indicador con casos que nadie resolvió.
 *
 * Sin esta bandera, «efectividad de las intervenciones» habría que calcularla
 * con una lista de claves escrita en el código, y entonces el motivo que la
 * escuela agregue mañana no contaría ni a favor ni en contra, en silencio.
 */
class MotivoCierreCaso extends Model
{
    use TieneAuditoria;

    protected $table = 'motivos_cierre_caso';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'cuenta_como_exito',
        'orden',
        'activo',
    ];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return [
            'cuenta_como_exito' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
