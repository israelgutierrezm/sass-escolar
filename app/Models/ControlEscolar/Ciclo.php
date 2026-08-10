<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Campus;
use App\Models\Academico\NivelEstudio;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ciclos (TENANT) — periodo escolar. `campus_id` NULL = ciclo global.
 */
class Ciclo extends Model
{
    use TieneAuditoria;

    protected $table = 'ciclos';

    protected $fillable = [
        'clave',
        'anio',
        'numero_periodo',
        'nombre',
        'fecha_inicio',
        'fecha_fin',
        'situacion_id',
        'inscripcion_desde',
        'inscripcion_hasta',
        'altas_bajas_hasta',
        'captura_calif_hasta',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'inscripcion_desde' => 'date',
            'inscripcion_hasta' => 'date',
            'altas_bajas_hasta' => 'date',
            'captura_calif_hasta' => 'date',
        ];
    }

    /** Campus donde aplica el ciclo. Vacío = ciclo global de la escuela. */
    public function campus(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'ciclo_campus', 'ciclo_id', 'campus_id')
            ->withTimestamps();
    }

    public function esGlobal(): bool
    {
        return $this->campus()->doesntExist();
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionCiclo::class, 'situacion_id');
    }

    /**
     * Niveles de estudio a los que se acota el ciclo (opcional). Si hay alguno,
     * los grupos del ciclo solo pueden ser de esos niveles. Vacío = cualquier
     * nivel.
     */
    public function niveles(): BelongsToMany
    {
        return $this->belongsToMany(NivelEstudio::class, 'ciclo_nivel', 'ciclo_id', 'nivel_estudios_id')
            ->withTimestamps();
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class, 'ciclo_id');
    }

    /** Ciclos del campus dado más los globales (sin campus asignado). */
    public function scopeParaCampus(Builder $query, int $campusId): Builder
    {
        return $query->where(fn ($q) => $q
            ->whereHas('campus', fn ($c) => $c->where('campus.id', $campusId))
            ->orWhereDoesntHave('campus'));
    }

    /**
     * Ciclos visibles para un alcance de campus. `null` = alcance global (los
     * ve todos); un arreglo acota a esos campus más los ciclos globales, que
     * son de la escuela entera y por tanto de todos.
     *
     * @param  array<int, int>|null  $campusIds
     */
    public function scopeDelAlcance(Builder $query, ?array $campusIds): Builder
    {
        if ($campusIds === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->whereHas('campus', fn ($c) => $c->whereIn('campus.id', $campusIds))
            ->orWhereDoesntHave('campus'));
    }

    /**
     * Los ciclos con los que todavía se trabaja: todos menos los cerrados.
     *
     * Una escuela con años de historia acumula veinte ciclos y sólo uno o dos
     * están vivos. Ofrecerlos todos convierte cada tarea diaria —capturar
     * calificaciones, inscribir, asignar tutorías— en elegir entre veintiuna
     * opciones donde sólo una tiene sentido, y pone «2016-1» a un clic de
     * distancia de la que se buscaba.
     *
     * Los planeados y los en curso SÍ se ofrecen: preparar el semestre que viene
     * es trabajo normal. Lo que se retira es lo que ya rindió sus actas.
     *
     * @param  int|null  $conservar  Un ciclo que se muestra aunque esté cerrado.
     *                               Es para las pantallas de edición: si el
     *                               registro que se edita apunta a un ciclo
     *                               viejo y éste desaparece del desplegable,
     *                               guardar lo movería a otro.
     */
    public function scopeVigentes(Builder $query, ?int $conservar = null): Builder
    {
        $cerrado = SituacionCiclo::query()->where('clave', 'cerrado')->value('id');

        // Sin catálogo sembrado no hay nada que excluir: se prefiere mostrar de
        // más a dejar el desplegable vacío y la pantalla inutilizable.
        if ($cerrado === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where('situacion_id', '!=', $cerrado)
            ->orWhereNull('situacion_id')
            ->when($conservar !== null, fn ($sub) => $sub->orWhere('id', $conservar)));
    }

    /**
     * El ciclo con el que se está trabajando hoy, para preseleccionarlo.
     *
     * Se busca por FECHA y no por situación: la situación la mueve una persona a
     * mano y se queda como quedó —en `demo` hay veinte «cerrados» y uno
     * «abierto» que ya terminó—, mientras que las fechas del ciclo son el dato
     * que la escuela sí mantiene. Si hoy no cae dentro de ninguno (vacaciones,
     * entre semestres), se toma el más próximo por empezar, y si tampoco hay, el
     * último que corrió.
     */
    public static function enCurso(): ?self
    {
        $hoy = now()->toDateString();

        return static::query()->vigentes()
            ->where('fecha_inicio', '<=', $hoy)
            ->where('fecha_fin', '>=', $hoy)
            ->orderBy('fecha_inicio')
            ->first()
            ?? static::query()->vigentes()
                ->where('fecha_inicio', '>', $hoy)
                ->orderBy('fecha_inicio')
                ->first()
            ?? static::query()->vigentes()->orderByDesc('fecha_inicio')->first();
    }

    /** ¿La ventana de inscripción está abierta en la fecha dada? */
    public function inscripcionAbierta(?string $fecha = null): bool
    {
        // Sin fechas configuradas la ventana NO está habilitada: no restringe,
        // así que la inscripción está abierta. La restricción solo aplica cuando
        // se capturan las fechas.
        if ($this->inscripcion_desde === null || $this->inscripcion_hasta === null) {
            return true;
        }

        $fecha = $fecha ?? now()->toDateString();

        return $fecha >= $this->inscripcion_desde->toDateString()
            && $fecha <= $this->inscripcion_hasta->toDateString();
    }
}
