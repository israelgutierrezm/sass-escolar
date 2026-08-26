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
 * ── POR QUÉ EL `deleted_at` SE ESCRIBE EXPLÍCITO ─────────────────────────
 * Una asignación docente se RETIRA con baja lógica desde la migración
 * `2026_08_26_090000` —antes se borraba, y de quien dio una materia medio
 * semestre no quedaba rastro—. `AsignaturaGrupo::docentes()` ya filtra lo
 * retirado, pero estas subconsultas van contra la tabla en crudo y por ahí no
 * pasa ninguna relación: sin el `whereNull`, un docente al que se le quitó la
 * materia seguiría contando como titular y «materias sin titular» —que es una
 * cola de trabajo— diría cero cuando hay tres.
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
                valor: fn (Grupo $g) => (int) ($g->cuantos ?? 0),
                // Del JOIN, no del alias del scope: un alias no se puede poner
                // en el `WHERE` del recorrido por lotes y la exportación
                // reventaría. Ver `Grupo::conteoDeAlumnosAgrupado()`.
                columnaSql: 'al.cuantos',
                ordenable: true,
                ancho: 9,
            ),
            'ocupacion' => new ColumnaReporte(
                clave: 'ocupacion',
                etiqueta: 'Ocupación',
                tipo: TipoDato::Porcentaje,
                valor: fn (Grupo $g) => $g->cupo > 0
                    ? round(((int) ($g->cuantos ?? 0)) * 100 / $g->cupo, 1)
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
                valor: fn (Grupo $g) => (int) ($g->cuantas ?? 0),
                columnaSql: 'mat.cuantas',
                ordenable: true,
                ancho: 9,
            ),
            'sin_titular' => new ColumnaReporte(
                clave: 'sin_titular',
                etiqueta: 'Sin titular',
                tipo: TipoDato::Entero,
                valor: fn (Grupo $g) => (int) ($g->sin_titular ?? 0),
                columnaSql: 'mat.sin_titular',
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
                aplicar: fn (Builder $q, bool $v) => $v ? $q->where('mat.sin_titular', '>', 0) : $q,
            ),
            'solo_vacios' => new FiltroReporte(
                clave: 'solo_vacios',
                etiqueta: 'Sólo grupos sin alumnos',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->where(fn (Builder $x) => $x
                    ->whereNull('al.cuantos')->orWhere('al.cuantos', '=', 0)) : $q,
                ayuda: 'Un grupo abierto y vacío: o no se ha inscrito nadie, o hay que cerrarlo.',
            ),
        ];
    }

    /**
     * Los agregados entran por JOIN, no por subconsulta correlacionada.
     *
     * Y no es preferencia: una columna que sale de un `selectSub` es un ALIAS, y
     * MySQL acepta un alias en el `ORDER BY` pero NO en el `WHERE`. El
     * recorrido por lotes de la exportación avanza con un `WHERE` sobre la
     * columna de orden, así que ordenar por «Alumnos» funcionaba en la pantalla
     * y reventaba al pulsar «Excel» con «Unknown column 'alumnos_count' in
     * 'where clause'». Con `leftJoinSub`, `al.cuantos` es una columna de verdad.
     *
     * Los dos JOIN son a subconsultas ya AGRUPADAS por grupo, así que no
     * multiplican filas: hay a lo sumo una por grupo. Es el mismo patrón que
     * {@see Cartera} usa con `SaldosDeCartera::porMatricula()`.
     */
    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return Grupo::query()
            ->select('grupos.*')
            ->with([
                'ciclo:id,clave',
                'campus:id,nombre',
                'plan:id,nombre',
                'turno:id,nombre',
                'situacion:id,nombre',
            ])
            // El conteo de alumnos sale del MODELO, que es donde vive el
            // criterio que comparten la pantalla de grupos y el panel.
            ->leftJoinSub(Grupo::conteoDeAlumnosAgrupado(), 'al', 'al.grupo_id', '=', 'grupos.id')
            /*
             * Las materias del grupo y cuántas están sin titular, en UNA sola
             * subconsulta: son la misma tabla y separarlas costaría dos barridos
             * de `asignatura_grupo` para contestar dos preguntas del mismo sitio.
             *
             * El `dag.deleted_at is null` es lo que hace cierto el conteo: esto
             * va contra la tabla en crudo, así que no hereda el filtro de la
             * relación y una asignación RETIRADA contaría como titular.
             */
            ->leftJoinSub(
                DB::table('asignatura_grupo as ag')
                    ->whereNull('ag.deleted_at')
                    ->select('ag.grupo_id')
                    ->selectRaw('count(*) as cuantas')
                    ->selectRaw('sum(case when exists (
                        select 1 from docente_asignatura_grupo dag
                        where dag.asignatura_grupo_id = ag.id
                          and dag.deleted_at is null
                          and dag.tipo = ?
                    ) then 0 else 1 end) as sin_titular', ['titular'])
                    ->groupBy('ag.grupo_id'),
                'mat',
                'mat.grupo_id',
                '=',
                'grupos.id',
            )
            /*
             * El ALIAS tiene que ser el ultimo segmento de `columnaSql`.
             *
             * El recorrido por lotes lee el atributo con el nombre de la columna
             * --`al.cuantos` se lee como `$fila->cuantos`--, asi que aliasarlo
             * como `alumnos_count` dejaba al cursor leyendo NULL en cada vuelta:
             * en descendente truncaba y en ascendente NO TERMINABA. Es el mismo
             * defecto que dejaba tres columnas en blanco, ahora contra el cursor.
             */
            ->addSelect(['al.cuantos', 'mat.cuantas', 'mat.sin_titular']);
    }

    public function llavePrimaria(): string
    {
        return 'grupos.id';
    }
}
