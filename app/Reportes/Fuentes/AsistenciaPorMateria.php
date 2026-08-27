<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Inscripcion;
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
 * ASISTENCIA por alumno y materia: quién está en riesgo de perder el derecho.
 *
 * ── Una fila es una INSCRIPCIÓN: un alumno en UNA materia ────────────────
 * No un alumno —quien lleva seis materias sale seis veces, y es lo correcto:
 * el derecho a examen se pierde materia por materia— ni una sesión, que es el
 * otro grano y vive en el detalle del pase de lista.
 *
 * ── El porcentaje se calcula sobre lo REGISTRADO, no sobre el calendario ─
 * Y hay que decirlo, porque es la diferencia entre un número útil y uno que
 * engaña: si un docente pasó lista tres veces en el semestre, «100 % de
 * asistencia» significa que fue a esas tres, no que no ha faltado. Por eso la
 * columna de sesiones viaja al lado del porcentaje: sin ella, un 100 % sobre
 * dos sesiones se lee igual que uno sobre cuarenta.
 *
 * ── Cuatro estatus y no dos ──────────────────────────────────────────────
 * `presente`, `falta`, `justificada` y `retardo`. La justificada NO cuenta como
 * falta para el derecho —para eso se justifica— pero tampoco es una presencia,
 * así que se enseña aparte en vez de sumarla a un lado y perder el dato. Y el
 * retardo es su propia cosa: tres retardos no son una falta salvo que la
 * escuela lo decida, y esa regla no existe todavía en el sistema.
 *
 * ── Los DADOS DE BAJA no salen ───────────────────────────────────────────
 * Y hay que decirlo porque no salía de balde: al que se dio de baja de una
 * materia NO se le puede pasar lista —`DocenciaController` lo saca de la lista
 * del docente—, así que se quedaba para siempre en la cola de «materias sin
 * lista pasada» sin gesto que la limpiara, y en «asistencia en riesgo» con un
 * 0 % que nadie podía corregir.
 *
 * El criterio es el que ya usaban `CargaAcademica`, `Grupo::inscritosDelGrupo()`
 * y `SalaDeMateria`: la clave del catálogo distinta de `baja`, tolerando el NULL
 * —una inscripción sin situación todavía cuenta—.
 *
 * ── Lo que NO se ofrece: el UMBRAL del derecho a examen ──────────────────
 * No hay ajuste que lo declare —se buscó en `CatalogoAjustes` y no está—, así
 * que una columna «¿pierde el derecho?» tendría que inventarse un porcentaje.
 * Se entrega el dato y el filtro por umbral, y quien lo consulta pone el suyo:
 * es honesto y sirve igual. El día que la escuela pueda configurarlo, la
 * columna sale de ese ajuste y no de un número escrito aquí.
 */
