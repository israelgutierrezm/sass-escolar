<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_inscripcion (TENANT-CONFIG). */
class SituacionInscripcion extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_inscripcion';

    protected $fillable = ['clave', 'nombre'];
}
