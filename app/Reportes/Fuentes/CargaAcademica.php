<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\AsignacionDocente;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Identidad\Usuario;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La CARGA ACADÉMICA: quién da qué, en qué grupo.
 *
 * ── Una fila es una ASIGNACIÓN ───────────────────────────────────────────
 * Un docente con ocho materias son OCHO filas; una materia con titular y
 * adjunto son DOS. Contar filas cuenta asignaciones — ni docentes ni materias.
 * Para contar docentes está {@see Docentes}, donde la carga es un número.
 *
 * Ésta es la pregunta que se hace al armar el ciclo y la que pide una
 * acreditadora: la tabla de quién imparte qué.
 *
 * ── El campus es DONDE SE IMPARTE, no dónde está adscrito el docente ─────
 * Un docente adscrito al campus centro que da una materia en el norte aparece
 * en el reporte del NORTE, porque esa clase se da ahí. Acotar por su adscripción
 * daría una carga que no corresponde a ningún plantel.
 *
 * ── Esta fuente no existía por una razón de esquema ──────────────────────
 * `docente_asignatura_grupo` tenía PK compuesta y ninguna columna `id`, y el
 * recorrido por lotes del motor avanza con UNA llave. La migración
 * `2026_08_26_090000` le dio llave propia; hasta entonces este reporte era
 * imposible y estaba anotado como deuda en vez de resuelto a medias.
 */
