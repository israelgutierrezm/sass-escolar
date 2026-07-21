<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** clasificaciones_asignatura (TENANT-CONFIG). */
class ClasificacionAsignatura extends Model
{
    use TieneAuditoria;

    protected $table = 'clasificaciones_asignatura';

    protected $fillable = ['clave', 'nombre'];
}
