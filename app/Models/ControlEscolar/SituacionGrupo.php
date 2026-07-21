<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_grupo (TENANT-CONFIG). */
class SituacionGrupo extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_grupo';

    protected $fillable = ['clave', 'nombre'];
}