class AsistenciaPorMateria implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'asistencia-por-materia';
    }

    public function titulo(): string
    {
        return 'Asistencia por materia';
    }

    public function grano(): string
    {
        return 'Una fila es un ALUMNO EN UNA MATERIA. Quien lleva seis materias sale seis veces: el '
            .'derecho a examen se pierde materia por materia. El porcentaje es sobre las sesiones '
            .'REGISTRADAS, no sobre el calendario.';
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

    /** La inscripción llega al campus por la oferta de su matrícula. */
    public function recorte(): Recorte
    {
        return Recorte::porOferta('matriculaOferta');
    }

    public function columnas(): array
    {
        return [
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                valor: fn (Inscripcion $i) => $i->matriculaOferta?->matricula,
                columnaSql: 'mo.matricula',
                ordenable: true,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto(),
                ancho: 32,
            ),
            'materia' => new ColumnaReporte(
                clave: 'materia',
                etiqueta: 'Materia',
                valor: fn (Inscripcion $i) => $i->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                ancho: 32,
            ),
            'grupo' => new ColumnaReporte(
                clave: 'grupo',
                etiqueta: 'Grupo',
                valor: fn (Inscripcion $i) => $i->asignaturaGrupo?->grupo?->clave,
                ancho: 10,
            ),
            'ciclo' => new ColumnaReporte(
                clave: 'ciclo',
                etiqueta: 'Ciclo',
                valor: fn (Inscripcion $i) => $i->ciclo?->clave,
                ancho: 12,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (Inscripcion $i) => $i->matriculaOferta?->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'sesiones' => new ColumnaReporte(
                clave: 'sesiones',
                etiqueta: 'Sesiones',
                tipo: TipoDato::Entero,
                valor: fn (Inscripcion $i) => (int) ($i->sesiones ?? 0),
                columnaSql: 'asis.sesiones',
                ordenable: true,
                ancho: 9,
                ayuda: 'Cuántas veces se le pasó lista. Sin este dato, un 100 % sobre dos sesiones se '
                    .'lee igual que uno sobre cuarenta.',
            ),
            'presentes' => new ColumnaReporte(
                clave: 'presentes',
                etiqueta: 'Presentes',
                tipo: TipoDato::Entero,
                valor: fn (Inscripcion $i) => (int) ($i->presentes ?? 0),
                columnaSql: 'asis.presentes',
                ordenable: true,
                ancho: 10,
            ),
            'faltas' => new ColumnaReporte(
                clave: 'faltas',
                etiqueta: 'Faltas',
                tipo: TipoDato::Entero,
                valor: fn (Inscripcion $i) => (int) ($i->faltas ?? 0),
                columnaSql: 'asis.faltas',
                ordenable: true,
                ancho: 8,
            ),
            'justificadas' => new ColumnaReporte(
                clave: 'justificadas',
                etiqueta: 'Justificadas',
                tipo: TipoDato::Entero,
                valor: fn (Inscripcion $i) => (int) ($i->justificadas ?? 0),
                columnaSql: 'asis.justificadas',
                ordenable: true,
                ancho: 12,
                ayuda: 'NO cuentan como falta —para eso se justifican— pero tampoco son una presencia, '
                    .'así que van aparte en vez de sumarse a un lado y perder el dato.',
            ),
            'retardos' => new ColumnaReporte(
                clave: 'retardos',
                etiqueta: 'Retardos',
                tipo: TipoDato::Entero,
                valor: fn (Inscripcion $i) => (int) ($i->retardos ?? 0),
                columnaSql: 'asis.retardos',
                ordenable: true,
                ancho: 10,
                ayuda: 'Su propia cosa: tres retardos NO son una falta salvo que la escuela lo decida, '
                    .'y esa regla todavía no existe en el sistema.',
            ),
            'porcentaje' => new ColumnaReporte(
                clave: 'porcentaje',
                etiqueta: '% de asistencia',
                tipo: TipoDato::Porcentaje,
                valor: fn (Inscripcion $i) => (int) ($i->sesiones ?? 0) === 0
                    // Sin lista pasada NO hay porcentaje. Un 0 % diría que no ha
                    // ido nunca, y un 100 % que no ha faltado: los dos mienten.
                    ? null
                    : round(((int) $i->presentes + (int) $i->justificadas) * 100 / (int) $i->sesiones, 1),
                ancho: 13,
                ayuda: 'Presentes MÁS justificadas, sobre las sesiones registradas. En blanco si nadie '
                    .'le ha pasado lista: eso no es 0 % ni 100 %.',
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
                aplicar: fn (Builder $q, array $v) => $q->whereIn('inscripcion.ciclo_id', $v),
                opciones: fn (Usuario $u) => Ciclo::query()->orderByDesc('id')->pluck('clave', 'id')->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matriculaOferta.oferta',
                    fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'minimo_porcentaje' => new FiltroReporte(
                clave: 'minimo_porcentaje',
                etiqueta: 'Asistencia POR DEBAJO de (%)',
                tipo: TipoFiltro::Numero,
                /*
                 * El umbral lo pone quien consulta, porque el sistema no lo
                 * sabe: no hay ajuste que declare el mínimo para conservar el
                 * derecho a examen. Escribir un 80 aquí sería inventarle una
                 * regla a la escuela.
                 *
                 * Y sólo alcanza a quien TIENE lista pasada, sin filtro que lo
                 * diga: `asis.sesiones` es NULL cuando nadie pasó lista, y en
                 * MySQL toda la expresión sale NULL —una condición NULL descarta
                 * la fila—. Llevaba un `where('asis.sesiones', '>', 0)` delante y
                 * se retiró al ver que su mutación sobrevivía: no cambiaba ni una
                 * fila. Es el mismo desenlace que el `whereNotNull` de las
                 * colocaciones sin matrícula.
                 *
                 * **Lo que sí importa es no envolver esto en un `coalesce`**: con
                 * `coalesce(asis.sesiones, 0)` la división por cero seguiría
                 * dando NULL, pero con `coalesce(..., 1)` o cualquier respaldo
                 * distinto de cero, las materias sin lista entrarían al reporte
                 * de riesgo como si nadie hubiera asistido nunca.
                 */
                aplicar: fn (Builder $q, $v) => $q
                    ->whereRaw('((asis.presentes + asis.justificadas) * 100 / asis.sesiones) < ?', [(float) $v]),
                ayuda: 'Sólo alcanza a quien ya tiene lista pasada: sin sesiones no hay porcentaje que comparar.',
            ),
            'sin_lista' => new FiltroReporte(
                clave: 'sin_lista',
                etiqueta: 'Sólo materias sin lista pasada',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where(fn (Builder $x) => $x->whereNull('asis.sesiones')->orWhere('asis.sesiones', '=', 0))
                    : $q,
                ayuda: 'Es una cola de trabajo del DOCENTE, no un problema del alumno.',
            ),
        ];
    }

    /**
     * Los cuatro estatus, contados en UNA subconsulta agrupada.
     *
     * `asistencia_clase` es a-muchos —una fila por sesión— así que un join en
     * crudo convertiría a un alumno con cuarenta sesiones en cuarenta filas.
     *
     * Las constantes salen del MODELO y no se escriben a mano: `scopeFaltas()`
     * llegó a comparar contra `'ausente'` mientras lo guardado era `'falta'`, y
     * como nadie lo llamaba nunca se notó — un reporte de inasistencias habría
     * devuelto CERO y parecería que nadie falta nunca.
     */
    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return Inscripcion::query()
            ->select('inscripcion.*')
            /*
             * Fuera los dados de baja: ver el docblock. Al que ya no está no se
             * le puede pasar lista, así que su renglón es una tarea imposible.
             */
            ->leftJoin('situaciones_inscripcion as si', 'si.id', '=', 'inscripcion.situacion_id')
            ->where(fn ($q) => $q->whereNull('si.clave')->orWhere('si.clave', '!=', 'baja'))
            /*
             * La matrícula, para poder ORDENAR por ella. Es un `belongsTo`, así
             * que el join no multiplica filas; el `with` de abajo sigue sirviendo
             * para pintar, y esto sólo existe para el `ORDER BY` —que no puede
             * salir de una relación cargada después—.
             */
            ->leftJoin('matricula_oferta as mo', 'mo.id', '=', 'inscripcion.matricula_oferta_id')
            ->with([
                'matriculaOferta:id,persona_id,oferta_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'matriculaOferta.oferta:id,campus_id',
                'matriculaOferta.oferta.campus:id,nombre',
                'asignaturaGrupo:id,grupo_id,plan_materia_id',
                'asignaturaGrupo.planMateria:id,asignatura_id',
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
                'asignaturaGrupo.grupo:id,clave',
                'ciclo:id,clave',
            ])
            ->leftJoinSub(
                DB::table('asistencia_clase as ac')
                    ->whereNull('ac.deleted_at')
                    ->select('ac.inscripcion_id')
                    ->selectRaw('count(*) as sesiones')
                    ->selectRaw('sum(case when ac.estatus = ? then 1 else 0 end) as presentes', [AsistenciaClase::PRESENTE])
                    ->selectRaw('sum(case when ac.estatus = ? then 1 else 0 end) as faltas', [AsistenciaClase::FALTA])
                    ->selectRaw('sum(case when ac.estatus = ? then 1 else 0 end) as justificadas', [AsistenciaClase::JUSTIFICADA])
                    ->selectRaw('sum(case when ac.estatus = ? then 1 else 0 end) as retardos', [AsistenciaClase::RETARDO])
                    ->groupBy('ac.inscripcion_id'),
                'asis',
                'asis.inscripcion_id',
                '=',
                'inscripcion.id',
            )
            /*
             * `mo.matricula` viaja al SELECT porque el keyset compara el
             * ATRIBUTO de la fila contra la columna: sin él, exportar ordenando
             * por matrícula se detiene con «la fila no trae el atributo». El
             * alias tiene que ser el último segmento de `columnaSql`.
             */
            ->addSelect(['asis.sesiones', 'asis.presentes', 'asis.faltas', 'asis.justificadas', 'asis.retardos', 'mo.matricula']);
    }

    public function llavePrimaria(): string
    {
        return 'inscripcion.id';
    }
}
