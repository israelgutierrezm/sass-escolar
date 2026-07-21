<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** estados_documento (TENANT-CONFIG). */
class EstadoDocumento extends Model
{
    use TieneAuditoria;

    protected $table = 'estados_documento';

    protected $fillable = ['clave', 'nombre'];
}
