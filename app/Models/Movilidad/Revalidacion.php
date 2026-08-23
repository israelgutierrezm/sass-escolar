<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Historial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * revalidaciones (TENANT) — una materia cursada fuera y su equivalencia aquí.
 *
 * Guarda lo que dijo el DESTINO tal cual —`materia_externa`,
 * `calificacion_externa`— porque allá la materia se llama de otro modo y la
 * calificación puede venir en otra escala. La conversión a la nuestra es un
 * juicio humano y por eso `calificacion_equivalente` se captura: no hay tabla
 * de conversión universal entre sistemas de calificación.
 *
 * `historial_id` apunta al renglón que asentó. Con valor = ya está en el
 * expediente; para corregirla hay que revocarla, no editarla.
 */
class Revalidacion extends Model
{
    use TieneAuditoria;

    protected $table = 'revalidaciones';

    protected $fillable = [
        'estancia_id',
        'materia_externa',
        'calificacion_externa',
        'plan_materia_id',
        'calificacion_equivalente',
        'dictamen_id',
        'ciclo_id',
        'historial_id',
        'dictaminada_en',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'calificacion_equivalente' => 'decimal:2',
            'dictaminada_en' => 'datetime',
        ];
    }

    public function estancia(): BelongsTo
    {
        return $this->belongsTo(Estancia::class, 'estancia_id');
    }

    public function dictamen(): BelongsTo
    {
        return $this->belongsTo(DictamenRevalidacion::class, 'dictamen_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    public function historial(): BelongsTo
    {
        return $this->belongsTo(Historial::class, 'historial_id');
    }

    /** ¿Ya escribió en el expediente? */
    public function estaAsentada(): bool
    {
        return $this->historial_id !== null;
    }
}
