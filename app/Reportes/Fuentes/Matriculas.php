<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Identidad\Usuario;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\DimensionReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteAgrupable;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use Illuminate\Database\Eloquent\Builder;

/**
 * La fuente de MATRÍCULAS: quién estudia qué.
 *
 * ── Una fila es una MATRÍCULA, no una persona ────────────────────────────
 * Es la distinción más importante de este reporte y por eso `grano()` la dice
 * con palabras en la pantalla: quien estudia dos carreras aparece DOS veces, y
 * es lo correcto —cada programa reporta lo suyo— pero quien lea «1 200 alumnos»
 * sin saberlo estará contando matrículas y presumiendo personas.
 *
 * Sobre esta misma fuente se montan varios reportes —inscritos, bajas,
 * egresados— cambiando sólo los filtros fijos. Ver `DefinicionReporte`.
 */
class Matriculas implements FuenteAgrupable, FuenteDeReporte
{
    public function clave(): string
    {
        return 'matriculas';
    }

    public function titulo(): string
    {
        return 'Matrículas';
    }

    public function grano(): string
    {
        return 'Una fila es una MATRÍCULA: quien estudia dos carreras aparece dos veces, una por programa.';
    }

    public function permiso(): string
    {
        return 'ver-alumnos';
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
     * Una matrícula llega al campus por su OFERTA.
     *
     * `matricula_oferta` no tiene `campus_id`: un `whereIn('campus_id', …)`
     * genérico habría reventado, y peor, uno mal escrito habría devuelto la
     * escuela entera a un coordinador de un solo plantel.
     */
    public function recorte(): Recorte
    {
        return Recorte::porOferta();
    }

    public function columnas(): array
    {
        return [
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                columnaSql: 'matricula_oferta.matricula',
                ordenable: true,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (MatriculaOferta $m) => $m->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'curp' => new ColumnaReporte(
                clave: 'curp',
                etiqueta: 'CURP',
                valor: fn (MatriculaOferta $m) => $m->persona?->curp,
                // Dato personal: se omite para quien no administra expedientes,
                // reusando el permiso que YA separa eso hoy.
                sensible: true,
                permisoExtra: 'editar-alumnos',
                ancho: 20,
            ),
            'carrera' => new ColumnaReporte(
                clave: 'carrera',
                etiqueta: 'Carrera',
                valor: fn (MatriculaOferta $m) => $m->oferta?->carrera?->nombre,
                ancho: 32,
            ),
            'plan' => new ColumnaReporte(
                clave: 'plan',
                etiqueta: 'Plan',
                valor: fn (MatriculaOferta $m) => $m->oferta?->plan?->nombre,
                ancho: 18,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (MatriculaOferta $m) => $m->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'generacion' => new ColumnaReporte(
                clave: 'generacion',
                etiqueta: 'Generación',
                columnaSql: 'matricula_oferta.generacion',
                ordenable: true,
                ancho: 12,
            ),
            'periodo_actual' => new ColumnaReporte(
                clave: 'periodo_actual',
                etiqueta: 'Periodo',
                tipo: TipoDato::Entero,
                columnaSql: 'matricula_oferta.periodo_actual',
                ordenable: true,
                ancho: 8,
                /*
                 * Es un ORDINAL —«va en 3.º»— y por eso no lleva pie.
                 *
                 * Sumarlo da la cifra sin sentido que el docblock de
                 * `Agregacion` usa de ejemplo; y promediarlo tampoco sirve,
                 * porque el número cuenta periodos DEL PLAN de cada quien: el 3
                 * de un bachillerato de seis semestres y el 3 de una
                 * licenciatura de nueve no son la misma posición, así que su
                 * media no dice por dónde va nadie.
                 */
                ayuda: 'El periodo del plan en que va el alumno. No se totaliza: es un ordinal '
                    .'—sumar los semestres de treinta alumnos no da nada, y promediarlos mezcla '
                    .'planes de distinta duración—.',
                total: Agregacion::Ninguno,
            ),
            'situacion' => new ColumnaReporte(
                clave: 'situacion',
                etiqueta: 'Situación',
                valor: fn (MatriculaOferta $m) => $m->situacion?->nombre,
                ancho: 16,
            ),
            'fecha_ingreso' => new ColumnaReporte(
                clave: 'fecha_ingreso',
                etiqueta: 'Ingreso',
                tipo: TipoDato::Fecha,
                valor: fn (MatriculaOferta $m) => $m->fecha_ingreso,
                columnaSql: 'matricula_oferta.fecha_ingreso',
                ordenable: true,
                ancho: 12,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'situacion_id' => new FiltroReporte(
                clave: 'situacion_id',
                etiqueta: 'Situación',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('situacion_id', $v),
                opciones: fn (Usuario $u) => SituacionAlumno::query()
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'oferta',
                    fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                /*
                 * Las opciones salen del alcance del USUARIO, no del catálogo
                 * entero: quien coordina un plantel no puede pedir el reporte de
                 * otro simplemente escribiendo su id, porque el motor valida el
                 * valor contra esta lista.
                 */
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'carrera_id' => new FiltroReporte(
                clave: 'carrera_id',
                etiqueta: 'Carrera',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'oferta',
                    fn (Builder $o) => $o->whereIn('carrera_id', $v),
                ),
                opciones: fn (Usuario $u) => Carrera::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'generacion' => new FiltroReporte(
                clave: 'generacion',
                etiqueta: 'Generación',
                tipo: TipoFiltro::Texto,
                aplicar: fn (Builder $q, string $v) => $q->where('generacion', $v),
                ayuda: 'Tal como se capturó: «2022», «2022-2025».',
            ),
            'ingreso_desde' => new FiltroReporte(
                clave: 'ingreso_desde',
                etiqueta: 'Ingresó desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('fecha_ingreso', '>=', $v),
            ),
            'ingreso_hasta' => new FiltroReporte(
                clave: 'ingreso_hasta',
                etiqueta: 'Ingresó hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('fecha_ingreso', '<=', $v),
            ),
        ];
    }

    /**
     * El eager loading va COMPLETO desde aquí.
     *
     * Es la fuente la que sabe qué relaciones tocan sus columnas. Sin esto, un
     * reporte de mil filas dispara mil consultas y no se nota hasta que alguien
     * pide el ciclo entero.
     */
    /**
     * Por qué se puede agrupar el padrón.
     *
     * Las tres son dimensiones de verdad —pocos valores, muchas filas— y las
     * tres son las que alguien pregunta: cuántos alumnos por campus, por carrera
     * y en qué situación están. Ninguna se podía agrupar antes de esto: las tres
     * se resuelven con una closure sobre una relación precargada, que sirve para
     * pintar la celda y no existe en SQL.
     *
     * **Se agrupa por el ID y se rotula con el NOMBRE.** Agrupar por el nombre
     * fundiría dos campus homónimos —una escuela con «Plantel Centro» en dos
     * municipios los tiene— y agrupar por el id sin rotular daría una tabla de
     * números.
     *
     * **Y la unión es un `join` y no un `leftJoin` donde la foránea es
     * obligatoria**: `matricula_oferta.oferta_id` lo es, así que un `leftJoin`
     * prometería un grupo «sin oferta» que la base no puede producir. Donde SÍ
     * puede faltar —la situación es nullable— va `leftJoin`, y ese grupo sin
     * etiqueta se enseña: esconderlo haría que los subtotales no sumaran el
     * total, que es lo único que un agrupado promete.
     */
    public function dimensiones(): array
    {
        /*
         * `oferta` se une UNA vez aunque se agrupe por dos de sus columnas.
         * Repetir el join daría «Not unique table/alias», y esto se llama justo
         * antes de agrupar, sobre una consulta que todavía no lo trae.
         */
        $conOferta = function (Builder $consulta): void {
            $consulta->join('oferta', 'oferta.id', '=', 'matricula_oferta.oferta_id');
        };

        return [
            'campus' => new DimensionReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                sqlAgrupacion: 'oferta.campus_id',
                sqlEtiqueta: 'campus.nombre',
                join: function (Builder $consulta) use ($conOferta): void {
                    $conOferta($consulta);
                    $consulta->join('campus', 'campus.id', '=', 'oferta.campus_id');
                },
                ayuda: 'El campus de la OFERTA en que está inscrita, que es donde estudia — no el de su rol.',
            ),
            'carrera' => new DimensionReporte(
                clave: 'carrera',
                etiqueta: 'Carrera',
                sqlAgrupacion: 'oferta.carrera_id',
                sqlEtiqueta: 'carreras.nombre',
                join: function (Builder $consulta) use ($conOferta): void {
                    $conOferta($consulta);
                    $consulta->join('carreras', 'carreras.id', '=', 'oferta.carrera_id');
                },
                ayuda: 'Quien estudia dos carreras cuenta en las dos: una fila es una MATRÍCULA, no una persona.',
            ),
            'situacion' => new DimensionReporte(
                clave: 'situacion',
                etiqueta: 'Situación escolar',
                sqlAgrupacion: 'matricula_oferta.situacion_id',
                sqlEtiqueta: 'situaciones_alumno.nombre',
                /*
                 * `join` y no `leftJoin`: medido, `matricula_oferta.situacion_id`
                 * es NOT NULL. Un `leftJoin` prometería aquí un grupo «sin
                 * situación» que la base no puede producir — otra salvaguarda
                 * que no salva nada, de las que este proyecto ya retiró dos.
                 *
                 * Donde el grupo vacío SÍ existe es en los aspirantes, cuyo
                 * campus, etapa y origen son nullable a propósito.
                 */
                join: function (Builder $consulta): void {
                    $consulta->join(
                        'situaciones_alumno',
                        'situaciones_alumno.id',
                        '=',
                        'matricula_oferta.situacion_id',
                    );
                },
                ayuda: 'Sale del catálogo de la escuela. Es obligatoria, así que no hay grupo sin situación.',
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return MatriculaOferta::query()->with([
            'persona:id,nombre,primer_apellido,segundo_apellido,curp',
            'oferta:id,carrera_id,plan_id,campus_id',
            'oferta.carrera:id,nombre',
            'oferta.plan:id,nombre',
            'oferta.campus:id,nombre',
            'situacion:id,nombre',
        ]);
    }

    public function llavePrimaria(): string
    {
        return 'matricula_oferta.id';
    }
}
