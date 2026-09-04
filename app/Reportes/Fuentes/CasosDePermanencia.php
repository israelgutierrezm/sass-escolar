<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\MotivoCierreCaso;
use App\Models\Permanencia\NivelRiesgo;
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
 * La fuente de CASOS: el acompañamiento, cuánto tardó y en qué terminó.
 *
 * ── Aquí viven los tres indicadores que el módulo existe para dar ─────────
 *  1. **EFECTIVIDAD**: de `motivos_cierre_caso.cuenta_como_exito`, que es una
 *     bandera del catálogo y no una lista de claves en el código. Y al lado,
 *     **qué pasó con la señal**: un caso cerrado «con éxito» cuya señal sigue
 *     abierta es información, no un error de captura. Las dos cifras juntas son
 *     lo que impide creerse la primera.
 *  2. **RECURRENCIA**: `caso_origen_id`. Reabrir crea un caso NUEVO que apunta
 *     al cerrado, y eso es justo lo que hace medible que la situación volvió.
 *     Con un estado `reabierto` esta columna no existiría.
 *  3. **PERMANENCIA POR COHORTE**: la situación ACTUAL del alumno, agrupable
 *     por generación. De los acompañados, cuántos siguen.
 *
 * ── Y por qué NO hay una tercera fuente «permanencia por cohorte» ─────────
 * «De la generación X, cuántos siguen activos» ya lo contesta la fuente
 * `Matriculas`, con su filtro de situación y su dimensión de generación. Una
 * fuente nueva sería una SEGUNDA VERDAD sobre el mismo número, y este proyecto
 * ya pagó tener tres definiciones del promedio. Lo que aquí se agrega es lo que
 * allá no se puede preguntar: **de los que fueron acompañados**, cuántos siguen.
 *
 * ── Una fila es un CASO, no una persona ───────────────────────────────────
 * Quien fue acompañado dos veces —porque la situación volvió— aparece dos
 * veces. Es lo correcto: cada acompañamiento tuvo su desenlace.
 */
class CasosDePermanencia implements FuenteAgrupable, FuenteDeReporte
{
    public function clave(): string
    {
        return 'casos_permanencia';
    }

    public function titulo(): string
    {
        return 'Casos de acompañamiento';
    }

    public function grano(): string
    {
        return 'Una fila es un CASO: quien fue acompañado dos veces aparece dos veces, una por '
            .'acompañamiento.';
    }

    public function permiso(): string
    {
        return 'ver-alertas';
    }

