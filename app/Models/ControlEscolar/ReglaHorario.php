<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * reglas_horario — con qué criterios se arma un horario.
 *
 * Ver la migración para el porqué de cada campo.
 */
class ReglaHorario extends Model
{
    use TieneAuditoria;

    protected $table = 'reglas_horario';

    /** Cómo se reparte una materia entre quienes pueden darla. */
    public const CONCENTRAR = 'concentrar';

    public const REPARTIR = 'repartir';

    public const REPARTOS = [self::CONCENTRAR, self::REPARTIR];

    protected $fillable = [
        'nombre',
        'ciclo_id',
        'campus_id',
        'dias',
        'hora_apertura',
        'hora_cierre',
        'minutos_bloque',
        'bloques_min_por_sesion',
        'bloques_max_por_sesion',
        'max_sesiones_por_dia',
        'horas_max_dia_docente',
        'horas_max_semana_docente',
        'minutos_descanso_docente',
        'reparto',
        'permite_huecos_grupo',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'dias' => 'array',
            'minutos_bloque' => 'integer',
            'bloques_min_por_sesion' => 'integer',
            'bloques_max_por_sesion' => 'integer',
            'max_sesiones_por_dia' => 'integer',
            'horas_max_dia_docente' => 'integer',
            'horas_max_semana_docente' => 'integer',
            'minutos_descanso_docente' => 'integer',
            'permite_huecos_grupo' => 'boolean',
            'activa' => 'boolean',
        ];
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    /**
     * La regla que aplica, de la más específica a la más general.
     *
     * Ciclo + campus gana a campus, que gana a ciclo, que gana a la base. Es el
     * mismo orden de los planes de cobro: la escuela define una y sólo escribe
     * las excepciones que de verdad tiene.
     *
     * Devuelve `null` cuando no hay ninguna configurada, y eso NO es un error:
     * significa que esta escuela no usa la generación de horarios. Quien
     * pregunte tiene que saber contestar «no está configurado» sin romperse,
     * porque la funcionalidad es opcional por diseño.
     */
    public static function resolver(?int $cicloId, ?int $campusId): ?self
    {
        $candidatas = static::query()
            ->where('activa', true)
            ->where(fn ($q) => $q->whereNull('ciclo_id')->orWhere('ciclo_id', $cicloId))
            ->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $campusId))
            ->get();

        // La más específica primero: cuenta cuántos de los dos ejes fija.
        return $candidatas
            ->sortByDesc(fn (self $r) => ($r->ciclo_id !== null ? 2 : 0) + ($r->campus_id !== null ? 1 : 0))
            ->first();
    }

    /** Cuántos minutos dura la jornada, para saber cuántos bloques caben. */
    public function minutosDeJornada(): int
    {
        return self::minutosEntre((string) $this->hora_apertura, (string) $this->hora_cierre);
    }

    /**
     * Lo mismo para una jornada que TODAVÍA no se guarda.
     *
     * La pantalla necesita avisar de una jornada imposible antes de crear la
     * regla, y para eso no hay modelo sobre el que preguntar.
     */
    public static function minutosEntre(string $apertura, string $cierre): int
    {
        return DisponibilidadDocente::aMinutos($cierre) - DisponibilidadDocente::aMinutos($apertura);
    }

    /** Los bloques en que se corta el día, como minutos desde medianoche. */
    public function bloquesDelDia(): array
    {
        $inicio = DisponibilidadDocente::aMinutos((string) $this->hora_apertura);
        $fin = DisponibilidadDocente::aMinutos((string) $this->hora_cierre);

        $bloques = [];

        for ($m = $inicio; $m + $this->minutos_bloque <= $fin; $m += $this->minutos_bloque) {
            $bloques[] = $m;
        }

        return $bloques;
    }

    /** @return array<int, int> los días con clase, 1 = lunes */
    public function diasLaborales(): array
    {
        return array_values(array_map('intval', $this->dias ?? []));
    }
}
