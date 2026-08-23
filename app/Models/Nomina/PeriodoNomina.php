<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * periodos_nomina (TENANT) — el corte que se paga.
 *
 * ── Tres estados, y cada uno habilita otra cosa ───────────────────────────
 * ABIERTO se calcula y se recalcula; CALCULADO ya tiene recibos y admite
 * ajustes a mano; CERRADO ya se pagó y no se toca. Son columna y no catálogo
 * porque cada uno abre gestos distintos en el código: una fila nueva no haría
 * nada.
 *
 * ── El timbrado NO está aquí ──────────────────────────────────────────────
 * Va a ser una propiedad de cada RECIBO. El SAT puede rechazar uno y aceptar los
 * otros cuarenta, igual que la SEP con los títulos de un lote; un estado de
 * periodo obligaría a elegir entre mentir o bloquear a todos.
 *
 * ── `campus_id` en null significa TODA la escuela ─────────────────────────
 * Es el caso más común —una nómina, todos— y se resuelve no diciendo nada en
 * vez de obligando a marcar la lista entera. Con campus, sólo entra quien está
 * adscrito ahí.
 */
class PeriodoNomina extends Model
{
    use TieneAuditoria;

    public const ABIERTO = 'abierto';

    public const CALCULADO = 'calculado';

    public const CERRADO = 'cerrado';

    protected $table = 'periodos_nomina';

    protected $fillable = [
        'nombre',
        'fecha_inicio',
        'fecha_fin',
        'fecha_pago',
        'campus_id',
        'estado',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_pago' => 'date',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function recibos(): HasMany
    {
        return $this->hasMany(ReciboNomina::class, 'periodo_nomina_id');
    }

    /** Mientras no esté cerrado se puede calcular y ajustar. */
    public function sePuedeTocar(): bool
    {
        return $this->estado !== self::CERRADO;
    }

    public function estaCerrado(): bool
    {
        return $this->estado === self::CERRADO;
    }

    /**
     * Otros periodos cuyo rango se encima con el de éste.
     *
     * No se prohíben —una quincena y un aguinaldo extraordinario se enciman de
     * forma legítima— pero cuentan las MISMAS checadas, así que la pantalla lo
     * advierte en vez de dejar que se descubra en el importe.
     */
    public function scopeQueSeEncimanCon(Builder $consulta, string $inicio, string $fin): Builder
    {
        return $consulta
            ->whereDate('fecha_inicio', '<=', $fin)
            ->whereDate('fecha_fin', '>=', $inicio);
    }
}