    public function modulo(): ?string
    {
        return 'permanencia';
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /**
     * El campus está COPIADO en el caso, así que el recorte va por columna.
     *
     * `porColumnaPropia` deja pasar las filas SIN campus, que es lo que hace
     * falta: un caso cuya oferta no tenía plantel al abrirse lo alcanza
     * cualquiera, igual que en la bandeja. Esconderlo de todos lo convertiría en
     * un caso que nadie atiende y que no sale en ningún informe.
     *
     * Y la columna va **CALIFICADA**: sin la tabla delante, una dimensión que
     * una `oferta` —que también tiene `campus_id`— la vuelve ambigua, y eso se
     * dispara solo al agrupar, sin que nadie escriba nada.
     */
    public function recorte(): Recorte
    {
        return Recorte::porColumnaPropia('casos_permanencia.campus_id');
    }

    public function columnas(): array
    {
        return [
            'folio' => new ColumnaReporte(
                clave: 'folio',
                etiqueta: 'Folio',
                valor: fn (CasoPermanencia $c) => $c->folio,
                columnaSql: 'casos_permanencia.folio',
                ordenable: true,
                ancho: 18,
            ),
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                valor: fn (CasoPermanencia $c) => $c->matricula?->matricula,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (CasoPermanencia $c) => $c->matricula?->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'programa_academico' => new ColumnaReporte(
                clave: 'programa_academico',
                etiqueta: 'Programa académico',
                valor: fn (CasoPermanencia $c) => $c->matricula?->oferta?->programaAcademico?->nombre,
                ancho: 32,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (CasoPermanencia $c) => $c->campus?->nombre,
                ancho: 18,
            ),
            'generacion' => new ColumnaReporte(
                clave: 'generacion',
                etiqueta: 'Generación',
                valor: fn (CasoPermanencia $c) => $c->matricula?->generacion,
                ancho: 12,
            ),
            'estado' => new ColumnaReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                valor: fn (CasoPermanencia $c) => $c->estado->etiqueta(),
                columnaSql: 'casos_permanencia.estado',
                ordenable: true,
                ancho: 18,
            ),
            'prioridad' => new ColumnaReporte(
                clave: 'prioridad',
                etiqueta: 'Prioridad',
                valor: fn (CasoPermanencia $c) => $c->prioridad,
                columnaSql: 'casos_permanencia.prioridad',
                ordenable: true,
                ancho: 10,
            ),
            'nivel_apertura' => new ColumnaReporte(
                clave: 'nivel_apertura',
                etiqueta: 'Riesgo al abrir',
                valor: fn (CasoPermanencia $c) => $c->nivelApertura?->nombre,
                ancho: 16,
                ayuda: 'El nivel del momento en que se abrió, congelado. Leerlo en vivo haría que '
                    .'un caso resuelto se viera como si nunca hubiera hecho falta.',
            ),
            'responsable' => new ColumnaReporte(
                clave: 'responsable',
                etiqueta: 'Responsable',
                valor: fn (CasoPermanencia $c) => $c->responsable?->persona?->nombreCompleto(),
                ancho: 28,
            ),
            'abierto_en' => new ColumnaReporte(
                clave: 'abierto_en',
                etiqueta: 'Abierto',
                tipo: TipoDato::Fecha,
                valor: fn (CasoPermanencia $c) => $c->abierto_en,
                columnaSql: 'casos_permanencia.abierto_en',
                ordenable: true,
                ancho: 12,
            ),
            'horas_primer_contacto' => new ColumnaReporte(
                clave: 'horas_primer_contacto',
                etiqueta: 'Horas al 1er contacto',
                tipo: TipoDato::Entero,
                valor: fn (CasoPermanencia $c) => $c->horasHastaElPrimerContacto(),
                ancho: 16,
                /*
                 * PROMEDIO y no suma: sumar las horas de espera de cien casos da
                 * un número sin significado. El promedio sí lo tiene — es cuánto
                 * tarda la escuela en hablar con alguien.
                 */
                total: Agregacion::Promedio,
                // Lo MISMO que la celda: `diffInHours` sobre los dos instantes.
                sqlTotal: 'timestampdiff(hour, casos_permanencia.abierto_en, '
                    .'casos_permanencia.primer_contacto_en)',
                ayuda: 'Desde que se abrió hasta que alguien habló con alguien. Vacío mientras no '
                    .'haya habido contacto: un cero ahí sería una mentira que además promedia bien.',
            ),
            'sla_vencido' => new ColumnaReporte(
                clave: 'sla_vencido',
                etiqueta: 'Fuera de plazo',
                tipo: TipoDato::Booleano,
                valor: fn (CasoPermanencia $c) => $c->slaVencido(),
                ancho: 12,
                ayuda: 'Abierto, con plazo fijado y sin contacto. Uno atendido a tiempo no está '
                    .'fuera de plazo aunque siga abierto.',
            ),
            'cerrado_en' => new ColumnaReporte(
                clave: 'cerrado_en',
                etiqueta: 'Cerrado',
                tipo: TipoDato::Fecha,
                valor: fn (CasoPermanencia $c) => $c->cerrado_en,
                columnaSql: 'casos_permanencia.cerrado_en',
                ordenable: true,
                ancho: 12,
            ),
            'dias_abierto' => new ColumnaReporte(
                clave: 'dias_abierto',
                etiqueta: 'Días que duró',
                tipo: TipoDato::Entero,
                valor: fn (CasoPermanencia $c) => $c->cerrado_en === null
                    ? null
                    : (int) $c->abierto_en->startOfDay()
                        ->diffInDays($c->cerrado_en->startOfDay(), absolute: true),
                ancho: 12,
                total: Agregacion::Promedio,
                sqlTotal: 'datediff(casos_permanencia.cerrado_en, casos_permanencia.abierto_en)',
                ayuda: 'Sólo de los cerrados. Uno abierto todavía no ha durado nada: contarlo '
                    .'bajaría el promedio justamente con los que llevan más tiempo.',
            ),
            'motivo_cierre' => new ColumnaReporte(
                clave: 'motivo_cierre',
                etiqueta: 'Motivo del cierre',
                valor: fn (CasoPermanencia $c) => $c->motivoCierre?->nombre,
                ancho: 30,
            ),
            'cuenta_como_exito' => new ColumnaReporte(
                clave: 'cuenta_como_exito',
                etiqueta: '¿Sirvió?',
                /*
                 * TRES valores y no dos: NULL es «ni una cosa ni otra» —cambió
                 * de plantel, se abrió por error—. Contar un traslado como
                 * fracaso castigaría a quien atendió bien un caso que dejó de
                 * ser suyo, y como éxito inflaría el indicador.
                 */
                valor: fn (CasoPermanencia $c) => match ($c->motivoCierre?->cuenta_como_exito) {
                    true => 'Sí',
                    false => 'No',
                    default => $c->cerrado_en === null ? null : 'Ni una cosa ni otra',
                },
                ancho: 16,
                ayuda: 'Sale de la bandera del motivo del catálogo, no de una lista escrita en el '
                    .'código: el motivo que la escuela agregue mañana cuenta igual.',
            ),
            'senal_resuelta' => new ColumnaReporte(
                clave: 'senal_resuelta',
                etiqueta: 'La señal mejoró',
                tipo: TipoDato::Booleano,
                /*
                 * Lo MEDIDO frente a lo declarado. Un caso cerrado «con éxito»
                 * cuya señal sigue abierta es información —quizá la mejora
                 * todavía no se refleja, quizá el cierre fue optimista— y las
                 * dos cifras juntas son lo que impide creerse la primera.
                 */
                valor: fn (CasoPermanencia $c) => $c->alertas->isEmpty()
                    ? null
                    : $c->alertas->every(fn ($a) => $a->estado_senal !== Alerta::ACTIVA),
                ancho: 14,
                ayuda: 'Si TODAS las señales que originaron el caso dejaron de cumplirse. Es el '
                    .'lado medido de la efectividad, frente al declarado en el motivo del cierre.',
            ),
            'es_reapertura' => new ColumnaReporte(
                clave: 'es_reapertura',
                etiqueta: 'Reapertura',
                tipo: TipoDato::Booleano,
                valor: fn (CasoPermanencia $c) => $c->caso_origen_id !== null,
                ancho: 12,
                ayuda: 'La situación volvió después de haberse cerrado. Es la medición de '
                    .'recurrencia, y sólo existe porque reabrir crea un caso nuevo.',
            ),
            'situacion_actual' => new ColumnaReporte(
                clave: 'situacion_actual',
                etiqueta: 'Situación hoy',
                /*
                 * La del ALUMNO, no la del caso: es lo que convierte esta fuente
                 * en «de los acompañados, cuántos siguen». Se lee en vivo a
                 * propósito — la pregunta es por HOY.
                 */
                valor: fn (CasoPermanencia $c) => $c->matricula?->situacion?->nombre,
                ancho: 18,
                ayuda: 'La del alumno hoy, no la de cuando se cerró el caso. Agrupando por '
                    .'generación es la permanencia por cohorte de los acompañados.',
            ),
            'intervenciones' => new ColumnaReporte(
                clave: 'intervenciones',
                etiqueta: 'Intervenciones',
                tipo: TipoDato::Entero,
                valor: fn (CasoPermanencia $c) => $c->intervenciones_count,
                ancho: 14,
                /*
                 * NO ordenable, y no por descuido: `withCount` genera una
                 * subconsulta con alias, y un alias del SELECT vale en el
                 * `ORDER BY` pero NO en el `WHERE` — que es por donde avanza el
                 * recorrido por lotes. Ordenaría bien en pantalla y reventaría
                 * al exportar, que es el defecto que este motor ya documentó.
                 *
                 * El TOTAL sí se puede: una subconsulta CORRELACIONADA es una
                 * expresión válida dentro del agregado.
                 */
                total: Agregacion::Suma,
                sqlTotal: '(select count(*) from intervenciones '
                    .'where intervenciones.caso_id = casos_permanencia.id '
                    .'and intervenciones.deleted_at is null)',
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'estado' => new FiltroReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('casos_permanencia.estado', $v),
                opciones: fn (Usuario $u) => collect(EstadoCaso::cases())
                    ->mapWithKeys(fn (EstadoCaso $e) => [$e->value => $e->etiqueta()])->all(),
            ),
            'prioridad' => new FiltroReporte(
                clave: 'prioridad',
                etiqueta: 'Prioridad',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('casos_permanencia.prioridad', $v),
                opciones: fn (Usuario $u) => collect(CasoPermanencia::PRIORIDADES)
                    ->mapWithKeys(fn (string $p) => [$p => $p])->all(),
            ),
            'motivo_cierre_id' => new FiltroReporte(
                clave: 'motivo_cierre_id',
                etiqueta: 'Motivo del cierre',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('casos_permanencia.motivo_cierre_id', $v),
                opciones: fn (Usuario $u) => MotivoCierreCaso::query()
                    ->orderBy('orden')->pluck('nombre', 'id')->all(),
            ),
            'nivel_apertura_id' => new FiltroReporte(
                clave: 'nivel_apertura_id',
                etiqueta: 'Riesgo al abrir',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('casos_permanencia.nivel_riesgo_apertura_id', $v),
                opciones: fn (Usuario $u) => NivelRiesgo::query()
                    ->reorder()->orderBy('orden')->pluck('nombre', 'id')->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('casos_permanencia.campus_id', $v),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'programa_academico_id' => new FiltroReporte(
                clave: 'programa_academico_id',
                etiqueta: 'Programa académico',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matricula.oferta', fn (Builder $o) => $o->whereIn('programa_academico_id', $v),
                ),
                opciones: fn (Usuario $u) => ProgramaAcademico::query()
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'situacion_id' => new FiltroReporte(
                clave: 'situacion_id',
                etiqueta: 'Situación del alumno hoy',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matricula', fn (Builder $m) => $m->whereIn('situacion_id', $v),
                ),
                opciones: fn (Usuario $u) => SituacionAlumno::query()
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
                ayuda: 'De los acompañados, en qué están hoy. Es la permanencia por cohorte.',
            ),
            'solo_reaperturas' => new FiltroReporte(
                clave: 'solo_reaperturas',
                etiqueta: 'Sólo las reaperturas',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereNotNull('casos_permanencia.caso_origen_id')
                    : $q,
                ayuda: 'Casos que volvieron después de haberse cerrado. Es la recurrencia.',
            ),
            'solo_fuera_de_plazo' => new FiltroReporte(
                clave: 'solo_fuera_de_plazo',
                etiqueta: 'Sólo los que se pasaron del plazo',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->slaVencido() : $q,
                ayuda: 'Abiertos, con plazo fijado y sin ningún contacto registrado.',
            ),
            'abierto_desde' => new FiltroReporte(
                clave: 'abierto_desde',
                etiqueta: 'Abierto desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('casos_permanencia.abierto_en', '>=', $v),
            ),
            'abierto_hasta' => new FiltroReporte(
                clave: 'abierto_hasta',
                etiqueta: 'Abierto hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('casos_permanencia.abierto_en', '<=', $v),
            ),
        ];
    }

