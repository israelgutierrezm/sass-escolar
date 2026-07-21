<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_periodo (TENANT-CONFIG). */
class TipoPeriodo extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_periodo';

    protected $fillable = ['clave', 'nombre'];
}
