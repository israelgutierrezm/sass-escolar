<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_campo (TENANT-CONFIG). */
class TipoCampo extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_campo';

    protected $fillable = ['clave', 'nombre'];
}
