<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\SituacionDocente;
use App\Models\ControlEscolar\TipoDocente;
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
 * La PLANTILLA DOCENTE: quién da clase, de qué y cuánto.
 *
 * ── Una fila es un DOCENTE ───────────────────────────────────────────────
 * `docentes` tiene PK `persona_id` y no id propio: el docente ES esa persona.
 * Quien da clase en dos campus sale UNA vez con los dos nombres en la misma
 * celda, y quien imparte ocho materias sale UNA vez con un ocho. Las materias
 * son un CONTEO suyo, nunca filas: desplegarlas convertiría «nueve docentes» en
 * «veintitrés», que es el error de conteo que no avisa.
 *
 * ── La carga se cuenta filtrando la asignación RETIRADA ──────────────────
 * `Docente::asignaturasGrupo()` NO filtra `docente_asignatura_grupo.deleted_at`
 * —comprobado en su SQL—, así que el `withCount` que hoy enseña
 * `/escolar/docentes` incluye materias que ya se le quitaron. Aquí se escribe
 * explícito, igual que en {@see Grupos}. Que la pantalla y el reporte den
 * números distintos es una consecuencia conocida de esto y está anotada como
 * deuda: el arreglo es corregir la relación, no copiar su defecto.
 *
 * ── Lo que NO se ofrece ──────────────────────────────────────────────────
 *  - **Horas frente a grupo.** `horarios_asignatura_grupo` está VACÍA en el
 *    demo, así que la columna saldría en cero para todos, sin error y sin
 *    aviso. Una columna que sólo puede mentir no se entrega.
 *  - **Si está sobre el tope de materias.** El tope vive en
 *    `AsignaturaGrupoController::motivoParaNoAsignar`, que es privado y está
 *    dentro de un controlador; es el único sitio que sabe que «tope 0» significa
 *    SIN LÍMITE. Reescribir esa regla aquí produciría un reporte que marca en
 *    rojo a quien el sistema sí deja asignar. Llega cuando ese criterio suba a
 *    un servicio.
 */
