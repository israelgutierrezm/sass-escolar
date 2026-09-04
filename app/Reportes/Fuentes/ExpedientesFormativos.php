<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
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
 * La fuente de EXPEDIENTES formativos: quién hace qué y en qué punto va.
 *
 * ── Una fila es un EXPEDIENTE, no una persona ────────────────────────────
 * Quien hace servicio social y además prácticas aparece DOS veces, y quien
 * estudia dos programas puede tener dos expedientes del mismo tipo. Es lo
 * correcto —cada proceso reporta lo suyo— pero quien lea «120 alumnos» sin
 * saberlo estará contando expedientes.
 *
 * ── El módulo NO se declara, y no es descuido ────────────────────────────
 * `procesos_formativos` SÍ está encendido en `modulos_activos` —lo enciende su
 * propia migración—, así que declararlo sería correcto hoy. Se declara: al
 * revés que finanzas o certificación, que figuran apagados por no tener fila y
 * dejarían sus reportes en 404.
 */
class ExpedientesFormativos implements FuenteAgrupable, FuenteDeReporte
{
    public function clave(): string
    {
        return 'expedientes_formativos';
    }

    public function titulo(): string
    {
        return 'Servicio social y prácticas';
    }

    public function grano(): string
    {
        return 'Una fila es un EXPEDIENTE: quien hace servicio social y prácticas aparece dos veces, '
            .'una por proceso.';
    }

    public function permiso(): string
    {
        return 'ver-procesos-formativos';
    }

