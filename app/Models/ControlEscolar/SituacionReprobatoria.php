<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_reprobatoria (TENANT-CONFIG). */
class SituacionReprobatoria extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_reprobatoria';

    protected $fillable = ['clave', 'nombre'];
}
