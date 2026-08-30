<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Campus;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\Turno;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * grupos (TENANT) — contenedor de materias en un ciclo.
 */
class Grupo extends Model
{
    use TieneAuditoria;

    protected $table = 'grupos';

    protected $fillable = [
        'ciclo_id',
        'campus_id',
        'nivel_estudios_id',
        'plan_id',
        'semestre',
        'clave',
        'nombre',
        'cupo',
        'turno_id',
        'situacion_id',
        'grupo_origen_id',
    ];

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_id');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionGrupo::class, 'situacion_id');
    }

    /** Grupo del que se clonó éste, si aplica. */
    public function grupoOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'grupo_origen_id');
    }

    /**
     * Cuántos alumnos DISTINTOS trae el grupo, en `alumnos_count`.
     *
     * Tres decisiones de dominio metidas en una subconsulta, y por eso vive
     * aquí y no copiada en cada pantalla:
     *
     *  1. Se cuentan **matrículas distintas**, no renglones de `inscripcion`:
     *     un alumno cursando seis materias del grupo es UN alumno, y es lo que
     *     se compara contra el cupo. Contando filas, tres alumnos con seis
     *     materias darían diecisiete.
     *  2. Se cuenta por matrícula y no por persona, porque **el alumno es la
     *     matrícula**: quien lleva dos programas académicos ocupa dos lugares.
     *  3. Las **bajas no ocupan lugar**, tolerando la situación en null —una
     *     escuela con el catálogo a medias no debe perder a sus inscritos—.
     *
     * Estaba escrita dentro de `GrupoController::index` y la necesitó también
     * la tarjeta de ocupación del panel. Dos copias del mismo criterio es como
     * se llega a que el panel diga 3 y la pantalla de grupos diga 17.
     */
    public function scopeConAlumnos(Builder $consulta): Builder
    {
        return $consulta->addSelect([
            'alumnos_count' => self::inscritosDelGrupo()
                ->selectRaw('COUNT(DISTINCT inscripcion.matricula_oferta_id)')
                ->whereColumn('asignatura_grupo.grupo_id', 'grupos.id'),
        ]);
    }

    /**
     * El MISMO conteo, pero AGRUPADO para poder unirlo como tabla derivada.
     *
     * ── Por qué hacen falta las dos formas ────────────────────────────────
     * `scopeConAlumnos()` mete el conteo con un `addSelect` correlacionado, y
     * eso produce un ALIAS. MySQL acepta un alias en el `ORDER BY` y **no lo
     * acepta en el `WHERE`**, así que un reporte ordenado por esa columna
     * ordena bien en la pantalla y revienta al exportar —el recorrido por lotes
     * avanza con un `WHERE` sobre la columna de orden—. Con un `leftJoinSub`,
     * `alumnos.cuantos` es una columna calificada de verdad y sirve para las dos
     * cosas.
     *
     * El CRITERIO no se duplica: las dos formas salen de
     * {@see inscritosDelGrupo()}, que es donde vive «qué cuenta como alumno de
     * un grupo». Lo que cambia es la forma de pegarlo a la consulta.
     */
    public static function conteoDeAlumnosAgrupado(): Builder
    {
        return self::inscritosDelGrupo()
            ->select('asignatura_grupo.grupo_id')
            ->selectRaw('COUNT(DISTINCT inscripcion.matricula_oferta_id) as cuantos')
            ->groupBy('asignatura_grupo.grupo_id');
    }

    /**
     * QUÉ CUENTA como alumno de un grupo. La única declaración.
     *
     *  1. Se cuenta por matrícula y no por persona, porque **el alumno es la
     *     matrícula**: quien lleva dos programas académicos ocupa dos lugares.
     *  2. Las **bajas no ocupan lugar**, tolerando la situación en null —una
     *     escuela con el catálogo a medias no debe perder a sus inscritos—.
     */
    private static function inscritosDelGrupo(): Builder
    {
        return Inscripcion::query()
            ->join('asignatura_grupo', 'asignatura_grupo.id', '=', 'inscripcion.asignatura_grupo_id')
            ->leftJoin('situaciones_inscripcion', 'situaciones_inscripcion.id', '=', 'inscripcion.situacion_id')
            ->where(fn ($q) => $q->whereNull('situaciones_inscripcion.clave')
                ->orWhere('situaciones_inscripcion.clave', '!=', 'baja'));
    }

    public function asignaturas(): HasMany
    {
        return $this->hasMany(AsignaturaGrupo::class, 'grupo_id');
    }
}
