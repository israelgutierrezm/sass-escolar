<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\SePuedeApagar;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_periodo (TENANT-CONFIG). */
class TipoPeriodo extends Model
{
    use SePuedeApagar, TieneAuditoria;

    protected $table = 'tipos_periodo';

    protected $fillable = ['clave', 'identificador', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
