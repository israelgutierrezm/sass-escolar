<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_ciclo (TENANT-CONFIG). */
class SituacionCiclo extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_ciclo';

    protected $fillable = ['clave', 'nombre'];
}
