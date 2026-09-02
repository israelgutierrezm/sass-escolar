<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * estados_cuenta_bancaria (TENANT) — un periodo del banco, ya importado.
 *
 * Los dos saldos son la prueba de que el archivo está COMPLETO: inicial más la
 * suma de los movimientos tiene que dar el final. Sin ese cuadre, un CSV
 * cortado a la mitad concilia impecable y esconde el resto del mes.
 */
class EstadoCuentaBancaria extends Model
{
    use TieneAuditoria;

    protected $table = 'estados_cuenta_bancaria';

    protected $fillable = [
        'cuenta_bancaria_id',
        'periodo_inicio',
        'periodo_fin',
        'saldo_inicial',
        'saldo_final',
        'movimientos',
        'archivo_ruta',
        'archivo_nombre',
    ];

    protected function casts(): array
    {
        return [
            'periodo_inicio' => 'date',
            'periodo_fin' => 'date',
            'saldo_inicial' => 'decimal:2',
            'saldo_final' => 'decimal:2',
            'movimientos' => 'integer',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function renglones(): HasMany
    {
        return $this->hasMany(MovimientoBancario::class, 'estado_cuenta_id');
    }

    /** Lo que el banco dice que se movió: la suma con signo de sus renglones. */
    public function neto(): float
    {
        return round((float) $this->renglones()->sum('monto'), 2);
    }

    /**
     * Lo que falta para que el periodo cuadre.
     *
     * Cero es que el archivo está completo. Se calcula al mirarlo y no se
     * guarda: al revés que un cierre fiscal, aquí lo que se afirma no es una
     * cifra sino que los renglones importados son TODOS, y eso hay que poder
     * volver a comprobarlo si alguien borra uno.
     */
    public function descuadre(): float
    {
        return round((float) $this->saldo_inicial + $this->neto() - (float) $this->saldo_final, 2);
    }

    public function cuadra(): bool
    {
        return abs($this->descuadre()) < 0.005;
    }
}