class Docentes implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'docentes';
    }

    public function titulo(): string
    {
        return 'Plantilla docente';
    }

    public function grano(): string
    {
        return 'Una fila es un DOCENTE. Sus materias, grupos y alumnos son conteos suyos, no filas: '
            .'quien imparte ocho materias sale UNA vez con un ocho.';
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
     * El docente llega al campus por una relación de MUCHOS A MUCHOS.
     *
     * Y con `incluirSinAsignar` PUESTO, a propósito: un docente al que nadie le
     * ha asignado campus todavía es alguien a quien hay que atender, y
     * esconderlo de todos los planteles lo convierte en un expediente que nadie
     * revisa. Es la misma decisión que `porColumnaPropia` toma con el campus en
     * null, y aquí se escribe porque desde hoy hay que pedirla.
     */
    public function recorte(): Recorte
    {
        return Recorte::porRelacion('campus', incluirSinAsignar: true);
    }

    public function columnas(): array
    {
        return [
            'clave_profesor' => new ColumnaReporte(
                clave: 'clave_profesor',
                etiqueta: 'Clave',
                columnaSql: 'docentes.clave_profesor',
                ordenable: true,
                ancho: 14,
            ),
            'docente' => new ColumnaReporte(
                clave: 'docente',
                etiqueta: 'Docente',
                valor: fn (Docente $d) => $d->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'cedula_profesional' => new ColumnaReporte(
                clave: 'cedula_profesional',
                etiqueta: 'Cédula',
                columnaSql: 'docentes.cedula_profesional',
                ordenable: true,
                ancho: 14,
                ayuda: 'Dato público del Registro Nacional de Profesionistas: no se oculta.',
            ),
            'tipo' => new ColumnaReporte(
                clave: 'tipo',
                etiqueta: 'Tipo',
                valor: fn (Docente $d) => $d->tipoDocente?->nombre,
                ancho: 18,
            ),
            'situacion' => new ColumnaReporte(
                clave: 'situacion',
                etiqueta: 'Situación',
                valor: fn (Docente $d) => $d->situacion?->nombre,
                ancho: 14,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                // Muchos a muchos: se JUNTAN en una celda en vez de repetir la
                // fila. Quien da clase en dos planteles sigue siendo uno.
                valor: fn (Docente $d) => $d->campus->pluck('nombre')->implode(', ') ?: null,
                ancho: 26,
            ),
            'correo_institucional' => new ColumnaReporte(
                clave: 'correo_institucional',
                etiqueta: 'Correo institucional',
                valor: fn (Docente $d) => $d->persona?->correo_institucional,
                ancho: 28,
                ayuda: 'El de la escuela, no el personal: es dato de trabajo y no de contacto privado.',
            ),
            'email' => new ColumnaReporte(
                clave: 'email',
                etiqueta: 'Correo personal',
                valor: fn (Docente $d) => $d->persona?->email,
                sensible: true,
                permisoExtra: 'editar-docentes',
                ancho: 26,
            ),
            'celular' => new ColumnaReporte(
                clave: 'celular',
                etiqueta: 'Celular',
                valor: fn (Docente $d) => $d->persona?->celular,
                sensible: true,
                permisoExtra: 'editar-docentes',
                ancho: 16,
            ),
            'curp' => new ColumnaReporte(
                clave: 'curp',
                etiqueta: 'CURP',
                valor: fn (Docente $d) => $d->persona?->curp,
                sensible: true,
                permisoExtra: 'editar-docentes',
                ancho: 20,
            ),
            'rfc' => new ColumnaReporte(
                clave: 'rfc',
                etiqueta: 'RFC',
                valor: fn (Docente $d) => $d->persona?->rfc,
                sensible: true,
                permisoExtra: 'editar-docentes',
                ancho: 16,
            ),
            'materias' => new ColumnaReporte(
                clave: 'materias',
                etiqueta: 'Materias',
                tipo: TipoDato::Entero,
                valor: fn (Docente $d) => (int) ($d->materias ?? 0),
                columnaSql: 'carga.materias',
                ordenable: true,
                ancho: 9,
                ayuda: 'Materias vivas que imparte en el ciclo elegido. NO cuenta las asignaciones que '
                    .'ya se le retiraron, al revés que el listado de docentes.',
            ),
            'grupos' => new ColumnaReporte(
                clave: 'grupos',
                etiqueta: 'Grupos',
                tipo: TipoDato::Entero,
                valor: fn (Docente $d) => (int) ($d->grupos ?? 0),
                columnaSql: 'carga.grupos',
                ordenable: true,
                ancho: 9,
                ayuda: 'Grupos distintos. Dos materias del mismo grupo son un grupo, no dos.',
            ),
            'titulos' => new ColumnaReporte(
                clave: 'titulos',
                etiqueta: 'Títulos',
                tipo: TipoDato::Entero,
                valor: fn (Docente $d) => (int) ($d->titulos_count ?? 0),
                columnaSql: 'tit.titulos_count',
                ordenable: true,
                ancho: 9,
                ayuda: 'Grados registrados en su expediente. Es lo que sostiene el perfil ante una acreditadora.',
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'ciclo_id' => new FiltroReporte(
                clave: 'ciclo_id',
                etiqueta: 'Ciclo de la carga',
                tipo: TipoFiltro::Lista,
                /*
                 * NO es un `where`: el valor se consume dentro de `consulta()`
                 * para acotar la subconsulta de la carga. Su closure devuelve la
                 * consulta INTACTA a propósito — y se escribe así para que nadie
                 * lo «arregle» metiéndole un where que dejaría fuera a los
                 * docentes sin carga, que son justo los que este reporte busca.
                 */
                aplicar: fn (Builder $q, $v) => $q,
                opciones: fn (Usuario $u) => Ciclo::query()->orderByDesc('id')->pluck('clave', 'id')->all(),
                ayuda: 'Acota los conteos de materias y grupos. Sin elegir ciclo se cuenta TODO su historial.',
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'campus',
                    fn (Builder $c) => $c->whereIn('campus.id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'tipo_docente_id' => new FiltroReporte(
                clave: 'tipo_docente_id',
                etiqueta: 'Tipo de docente',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('docentes.tipo_docente_id', $v),
                opciones: fn (Usuario $u) => TipoDocente::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'situacion_id' => new FiltroReporte(
                clave: 'situacion_id',
                etiqueta: 'Situación',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('docentes.situacion_id', $v),
                opciones: fn (Usuario $u) => SituacionDocente::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'sin_cedula' => new FiltroReporte(
                clave: 'sin_cedula',
                etiqueta: 'Sólo sin cédula',
                tipo: TipoFiltro::Booleano,
                // El guard `$v ?` no es opcional: el motor sólo salta null,
                // cadena vacía y arreglo vacío — un `false` SÍ llega aquí.
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where(fn (Builder $x) => $x->whereNull('docentes.cedula_profesional')
                        ->orWhere('docentes.cedula_profesional', '='))
                    : $q,
            ),
            'sin_carga' => new FiltroReporte(
                clave: 'sin_carga',
                etiqueta: 'Sólo sin materias asignadas',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where(fn (Builder $x) => $x->whereNull('carga.materias')->orWhere('carga.materias', '=', 0))
                    : $q,
            ),
        ];
    }

    /**
     * La carga y los títulos entran AGRUPADOS por JOIN.
     *
     * Los dos son a-muchos: con un join en crudo, un docente con ocho materias
     * saldría ocho veces y «nueve docentes» pasaría a ser «veintitrés». Ya
     * agrupados hay a lo sumo una fila por docente.
     *
     * Y por JOIN y no por `selectSub` porque estas columnas se ORDENAN, y un
     * alias de SELECT no vale en el `WHERE` del recorrido por lotes.
     */
    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        $ciclo = $filtros['ciclo_id'] ?? null;

        $carga = DB::table('docente_asignatura_grupo as dag')
            ->join('asignatura_grupo as ag', 'ag.id', '=', 'dag.asignatura_grupo_id')
            ->join('grupos as g', 'g.id', '=', 'ag.grupo_id')
            // Las RETIRADAS no cuentan. La relación del modelo no lo filtra, y
            // por eso este conteo puede no coincidir con el del listado.
            ->whereNull('dag.deleted_at')
            ->whereNull('ag.deleted_at')
            ->whereNull('g.deleted_at')
            ->when($ciclo !== null, fn ($q) => $q->where('g.ciclo_id', $ciclo))
            ->select('dag.persona_id')
            ->selectRaw('count(*) as materias')
            ->selectRaw('count(distinct g.id) as grupos')
            ->groupBy('dag.persona_id');

        return Docente::query()
            ->select('docentes.*')
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp,rfc,email,celular,correo_institucional',
                'tipoDocente:id,nombre',
                'situacion:id,nombre',
                'campus:id,nombre',
            ])
            ->leftJoinSub($carga, 'carga', 'carga.persona_id', '=', 'docentes.persona_id')
            ->leftJoinSub(
                DB::table('titulos_docente as td')
                    ->whereNull('td.deleted_at')
                    ->select('td.persona_id')
                    ->selectRaw('count(*) as titulos_count')
                    ->groupBy('td.persona_id'),
                'tit',
                'tit.persona_id',
                '=',
                'docentes.persona_id',
            )
            ->addSelect(['carga.materias', 'carga.grupos', 'tit.titulos_count']);
    }

    /** `docentes` no tiene id: su llave es la persona. */
    public function llavePrimaria(): string
    {
        return 'docentes.persona_id';
    }
}
