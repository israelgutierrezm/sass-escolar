<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_asesor (TENANT-CONFIG). */
class SituacionAsesor extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_asesor';

    protected $fillable = ['clave', 'nombre'];
}
