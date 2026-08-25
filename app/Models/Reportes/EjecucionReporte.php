<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada vez que alguien corre un reporte.
 *
 * ── Para qué ─────────────────────────────────────────────────────────────
 * Para DECIDIR con datos qué construir después: qué reportes se usan de verdad,
 * con qué filtros, cuáles tardan y cuáles no abre nadie. Sin esto, la pregunta
 * de si vale la pena un constructor de reportes se contesta a ojo.
 *
 * Y para contestar «¿quién sacó la lista con las CURP?», que en un sistema
 * escolar es una pregunta que se acaba haciendo.
 */
class EjecucionReporte extends Model
{
    use TieneAuditoria;

    protected $table = 'ejecuciones_reporte';

    protected $fillable = [
        'reporte', 'persona_id', 'formato', 'filas',
        'milisegundos', 'filtros', 'columnas', 'columnas_omitidas',
    ];

    protected function casts(): array
    {
        return [
            'filas' => 'integer',
            'milisegundos' => 'integer',
            'filtros' => 'array',
            'columnas' => 'array',
            'columnas_omitidas' => 'array',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
