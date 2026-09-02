<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * presupuestos_beca (TENANT) — cuánto hay para becar de cada bolsa, por ciclo.
 *
 * Guarda SÓLO el monto asignado. Lo ejercido no se guarda: sale de sumar los
 * ajustes que las becas de ese patrocinador aplicaron de verdad, así que es un
 * hecho y no un total que hay que mantener sincronizado —y que el día que se
 * desincronice nadie sabría contra qué comparar—.
 */
class PresupuestoBeca extends Model
{
    use TieneAuditoria;

    protected $table = 'presupuestos_beca';

    protected $fillable = ['patrocinador_id', 'ciclo_id', 'monto', 'notas'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function patrocinador(): BelongsTo
    {
        return $this->belongsTo(Patrocinador::class, 'patrocinador_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }
}
