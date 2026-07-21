<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_evaluacion (TENANT-CONFIG). */
class TipoEvaluacion extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_evaluacion';

    protected $fillable = ['clave', 'nombre'];
}
