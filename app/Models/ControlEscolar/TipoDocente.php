<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_docente (TENANT-CONFIG). */
class TipoDocente extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_docente';

    protected $fillable = ['clave', 'nombre'];
}
