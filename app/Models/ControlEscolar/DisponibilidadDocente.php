<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * disponibilidad_docente — cuándo puede dar clase un docente.
 *
 * Ver la migración para el porqué del modelo. En resumen: sin `ciclo_id` es su
 * disponibilidad habitual; con ciclo, la de ese periodo, y esa REEMPLAZA a la
 * habitual en vez de sumarse.
 */
class DisponibilidadDocente extends Model
{
    use TieneAuditoria;

    protected $table = 'disponibilidad_docente';

    /** Dónde puede dar la clase. */
    public const PRESENCIAL = 'presencial';

    public const EN_LINEA = 'en_linea';

    public const AMBAS = 'ambas';

    public const MODALIDADES = [self::PRESENCIAL, self::EN_LINEA, self::AMBAS];

    protected $fillable = [
        'persona_id',
        'ciclo_id',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'modalidad',
        'nota',
    ];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer'];
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    /**
     * La disponibilidad que vale para un ciclo: la suya si la declaró, y si no,
     * la habitual.
     *
     * Es LA regla del modelo y vive aquí para que nadie la reimplemente: quien
     * consulte disponibilidad sin pasar por este método verá la plantilla de un
     * docente que ya declaró horarios distintos para el periodo, y armará el
     * horario con datos viejos sin que nada avise.
     *
     * @return Collection<int, self>
     */
    public static function paraElCiclo(int $cicloId, ?array $personaIds = null): Collection
    {
        $delCiclo = static::query()
            ->where('ciclo_id', $cicloId)
            ->when($personaIds !== null, fn (Builder $q) => $q->whereIn('persona_id', $personaIds))
            ->get();

        // Quien ya se pronunció sobre este ciclo no vuelve a mirar su plantilla.
        $yaDeclararon = $delCiclo->pluck('persona_id')->unique();

        $habituales = static::query()
            ->whereNull('ciclo_id')
            ->whereNotIn('persona_id', $yaDeclararon)
            ->when($personaIds !== null, fn (Builder $q) => $q->whereIn('persona_id', $personaIds))
            ->get();

        return $delCiclo->concat($habituales)->values();
    }

    /** ¿Esta franja sirve para una clase de la modalidad pedida? */
    public function admite(string $modalidad): bool
    {
        return $this->modalidad === self::AMBAS || $this->modalidad === $modalidad;
    }

    /**
     * Minutos desde la medianoche, para comparar sin pelearse con los formatos.
     *
     * `hora_inicio` llega como string de MySQL («07:00:00») o como lo que haya
     * tecleado un formulario («7:00»); compararlas como texto funciona hasta
     * que alguien escribe una sin el cero de la izquierda.
     */
    public function inicioEnMinutos(): int
    {
        return self::aMinutos((string) $this->hora_inicio);
    }

    public function finEnMinutos(): int
    {
        return self::aMinutos((string) $this->hora_fin);
    }

    public static function aMinutos(string $hora): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $hora)), 2, 0);

        return $h * 60 + $m;
    }
}
