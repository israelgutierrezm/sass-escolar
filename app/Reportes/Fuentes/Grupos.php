<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\Identidad\Usuario;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los GRUPOS: cuántos alumnos tiene cada uno y cuántas materias sin titular.
 *
 * ── Una fila es un GRUPO ─────────────────────────────────────────────────
 * No un alumno ni una materia. «45 grupos» son grupos; para contar alumnos está
 * la fuente de matrículas, y ahí un alumno inscrito en dos grupos NO se cuenta
 * dos veces porque el grano es otro.
 *
 * ── Nada se recalcula: el conteo de alumnos sale del SCOPE ───────────────
 * `Grupo::scopeConAlumnos()` ya decide qué cuenta como alumno de un grupo
 * —inscripciones distintas, sin las dadas de baja— y lo usa la pantalla de
 * `/escolar/grupos`. Escribir aquí otro `count` produciría dos ocupaciones
 * distintas del mismo grupo y nadie sabría cuál creer; era una consulta privada
 * dentro de un controlador hasta que se subió al modelo precisamente para esto.
 *
 * ── LA TRAMPA DEL `deleted_at` QUE NADIE FILTRA ──────────────────────────
 * `docente_asignatura_grupo` TIENE columna `deleted_at`, pero
 * `AsignaturaGrupo::docentes()` es un `belongsToMany(...)->withPivot('tipo')`
 * **sin `wherePivotNull('deleted_at')`**: la relación devuelve también las
 * asignaciones RETIRADAS. Un docente al que se le quitó la materia seguiría
 * contando como titular, y «materias sin titular» —que es una cola de trabajo—
 * diría cero cuando hay tres. Por eso las subconsultas de esta fuente lo
 * escriben explícito en vez de apoyarse en la relación.
 */
