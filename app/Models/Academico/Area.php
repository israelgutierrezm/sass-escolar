<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** areas (TENANT-CONFIG) — áreas del conocimiento / academias. */
class Area extends Model
{
    use TieneAuditoria;

    protected $table = 'areas';

    protected $fillable = ['clave', 'nombre'];
}
