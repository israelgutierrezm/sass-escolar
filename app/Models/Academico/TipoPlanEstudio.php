<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_plan_estudio (TENANT-CONFIG). */
class TipoPlanEstudio extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_plan_estudio';

    protected $fillable = ['clave', 'nombre'];
}
