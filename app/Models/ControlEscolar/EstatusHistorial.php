<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** estatus_historial (TENANT-CONFIG). */
class EstatusHistorial extends Model
{
    use TieneAuditoria;

    protected $table = 'estatus_historial';

    protected $fillable = ['clave', 'nombre'];
}
