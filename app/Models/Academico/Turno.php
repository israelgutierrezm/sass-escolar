<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** turnos (TENANT-CONFIG) — matutino, vespertino, mixto, sabatino. */
class Turno extends Model
{
    use TieneAuditoria;

    protected $table = 'turnos';

    protected $fillable = ['clave', 'nombre'];
}
