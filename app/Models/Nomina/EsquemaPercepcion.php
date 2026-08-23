<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * esquemas_percepcion (TENANT) — cuánto se le paga a un expediente, y desde
 * cuándo.
 *
 * ── Uno solo abierto por expediente ───────────────────────────────────────
 * Con dos, «cuánto gana hoy» tendría dos respuestas y la nómina tomaría la que
 * saliera primero. Lo sostiene `RegistroPercepciones`, que al abrir uno cierra
 * el anterior el día antes.
 *
 * ── Y el anterior se conserva ─────────────────────────────────────────────
 * Un aumento no borra lo que ganaba antes. Es lo que permite explicar un recibo
 * viejo sin adivinar.
 *
 * ── Los componentes que la modalidad no usa quedan en NULL ────────────────
 * Un cero diría «se le paga cero por hora», que es una afirmación distinta de
 * «a esta persona no se le paga por hora».
 */
class EsquemaPercepcion extends Model
{
    use TieneAuditoria;

    protected $table = 'esquemas_percepcion';

    protected $fillable = [
        'expediente_laboral_id',
        'modalidad_id',
        'monto_base',
        'tarifa_hora',
        'tarifa_asignatura',
        'vigente_desde',
        'vigente_hasta',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'monto_base' => 'decimal:2',
            'tarifa_hora' => 'decimal:2',
            'tarifa_asignatura' => 'decimal:2',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteLaboral::class, 'expediente_laboral_id');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadPercepcion::class, 'modalidad_id');
    }

    /** El que no se ha cerrado. */
    public function estaAbierto(): bool
    {
        return $this->vigente_hasta === null;
    }

    public function scopeAbiertos(Builder $consulta): Builder
    {
        return $consulta->whereNull('vigente_hasta');
    }

    /**
     * Los que cubren una fecha.
     *
     * Se compara con la fecha y no con «hoy» porque la nómina calcula periodos
     * pasados: un recibo de la quincena anterior tiene que usar el sueldo que
     * regía ENTONCES, no el de ahora.
     */
    public function scopeVigentesEn(Builder $consulta, string $fecha): Builder
    {
        return $consulta
            ->whereDate('vigente_desde', '<=', $fecha)
            ->where(fn (Builder $q) => $q
                ->whereNull('vigente_hasta')
                ->orWhereDate('vigente_hasta', '>=', $fecha));
    }
}
