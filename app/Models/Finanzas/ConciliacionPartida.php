<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * conciliacion_partidas (TENANT) — este renglón del banco es este movimiento
 * nuestro.
 *
 * Es lo ÚNICO que la conciliación escribe sobre el mundo. No toca el pago ni el
 * adeudo: el insumo es un CSV que cualquiera edita en su máquina, y si de él
 * dependiera el estatus de un cobro, un archivo retocado movería lo que un
 * alumno debe.
 */
class ConciliacionPartida extends Model
{
    use TieneAuditoria;

    protected $table = 'conciliacion_partidas';

    protected $fillable = [
        'movimiento_bancario_id',
        'pago_id',
        'deposito_caja_id',
        'monto_aplicado',
        'automatica',
    ];

    protected function casts(): array
    {
        return ['monto_aplicado' => 'decimal:2', 'automatica' => 'boolean'];
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'movimiento_bancario_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(DepositoCaja::class, 'deposito_caja_id');
    }

    /** Qué se concilió, para decirlo en pantalla sin repetir el `if` en cada sitio. */
    public function descripcion(): string
    {
        if ($this->pago_id !== null) {
            return 'Pago #'.$this->pago_id.($this->pago?->referencia ? " · {$this->pago->referencia}" : '');
        }

        return 'Depósito #'.$this->deposito_caja_id.($this->deposito?->referencia ? " · {$this->deposito->referencia}" : '');
    }
}