class Grupos implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'grupos';
    }

    public function titulo(): string
    {
        return 'Grupos';
    }

    public function grano(): string
    {
        return 'Una fila es un GRUPO. Los alumnos y las materias son conteos suyos, no filas: '
            .'un grupo de treinta sale UNA vez.';
    }

    public function permiso(): string
    {
        return 'ver-grupos';
    }

    public function modulo(): ?string
    {
        return null;
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /**
     * El grupo TIENE su propio `campus_id`, así que no hace falta relación.
     *
     * Y por eso NO se usa `porRelacion`: ésa deja pasar lo que no completa la
     * cadena, y aquí no hay cadena que completar.
     */
    public function recorte(): Recorte
    {
        return Recorte::porColumnaPropia('grupos.campus_id');
    }

    public function columnas(): array
    {
        return [
            'clave' => new ColumnaReporte(
                clave: 'clave',
                etiqueta: 'Clave',
                columnaSql: 'grupos.clave',
                ordenable: true,
                ancho: 14,
            ),
            'nombre' => new ColumnaReporte(
                clave: 'nombre',
                etiqueta: 'Grupo',
                columnaSql: 'grupos.nombre',
                ordenable: true,
                ancho: 26,
            ),
            'ciclo' => new ColumnaReporte(
                clave: 'ciclo',
                etiqueta: 'Ciclo',
                valor: fn (Grupo $g) => $g->ciclo?->clave,
                ancho: 14,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (Grupo $g) => $g->campus?->nombre,
                ancho: 18,
            ),
            'plan' => new ColumnaReporte(
                clave: 'plan',
                etiqueta: 'Plan',
                valor: fn (Grupo $g) => $g->plan?->nombre,
                ancho: 26,
            ),
            'periodo' => new ColumnaReporte(
                clave: 'periodo',
                etiqueta: 'Periodo',
                tipo: TipoDato::Entero,
                // La columna se llama `semestre` aunque el plan puede medir en
                // cuatrimestres o módulos: se enseña con el nombre neutro y el
                // resolutor lee la columna real.
                valor: fn (Grupo $g) => $g->semestre,
                columnaSql: 'grupos.semestre',
                ordenable: true,
                ancho: 8,
            ),
            'turno' => new ColumnaReporte(
                clave: 'turno',
                etiqueta: 'Turno',
                valor: fn (Grupo $g) => $g->turno?->nombre,
                ancho: 14,
                ayuda: 'El turno es del GRUPO, no de la oferta.',
            ),
            'situacion' => new ColumnaReporte(
                clave: 'situacion',
                etiqueta: 'Situación',
                valor: fn (Grupo $g) => $g->situacion?->nombre,
                ancho: 14,
            ),
            'cupo' => new ColumnaReporte(
                clave: 'cupo',
                etiqueta: 'Cupo',
                tipo: TipoDato::Entero,
                columnaSql: 'grupos.cupo',
                ordenable: true,
                ancho: 8,
            ),
            'alumnos' => new ColumnaReporte(
                clave: 'alumnos',
                etiqueta: 'Alumnos',
                tipo: TipoDato::Entero,
                // Del scope del modelo, que es el mismo que usa la pantalla.
                valor: fn (Grupo $g) => (int) ($g->alumnos_count ?? 0),
                columnaSql: 'alumnos_count',
                ordenable: true,
                ancho: 9,
            ),
            'ocupacion' => new ColumnaReporte(
                clave: 'ocupacion',
                etiqueta: 'Ocupación',
                tipo: TipoDato::Porcentaje,
                valor: fn (Grupo $g) => $g->cupo > 0
                    ? round(((int) ($g->alumnos_count ?? 0)) * 100 / $g->cupo, 1)
                    // Sin cupo no hay porcentaje que calcular, y un 0 % diría
                    // que está vacío cuando puede estar lleno.
                    : null,
                ancho: 11,
                ayuda: 'En blanco cuando el grupo no tiene cupo capturado: no es 0 %.',
            ),
            'materias' => new ColumnaReporte(
                clave: 'materias',
                etiqueta: 'Materias',
                tipo: TipoDato::Entero,
                valor: fn (Grupo $g) => (int) ($g->materias ?? 0),
                columnaSql: 'materias',
                ordenable: true,
                ancho: 9,
            ),
            'sin_titular' => new ColumnaReporte(
                clave: 'sin_titular',
                etiqueta: 'Sin titular',
                tipo: TipoDato::Entero,
                valor: fn (Grupo $g) => (int) ($g->sin_titular ?? 0),
                columnaSql: 'sin_titular',
                ordenable: true,
                ancho: 10,
                ayuda: 'Materias abiertas del grupo a las que no se les ha asignado docente titular. '
                    .'Es una cola de trabajo: cada una es un grupo que empieza sin quien le dé clase.',
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'ciclo_id' => new FiltroReporte(
                clave: 'ciclo_id',
                etiqueta: 'Ciclo',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('grupos.ciclo_id', $v),
                opciones: fn (Usuario $u) => Ciclo::query()
                    ->orderByDesc('id')->pluck('clave', 'id')->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('grupos.campus_id', $v),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'plan_id' => new FiltroReporte(
                clave: 'plan_id',
                etiqueta: 'Plan de estudios',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('grupos.plan_id', $v),
                opciones: fn (Usuario $u) => PlanEstudio::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'solo_sin_titular' => new FiltroReporte(
                clave: 'solo_sin_titular',
                etiqueta: 'Sólo con materias sin titular',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->having('sin_titular', '>', 0) : $q,
            ),
            'solo_vacios' => new FiltroReporte(
                clave: 'solo_vacios',
                etiqueta: 'Sólo grupos sin alumnos',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->having('alumnos_count', '=', 0) : $q,
                ayuda: 'Un grupo abierto y vacío: o no se ha inscrito nadie, o hay que cerrarlo.',
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return Grupo::query()
            /*
             * El `select` va ANTES del scope, y el orden no es cosmético.
             *
             * `scopeConAlumnos()` usa `addSelect`, así que un `select('grupos.*')`
             * puesto después lo BORRA: la columna de alumnos salía vacía en todas
             * las filas, sin un solo error. Es el mismo defecto que las tres
             * columnas de `Cargos`, por otro camino.
             */
            ->select('grupos.*')
            ->conAlumnos()
            ->with([
                'ciclo:id,clave',
                'campus:id,nombre',
                'plan:id,nombre',
                'turno:id,nombre',
                'situacion:id,nombre',
            ])
            ->selectSub(
                DB::table('asignatura_grupo as ag')
                    ->whereColumn('ag.grupo_id', 'grupos.id')
                    ->whereNull('ag.deleted_at')
                    ->selectRaw('count(*)'),
                'materias',
            )
            /*
             * Las materias SIN titular.
             *
             * El `whereNull('dag.deleted_at')` es lo que hace que esto sea
             * cierto: la relación `docentes()` del modelo no lo filtra, así que
             * una asignación RETIRADA seguiría contando como titular y esta
             * columna diría cero donde hay tres. Ver el docblock de la clase.
             */
            ->selectSub(
                DB::table('asignatura_grupo as ag2')
                    ->whereColumn('ag2.grupo_id', 'grupos.id')
                    ->whereNull('ag2.deleted_at')
                    ->whereNotExists(fn ($q) => $q
                        ->from('docente_asignatura_grupo as dag')
                        ->whereColumn('dag.asignatura_grupo_id', 'ag2.id')
                        ->whereNull('dag.deleted_at')
                        ->where('dag.tipo', 'titular'))
                    ->selectRaw('count(*)'),
                'sin_titular',
            );
    }

    public function llavePrimaria(): string
    {
        return 'grupos.id';
    }
}
