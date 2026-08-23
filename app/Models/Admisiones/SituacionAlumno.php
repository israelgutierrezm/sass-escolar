<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * situaciones_alumno (TENANT-CONFIG) — usada por alumnos y matricula_oferta.
 *
 * `cuenta_como_egresado` es el DENOMINADOR del indicador de empleabilidad: sin
 * él habría que preguntar por `clave IN ('egresado','titulado')`, y una escuela
 * que agregue «Pasante» o «Egresado sin titular» dejaría gente fuera del
 * porcentaje sin que nada fallara.
 */
class SituacionAlumno extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_alumno';

    protected $fillable = ['clave', 'nombre', 'cuenta_como_egresado'];

    protected function casts(): array
    {
        return ['cuenta_como_egresado' => 'boolean'];
    }

    public function scopeDeEgresados(Builder $consulta): Builder
    {
        return $consulta->where('cuenta_como_egresado', true);
    }
}
