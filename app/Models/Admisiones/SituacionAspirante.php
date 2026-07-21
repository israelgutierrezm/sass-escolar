<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_aspirante (TENANT-CONFIG). */
class SituacionAspirante extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_aspirante';

    protected $fillable = ['clave', 'nombre'];
}
