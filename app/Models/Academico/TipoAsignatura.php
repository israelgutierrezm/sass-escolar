<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_asignatura (TENANT-CONFIG). */
class TipoAsignatura extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_asignatura';

    protected $fillable = ['clave', 'nombre'];
}
