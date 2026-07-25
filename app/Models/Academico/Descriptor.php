<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * descriptores (TENANT-CONFIG) — los apartados que puede tener el programa de
 * una asignatura (Bienvenida, Contenido temático…). Se seleccionan por
 * asignatura; el catálogo admite más.
 */
class Descriptor extends Model
{
    use TieneAuditoria;

    protected $table = 'descriptores';

    protected $fillable = ['clave', 'nombre'];
}
