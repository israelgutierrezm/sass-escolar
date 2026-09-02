<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * devoluciones_caja (TENANT) — dinero que salió del cajón.
 *
 * Cuelga del turno donde salió, que no tiene por qué ser aquel donde entró:
 * devolver hoy un pago de la semana pasada saca dinero de la caja de HOY.
 */
class DevolucionCaja extends Model
{
    use TieneAuditoria;

    protected $table = 'devoluciones_caja';

    protected $fillable = ['sesion_caja_id', 'pago_id', 'monto', 'motivo'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
