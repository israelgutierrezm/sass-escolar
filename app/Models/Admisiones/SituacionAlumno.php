<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_alumno (TENANT-CONFIG) — usada por alumnos y matricula_oferta. */
class SituacionAlumno extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_alumno';

    protected $fillable = ['clave', 'nombre'];
}
