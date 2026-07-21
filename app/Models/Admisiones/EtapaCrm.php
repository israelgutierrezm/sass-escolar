<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** etapas_crm (TENANT-CONFIG) — embudo de admisión. */
class EtapaCrm extends Model
{
    use TieneAuditoria;

    protected $table = 'etapas_crm';

    protected $fillable = ['clave', 'nombre', 'orden'];
}
