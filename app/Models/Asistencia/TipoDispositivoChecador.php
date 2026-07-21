<?php

declare(strict_types=1);

namespace App\Models\Asistencia;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_dispositivo_checador (TENANT-CONFIG). */
class TipoDispositivoChecador extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_dispositivo_checador';

    protected $fillable = ['clave', 'nombre'];
}