    /**
     * Por qué se puede agrupar.
     *
     * **La GENERACIÓN es la que hace posible la permanencia por cohorte**, y por
     * eso está aunque sea una columna de texto: agrupar por ella y filtrar por
     * la situación de hoy contesta «de los que acompañamos en la generación
     * 2022, cuántos siguen».
     */
    public function dimensiones(): array
    {
        return [
            'estado' => new DimensionReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                sqlAgrupacion: 'casos_permanencia.estado',
                sqlEtiqueta: 'casos_permanencia.estado',
            ),
            'motivo_cierre' => new DimensionReporte(
                clave: 'motivo_cierre',
                etiqueta: 'Motivo del cierre',
                sqlAgrupacion: 'motivos_cierre_caso.id',
                sqlEtiqueta: 'motivos_cierre_caso.nombre',
                /*
                 * `leftJoin`: `motivo_cierre_id` es nullable —los abiertos no lo
                 * tienen— y ese grupo se ENSEÑA. Esconderlo haría que los
                 * subtotales no sumaran el total.
                 */
                join: fn (Builder $q) => $q->leftJoin(
                    'motivos_cierre_caso', 'motivos_cierre_caso.id', '=', 'casos_permanencia.motivo_cierre_id',
                ),
            ),
            'campus' => new DimensionReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                sqlAgrupacion: 'campus.id',
                sqlEtiqueta: 'campus.nombre',
                join: fn (Builder $q) => $q->leftJoin(
                    'campus', 'campus.id', '=', 'casos_permanencia.campus_id',
                ),
            ),
            'generacion' => new DimensionReporte(
                clave: 'generacion',
                etiqueta: 'Generación',
                sqlAgrupacion: 'matricula_oferta.generacion',
                sqlEtiqueta: 'matricula_oferta.generacion',
                join: fn (Builder $q) => $q->leftJoin(
                    'matricula_oferta', 'matricula_oferta.id', '=', 'casos_permanencia.matricula_oferta_id',
                ),
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return CasoPermanencia::query()
            /*
             * El conteo va en SQL y no por relación cargada: con mil casos, un
             * `->intervenciones->count()` por fila son mil consultas — la N+1
             * que este motor existe para no tener.
             */
            ->withCount('intervenciones')
            ->with([
                'matricula:id,persona_id,matricula,oferta_id,generacion,situacion_id',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'matricula.oferta:id,programa_academico_id,campus_id',
                'matricula.oferta.programaAcademico:id,nombre',
                'matricula.situacion:id,nombre',
                'campus:id,nombre',
                'nivelApertura:id,nombre',
                'motivoCierre:id,nombre,cuenta_como_exito',
                'responsable:id,persona_id',
                'responsable.persona:id,nombre,primer_apellido,segundo_apellido',
                'alertas:id,estado_senal',
            ]);
    }

    public function llavePrimaria(): string
    {
        return 'casos_permanencia.id';
    }
}
