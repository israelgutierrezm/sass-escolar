<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * presupuestos (TENANT) — cuánto se autorizó gastar en un cruce.
 *
 * Una sola cifra por (centro de costo, partida, ciclo): con dos, «cuánto se
 * autorizó» no tendría respuesta y el disponible dependería de cuál se mirara.
 */
class Presupuesto extends Model
{
    use TieneAuditoria;

    protected $table = 'presupuestos';

    protected $fillable = ['centro_costo_id', 'partida_id', 'ciclo_id', 'monto', 'notas'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function centro(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function partida(): BelongsTo
    {
        return $this->belongsTo(PartidaPresupuesto::class, 'partida_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }
}
