<?php

declare(strict_types=1);

namespace App\Models\Familia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * citas_familia_docente (TENANT) — una cita entre una familia y un docente.
 *
 * Ver `docs/plan-citas-familia-docente.md`. La familia SOLICITA, el docente
 * CONFIRMA o RECHAZA, y no se encima con otra confirmada. `inicio`/`fin` los fija
 * el servidor de la duración de la ventana; nunca se creen del cliente.
 */
class Cita extends Model
{
    use TieneAuditoria;

    protected $table = 'citas_familia_docente';

    public const SOLICITADA = 'solicitada';

    public const CONFIRMADA = 'confirmada';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    public const REALIZADA = 'realizada';

    public const NO_ASISTIO = 'no_asistio';

    /** Los estados en que la cita sigue «viva» y ocupa la agenda. */
    public const ACTIVOS = [self::SOLICITADA, self::CONFIRMADA];

    public const ETIQUETAS = [
        self::SOLICITADA => 'Solicitada',
        self::CONFIRMADA => 'Confirmada',
        self::RECHAZADA => 'Rechazada',
        self::CANCELADA => 'Cancelada',
        self::REALIZADA => 'Realizada',
        self::NO_ASISTIO => 'No asistió',
    ];

    protected $fillable = [
        'docente_persona_id',
        'alumno_persona_id',
        'solicitante_persona_id',
        'inicio',
        'fin',
        'modalidad',
        'motivo',
        'lugar',
        'estado',
        'respuesta',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
        ];
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'docente_persona_id');
    }

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'alumno_persona_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'solicitante_persona_id');
    }

    /** ¿Sigue viva (solicitada o confirmada)? */
    public function estaActiva(): bool
    {
        return in_array($this->estado, self::ACTIVOS, true);
    }

    /** ¿Su hora ya pasó? */
    public function yaPaso(): bool
    {
        return $this->inicio !== null && $this->inicio->isPast();
    }

    /** @param  Builder<self>  $q */
    public function scopeConfirmadas(Builder $q): Builder
    {
        return $q->where('estado', self::CONFIRMADA);
    }

    /**
     * Citas del docente que se ENCIMAN con [$inicio, $fin): empieza antes de que
     * la otra acabe Y acaba después de que la otra empiece. Las dos condiciones,
     * porque una contenida dentro de otra no comparte extremos y choca igual.
     *
     * @param  Builder<self>  $q
     */
    public function scopeTraslapa(Builder $q, Carbon $inicio, Carbon $fin): Builder
    {
        return $q->where('inicio', '<', $fin)->where('fin', '>', $inicio);
    }
}
