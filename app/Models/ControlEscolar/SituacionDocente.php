<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_docente (TENANT-CONFIG). */
class SituacionDocente extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_docente';

    protected $fillable = ['clave', 'nombre'];
}