    public function modulo(): ?string
    {
        return 'procesos_formativos';
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /**
     * Un expediente llega al campus por la OFERTA de su matrícula.
     *
     * `expedientes_proceso` no tiene `campus_id` y no debe tenerlo: el campus es
     * de la oferta, y copiarlo crearía un segundo dato que se separaría al
     * cambiar de plantel.
     *
     * **La cadena TIENE que terminar en la relación del campus.** `porRelacion`
     * escribe `campus.id` dentro de su `whereHas`, así que parándola en `oferta`
     * la consulta busca esa columna en la tabla equivocada. Y no falla al
     * arrancar ni al registrar el reporte: falla la primera vez que lo ejecuta
     * alguien ACOTADO A UN CAMPUS —con alcance global el recorte ni se aplica—,
     * o sea en la escuela del cliente y no aquí.
     */
    public function recorte(): Recorte
    {
        return Recorte::porRelacion('matricula.oferta.campus');
    }

    public function columnas(): array
    {
        return [
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                valor: fn (ExpedienteProceso $e) => $e->matricula?->matricula,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (ExpedienteProceso $e) => $e->matricula?->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'curp' => new ColumnaReporte(
                clave: 'curp',
                etiqueta: 'CURP',
                valor: fn (ExpedienteProceso $e) => $e->matricula?->persona?->curp,
                // Dato personal: se omite para quien no administra expedientes,
                // reusando el permiso que YA separa eso hoy.
                sensible: true,
                permisoExtra: 'editar-alumnos',
                ancho: 20,
            ),
            'programa_academico' => new ColumnaReporte(
                clave: 'programa_academico',
                etiqueta: 'Programa académico',
                valor: fn (ExpedienteProceso $e) => $e->matricula?->oferta?->programaAcademico?->nombre,
                ancho: 32,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (ExpedienteProceso $e) => $e->matricula?->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'tipo' => new ColumnaReporte(
                clave: 'tipo',
                etiqueta: 'Proceso',
                valor: fn (ExpedienteProceso $e) => $e->tipoProceso?->nombre,
                ancho: 22,
            ),
            'estado' => new ColumnaReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                valor: fn (ExpedienteProceso $e) => $e->estado->etiqueta(),
                columnaSql: 'expedientes_proceso.estado',
                ordenable: true,
                ancho: 18,
            ),
            'organizacion' => new ColumnaReporte(
                clave: 'organizacion',
                etiqueta: 'Organización',
                valor: fn (ExpedienteProceso $e) => $e->organizacion?->comoSeLeConoce(),
                ancho: 32,
            ),
            'horas_requeridas' => new ColumnaReporte(
                clave: 'horas_requeridas',
                etiqueta: 'Horas pedidas',
                tipo: TipoDato::Entero,
                columnaSql: 'expedientes_proceso.horas_requeridas',
                ordenable: true,
                ancho: 12,
                /*
                 * No se totaliza: es un UMBRAL repetido por fila, no una
                 * cantidad. Sumar «480 horas» de treinta expedientes da 14 400,
                 * que no es nada — es la misma razón por la que
                 * `certificables.meta` tampoco lleva pie.
                 */
                ayuda: 'Lo que su regla le exige. No se totaliza: es un umbral repetido por fila, '
                    .'no una cantidad acumulable.',
                total: Agregacion::Ninguno,
            ),
            'horas_aprobadas' => new ColumnaReporte(
                clave: 'horas_aprobadas',
                etiqueta: 'Horas hechas',
                tipo: TipoDato::Entero,
                columnaSql: 'expedientes_proceso.horas_aprobadas',
                ordenable: true,
                ancho: 12,
                // Ésta SÍ suma: son horas de verdad, de gente distinta, y su
                // total es «cuánto trabajo comunitario aportó la escuela».
                total: Agregacion::Suma,
            ),
            'avance' => new ColumnaReporte(
                clave: 'avance',
                etiqueta: 'Avance',
                tipo: TipoDato::Porcentaje,
                valor: fn (ExpedienteProceso $e) => $e->horas_requeridas > 0
                    ? round(((int) $e->horas_aprobadas) * 100 / (int) $e->horas_requeridas, 1)
                    : null,
                ancho: 10,
                // Un porcentaje no se suma, y promediarlo mezclaría procesos de
                // 480 horas con otros de 100.
                ayuda: 'Horas hechas sobre las pedidas. No se totaliza: promediar porcentajes de '
                    .'procesos con distinta exigencia no dice nada.',
                total: Agregacion::Ninguno,
            ),
            'fecha_solicitud' => new ColumnaReporte(
                clave: 'fecha_solicitud',
                etiqueta: 'Solicitó',
                tipo: TipoDato::Fecha,
                valor: fn (ExpedienteProceso $e) => $e->fecha_solicitud,
                columnaSql: 'expedientes_proceso.fecha_solicitud',
                ordenable: true,
                ancho: 12,
            ),
            'fecha_inicio' => new ColumnaReporte(
                clave: 'fecha_inicio',
                etiqueta: 'Empezó',
                tipo: TipoDato::Fecha,
                valor: fn (ExpedienteProceso $e) => $e->fecha_inicio,
                columnaSql: 'expedientes_proceso.fecha_inicio',
                ordenable: true,
                ancho: 12,
            ),
            'fecha_fin_programada' => new ColumnaReporte(
                clave: 'fecha_fin_programada',
                etiqueta: 'Debe terminar',
                tipo: TipoDato::Fecha,
                valor: fn (ExpedienteProceso $e) => $e->fecha_fin_programada,
                columnaSql: 'expedientes_proceso.fecha_fin_programada',
                ordenable: true,
                ancho: 14,
            ),
            'folio_liberacion' => new ColumnaReporte(
                clave: 'folio_liberacion',
                etiqueta: 'Folio',
                /*
                 * El de la liberación VIGENTE. Sin filtrar las corregidas, un
                 * expediente enmendado saldría con el folio que ya no vale — y
                 * ése es justo el que no hay que citar en ningún lado.
                 */
                valor: fn (ExpedienteProceso $e) => $e->liberaciones
                    ->firstWhere('corregida_en', null)?->folio,
                ancho: 18,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'tipo_proceso_id' => new FiltroReporte(
                clave: 'tipo_proceso_id',
                etiqueta: 'Proceso',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('expedientes_proceso.tipo_proceso_id', $v),
                opciones: fn (Usuario $u) => TipoProcesoFormativo::query()
                    ->orderBy('orden')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'estado' => new FiltroReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('expedientes_proceso.estado', $v),
                opciones: fn (Usuario $u) => collect(EstadoExpediente::cases())
                    ->mapWithKeys(fn (EstadoExpediente $e) => [$e->value => $e->etiqueta()])
                    ->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matricula.oferta',
                    fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                /*
                 * Las opciones salen del alcance del USUARIO y no del catálogo
                 * entero: quien coordina un plantel no puede pedir el reporte de
                 * otro escribiendo su id, porque el motor valida contra esta
                 * lista.
                 */
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'programa_academico_id' => new FiltroReporte(
                clave: 'programa_academico_id',
                etiqueta: 'Programa académico',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matricula.oferta',
                    fn (Builder $o) => $o->whereIn('programa_academico_id', $v),
                ),
                opciones: fn (Usuario $u) => ProgramaAcademico::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'organizacion_id' => new FiltroReporte(
                clave: 'organizacion_id',
                etiqueta: 'Organización',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('expedientes_proceso.organizacion_id', $v),
                opciones: fn (Usuario $u) => OrganizacionReceptora::query()
                    ->orderBy('razon_social')
                    ->pluck('razon_social', 'id')
                    ->all(),
            ),
            'inicio_desde' => new FiltroReporte(
                clave: 'inicio_desde',
                etiqueta: 'Empezó desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('expedientes_proceso.fecha_inicio', '>=', $v),
            ),
            'inicio_hasta' => new FiltroReporte(
                clave: 'inicio_hasta',
                etiqueta: 'Empezó hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('expedientes_proceso.fecha_inicio', '<=', $v),
            ),
            'vencidos' => new FiltroReporte(
                clave: 'vencidos',
                etiqueta: 'Sólo los que se pasaron de fecha',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereDate('fecha_fin_programada', '<', now()->toDateString())
                        ->whereIn('estado', [
                            EstadoExpediente::EnCurso->value,
                            EstadoExpediente::Suspendido->value,
                        ])
                    : $q,
                ayuda: 'Los que debían terminar y siguen abiertos. Es la cola que alguien tiene que resolver.',
            ),
        ];
    }

    /**
     * Por qué se puede agrupar.
     *
     * Las tres son dimensiones de verdad —pocos valores, muchas filas— y las
     * tres son las que alguien pregunta: cuántos por proceso, en qué estado y
     * en qué organización.
     *
     * **Se agrupa por el ID y se rotula con el NOMBRE**, salvo el estado, que ya
     * ES una cadena corta en la propia tabla. Agrupar por el nombre fundiría dos
     * organizaciones homónimas —dos delegaciones de la misma dependencia las
     * tiene cualquier escuela—.
     */
    public function dimensiones(): array
    {
        return [
            'tipo' => new DimensionReporte(
                clave: 'tipo',
                etiqueta: 'Proceso',
                sqlAgrupacion: 'tipos_proceso_formativo.id',
                sqlEtiqueta: 'tipos_proceso_formativo.nombre',
                /*
                 * `join` y no `leftJoin`: `tipo_proceso_id` es NOT NULL, así que
                 * un `leftJoin` prometería un grupo «sin proceso» que la base no
                 * puede producir ni sembrándolo.
                 */
                join: fn (Builder $q) => $q->join(
                    'tipos_proceso_formativo',
                    'tipos_proceso_formativo.id',
                    '=',
                    'expedientes_proceso.tipo_proceso_id',
                ),
            ),
            'estado' => new DimensionReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                sqlAgrupacion: 'expedientes_proceso.estado',
                sqlEtiqueta: 'expedientes_proceso.estado',
            ),
            'organizacion' => new DimensionReporte(
                clave: 'organizacion',
                etiqueta: 'Organización',
                sqlAgrupacion: 'organizaciones_receptoras.id',
                sqlEtiqueta: 'organizaciones_receptoras.razon_social',
                /*
                 * Aquí SÍ `leftJoin`: `organizacion_id` es nullable —un
                 * expediente sin asignar todavía no la tiene—, y ese grupo se
                 * ENSEÑA. Esconderlo haría que los subtotales no sumaran el
                 * total, que es lo único que un agrupado promete.
                 */
                join: fn (Builder $q) => $q->leftJoin(
                    'organizaciones_receptoras',
                    'organizaciones_receptoras.id',
                    '=',
                    'expedientes_proceso.organizacion_id',
                ),
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        /*
         * El eager loading va COMPLETO desde aquí: es la fuente la que sabe qué
         * relaciones tocan sus columnas. Sin esto, un reporte de mil filas
         * dispara mil consultas y no se nota hasta que alguien pide el ciclo
         * entero.
         */
        return ExpedienteProceso::query()->with([
            'matricula:id,persona_id,matricula,oferta_id',
            'matricula.persona:id,nombre,primer_apellido,segundo_apellido,curp',
            'matricula.oferta:id,programa_academico_id,campus_id',
            'matricula.oferta.programaAcademico:id,nombre',
            'matricula.oferta.campus:id,nombre',
            'tipoProceso:id,nombre',
            'organizacion:id,razon_social,nombre_comercial',
            'liberaciones:id,expediente_id,folio,corregida_en',
        ]);
    }

    public function llavePrimaria(): string
    {
        return 'expedientes_proceso.id';
    }
}
