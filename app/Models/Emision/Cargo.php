<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** cargos (TENANT-CONFIG) — puesto del responsable. Catálogo oficial protegido. */
class Cargo extends Model
{
    use TieneAuditoria;

    protected $table = 'cargos';

    protected $fillable = ['clave', 'nombre', 'protegido'];

    protected function casts(): array
    {
        return ['protegido' => 'boolean'];
    }
}
