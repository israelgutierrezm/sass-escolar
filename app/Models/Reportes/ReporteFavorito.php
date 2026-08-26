<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * Un reporte marcado por alguien para tenerlo a mano.
 *
 * Es de la PERSONA y no del rol: quien conmuta de rol sigue siendo la misma
 * persona con las mismas costumbres.
 */
class ReporteFavorito extends Model
{
    use TieneAuditoria;

    protected $table = 'reportes_favoritos';

    protected $fillable = ['reporte', 'persona_id'];
}
