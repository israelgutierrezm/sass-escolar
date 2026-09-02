<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * depositos_caja (TENANT) — el efectivo del cajón, ya en el banco.
 *
 * Junta los turnos que se llevaron juntos a la sucursal, que es como se hace:
 * al final del día va todo en una sola ficha.
 */
class DepositoCaja extends Model
{
    use TieneAuditoria;

    protected $table = 'depositos_caja';

    protected $fillable = ['cuenta_bancaria_id', 'monto', 'fecha', 'referencia', 'notas'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'fecha' => 'date'];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionCaja::class, 'deposito_caja_id');
    }
}
