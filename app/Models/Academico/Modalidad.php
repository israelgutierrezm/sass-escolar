<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** modalidades (TENANT-CONFIG) — presencial, en línea, mixta. */
class Modalidad extends Model
{
    use TieneAuditoria;

    protected $table = 'modalidades';

    protected $fillable = ['clave', 'nombre'];
}
