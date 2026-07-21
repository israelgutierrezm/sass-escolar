<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_campus (TENANT-CONFIG). */
class TipoCampus extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_campus';

    protected $fillable = ['clave', 'nombre'];
}
