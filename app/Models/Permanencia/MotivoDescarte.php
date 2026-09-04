<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * motivos_descarte (TENANT-CONFIG) — por qué se descartó una alerta.
 *
 * ── `cuenta_como_falso_positivo` es lo que permite calibrar una regla ──────
 * «El dato estaba mal capturado» y «la regla no aplica a este caso» acusan a la
 * REGLA. «Ya se atendió por otra vía» no: ahí la señal era cierta y alguien ya
 * estaba encima. Separarlos es lo que permite medir si una regla está mal
 * calibrada sin castigarla por los descartes legítimos.
 *
 * Y esa medición no es un adorno: una cola de alertas que todo el mundo
 * descarta sin leer es como este módulo se vuelve ruido, y la tasa de falsos
 * positivos POR REGLA es la única señal temprana de que eso está pasando.
 *
 * ── Catálogo aparte de los motivos de cierre, a propósito ──────────────────
 * Cerrar un caso y descartar una señal son actos distintos con vocabularios
 * distintos: «el alumno se recuperó» no es un motivo para descartar una alerta,
 * y «el dato estaba mal» no es un motivo para cerrar un caso. Fundidos, cada
 * desplegable ofrecería la mitad de opciones que no significan nada ahí.
 */
class MotivoDescarte extends Model
{
    use TieneAuditoria;

    protected $table = 'motivos_descarte';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'cuenta_como_falso_positivo',
        'orden',
        'activo',
    ];

    protected $attributes = [
        'cuenta_como_falso_positivo' => false,
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'cuenta_como_falso_positivo' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