class CargaAcademica implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'carga-academica';
    }

    public function titulo(): string
    {
        return 'Carga académica';
    }

    public function grano(): string
    {
        return 'Una fila es una ASIGNACIÓN: un docente en UNA materia de UN grupo. Un docente con ocho '
            .'materias son ocho filas, y una materia con titular y adjunto son dos.';
    }

    public function permiso(): string
    {
        return 'ver-docentes';
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
     * Por el grupo donde se imparte, y SIN tolerancia.
     *
     * `porAdscripcion` genera un `whereHas` de la relación anidada, sin la rama
     * que deja pasar lo incompleto: una asignación cuyo grupo desapareció no
     * puede aparecer en el reporte de todos los planteles.
     */
    public function recorte(): Recorte
    {
        return Recorte::porAdscripcion('asignaturaGrupo.grupo');
    }

    public function columnas(): array
    {
        return [
            'docente' => new ColumnaReporte(
                clave: 'docente',
                etiqueta: 'Docente',
                valor: fn (AsignacionDocente $a) => $a->docente?->persona?->nombreCompleto(),
                ancho: 32,
            ),
            'clave_profesor' => new ColumnaReporte(
                clave: 'clave_profesor',
                etiqueta: 'Clave',
                valor: fn (AsignacionDocente $a) => $a->docente?->clave_profesor,
                ancho: 12,
            ),
            'tipo' => new ColumnaReporte(
                clave: 'tipo',
                etiqueta: 'Papel',
                columnaSql: 'docente_asignatura_grupo.tipo',
                ordenable: true,
                ancho: 10,
                ayuda: 'El TITULAR es quien firma el acta; el adjunto acompaña.',
            ),
            'materia' => new ColumnaReporte(
                clave: 'materia',
                etiqueta: 'Materia',
                valor: fn (AsignacionDocente $a) => $a->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                ancho: 34,
            ),
            'plan' => new ColumnaReporte(
                clave: 'plan',
                etiqueta: 'Plan',
                valor: fn (AsignacionDocente $a) => $a->asignaturaGrupo?->grupo?->plan?->nombre,
                ancho: 26,
            ),
            'grupo' => new ColumnaReporte(
                clave: 'grupo',
                etiqueta: 'Grupo',
                valor: fn (AsignacionDocente $a) => $a->asignaturaGrupo?->grupo?->clave,
                ancho: 12,
            ),
            'ciclo' => new ColumnaReporte(
                clave: 'ciclo',
                etiqueta: 'Ciclo',
                valor: fn (AsignacionDocente $a) => $a->asignaturaGrupo?->grupo?->ciclo?->clave,
                ancho: 12,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (AsignacionDocente $a) => $a->asignaturaGrupo?->grupo?->campus?->nombre,
                ancho: 18,
                ayuda: 'Donde SE IMPARTE la materia, no donde está adscrito el docente.',
            ),
            'inscritos' => new ColumnaReporte(
                clave: 'inscritos',
                etiqueta: 'Inscritos',
                tipo: TipoDato::Entero,
                valor: fn (AsignacionDocente $a) => (int) ($a->inscritos ?? 0),
                columnaSql: 'ins.inscritos',
                ordenable: true,
                ancho: 10,
                ayuda: 'Alumnos en ESA materia, sin los dados de baja. No son los del grupo entero. '
                    .'No se totaliza: el número es de la MATERIA y una fila es una ASIGNACIÓN, así que '
                    .'una materia con titular y adjunto sumaría sus inscritos dos veces; y aun con un '
                    .'solo docente, sumar cuenta parejas alumno-materia y no alumnos —quien cursa seis '
                    .'materias entra seis veces—. Cuántos alumnos hay lo contesta la fuente de matrículas.',
                total: Agregacion::Ninguno,
            ),
            'asignada_en' => new ColumnaReporte(
                clave: 'asignada_en',
                etiqueta: 'Asignada',
                tipo: TipoDato::Fecha,
                valor: fn (AsignacionDocente $a) => $a->created_at,
                columnaSql: 'docente_asignatura_grupo.created_at',
                ordenable: true,
                ancho: 12,
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
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'asignaturaGrupo.grupo',
                    fn (Builder $g) => $g->whereIn('ciclo_id', $v),
                ),
                opciones: fn (Usuario $u) => Ciclo::query()->orderByDesc('id')->pluck('clave', 'id')->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'asignaturaGrupo.grupo',
                    fn (Builder $g) => $g->whereIn('campus_id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'tipo' => new FiltroReporte(
                clave: 'tipo',
                etiqueta: 'Papel',
                tipo: TipoFiltro::Lista,
                aplicar: fn (Builder $q, string $v) => $q->where('docente_asignatura_grupo.tipo', $v),
                opciones: fn (Usuario $u) => ['titular' => 'Titular', 'adjunto' => 'Adjunto'],
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return AsignacionDocente::query()
            ->select('docente_asignatura_grupo.*')
            ->with([
                'docente:persona_id,clave_profesor',
                'docente.persona:id,nombre,primer_apellido,segundo_apellido',
                'asignaturaGrupo:id,grupo_id,plan_materia_id',
                'asignaturaGrupo.planMateria:id,asignatura_id',
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
                'asignaturaGrupo.grupo:id,clave,ciclo_id,campus_id,plan_id',
                'asignaturaGrupo.grupo.ciclo:id,clave',
                'asignaturaGrupo.grupo.campus:id,nombre',
                'asignaturaGrupo.grupo.plan:id,nombre',
            ])
            /*
             * Los inscritos de ESA materia, agrupados. Con un join en crudo la
             * asignación saldría una vez por alumno: treinta filas para un
             * docente que da una materia.
             */
            ->leftJoinSub(
                DB::table('inscripcion as i')
                    ->leftJoin('situaciones_inscripcion as si', 'si.id', '=', 'i.situacion_id')
                    ->whereNull('i.deleted_at')
                    ->where(fn ($q) => $q->whereNull('si.clave')->orWhere('si.clave', '!=', 'baja'))
                    ->select('i.asignatura_grupo_id')
                    ->selectRaw('count(distinct i.matricula_oferta_id) as inscritos')
                    ->groupBy('i.asignatura_grupo_id'),
                'ins',
                'ins.asignatura_grupo_id',
                '=',
                'docente_asignatura_grupo.asignatura_grupo_id',
            )
            ->addSelect(['ins.inscritos']);
    }

    /** La llave sustituta que la migración `2026_08_26_090000` le dio. */
    public function llavePrimaria(): string
    {
        return 'docente_asignatura_grupo.id';
    }
}
