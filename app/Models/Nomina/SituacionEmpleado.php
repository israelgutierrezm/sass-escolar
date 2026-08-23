<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * situaciones_empleado (TENANT-CONFIG) — en qué está quien SIGUE contratado.
 *
 * ── Aquí no hay «baja» ────────────────────────────────────────────────────
 * Eso lo dice `expedientes_laborales.fecha_baja`, y es su única fuente de
 * verdad. Con las dos cosas, un expediente podría decir «activo» con fecha de
 * baja puesta y nadie sabría cuál manda. Estas situaciones distinguen matices
 * de quien está dentro: activo, licencia con o sin goce, comisión.
 *
 * ── Lo que el motor de nómina consulta es `entra_a_nomina` ────────────────
 * No la clave. Quien está de licencia SIN goce sigue contratado y no se le
 * paga; quien está comisionado sí. Preguntar por `clave = 'activo'` dejaría
 * fuera a la comisión y dentro a la licencia sin goce, y ninguna de las dos
 * cosas se notaría hasta el día de pago.
 */
class SituacionEmpleado extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_empleado';

    protected $fillable = ['clave', 'nombre', 'entra_a_nomina', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'entra_a_nomina' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }

    /** Las que sí se pagan. Lo que pregunta el cálculo del periodo. */
    public function scopePagables(Builder $consulta): Builder
    {
        return $consulta->where('entra_a_nomina', true);
    }
}
