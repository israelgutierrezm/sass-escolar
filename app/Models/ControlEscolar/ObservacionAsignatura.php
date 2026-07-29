<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * observaciones_asignatura (TENANT-CONFIG) — `cat_observacion_asignatura` de la
 * SEP: el estatus académico con el que se cursó/cargó una asignatura en el
 * historial (equivalencia, extraordinario, revalidación, normal/ordinario…).
 * Catálogo oficial con ids fijos (70–104); `protegido`.
 */
class ObservacionAsignatura extends Model
{
    use TieneAuditoria;

    protected $table = 'observaciones_asignatura';

    protected $fillable = ['clave', 'nombre', 'abreviatura', 'protegido'];

    protected function casts(): array
    {
        return ['protegido' => 'boolean'];
    }
}
