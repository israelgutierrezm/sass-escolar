<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** autorizaciones_reconocimiento (TENANT-CONFIG) — tipo de RVOE/incorporación. */
class AutorizacionReconocimiento extends Model
{
    use TieneAuditoria;

    protected $table = 'autorizaciones_reconocimiento';

    protected $fillable = ['clave', 'nombre'];
}
