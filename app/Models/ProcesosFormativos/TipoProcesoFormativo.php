<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_proceso_formativo (TENANT-CONFIG) — servicio social, prácticas,
 * residencia, estancia profesional, internado…
 *
 * ── Las BANDERAS, nunca la clave ───────────────────────────────────────────
 * Lo que el código consulta es `exigeOrganizacion()` o `cuentaHoras()`, no
 * `clave === 'servicio_social'`: preguntar por la clave funciona hoy y deja de
 * funcionar EN SILENCIO el día que la escuela edite su catálogo. Es lo que
 * separa un catálogo configurable de cuatro casos cableados, y la lección que
 * este proyecto ya escribió con `entra_a_nomina` y `cuenta_como_egresado`.
 *
 * ── «Estancia profesional» y no «estancia» ─────────────────────────────────
 * `estancias` ya existe en Movilidad y es el periodo de un intercambio
 * académico, del que cuelga la revalidación de materias. Dos cosas distintas
 * con la misma palabra se acaban confundiendo.
 */
class TipoProcesoFormativo extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_proceso_formativo';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'exige_organizacion',
        'exige_plaza',
        'permite_organizacion_propuesta',
        'cuenta_horas',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'exige_organizacion' => 'boolean',
            'exige_plaza' => 'boolean',
            'permite_organizacion_propuesta' => 'boolean',
            'cuenta_horas' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
