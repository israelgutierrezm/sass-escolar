<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** observaciones_historial (TENANT-CONFIG). */
class ObservacionHistorial extends Model
{
    use TieneAuditoria;

    protected $table = 'observaciones_historial';

    protected $fillable = ['clave', 'nombre'];
}
