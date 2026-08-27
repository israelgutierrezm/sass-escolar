<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use App\Services\EstadoCertificacion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién puede CERTIFICARSE, y a quién le falta qué.
 *
 * ── Una fila es una MATRÍCULA-CARRERA ────────────────────────────────────
 * Un certificado es de una carrera: quien estudia dos se certifica dos veces,
 * con su propio avance y su propio faltante. Misma regla vertebral que el
 * historial académico y la credencial.
 *
 * ── Nada se reimplementa: las reglas salen de `EstadoCertificacion` ──────
 * «Cerró su plan», «cuántas materias exige el plan» y «ya está ocupada por una
 * certificación» se declaran ahí, y ahí viven también sus versiones en SQL. Esta
 * fuente las UNE; no las reescribe. La razón es medible: resolver la
 * elegibilidad fila por fila con los métodos del servicio cuesta **64 consultas
 * para 32 matrículas** —dos por fila—, y sobre tres mil son seis mil por cada
 * pintado y por cada lote de una exportación.
 *
 * Que las dos formas digan lo mismo lo fija la suite, comparándolas matrícula
 * por matrícula: si alguien toca una y no la otra, se pone roja.
 *
 * ── Lo que NO se ofrece, y con su medición ───────────────────────────────
 * **La lista de qué le falta a cada quien para firmar.** La sabe `ValidadorDec`,
 * pero su única entrada pública es `validarLote()` —exige un LOTE— y por dentro
 * construye el XML y lo valida contra el XSD. Medido: 38 ms por matrícula cuando
 * corta pronto y **255 ms cuando llega a armar el XML**. En una exportación de
 * mil filas son más de cuatro minutos, y en pantalla se nota. Reimplementar sus
 * reglas aquí sería peor: el XML lo valida el XSD oficial y una segunda opinión
 * que discrepe manda un lote a la SEP creyéndolo bueno.
 *
 * Lo que SÍ se ofrece son las condiciones baratas y ciertas —cerró el plan, la
 * carrera expide documentos, ya está en un lote, y el identificador del campus—,
 * que hoy explican todos los bloqueos del demo.
 *
 * ── El identificador del campus no es un adorno ──────────────────────────
 * Comprobado end to end: los tres campus del demo lo tienen en NULL, y por eso
 * `ValidadorDec::validarLote(13)` devuelve OCHO errores, uno por renglón. O sea
 * que hoy no se puede certificar a nadie, y el motivo es un dato de catálogo que
 * ninguna pantalla reclamaba. Por eso viaja como columna: es la casilla que hay
 * que llenar antes de que nada más importe.
 */
class Certificables implements FuenteDeReporte
{
    public function __construct(private readonly EstadoCertificacion $estado) {}

    public function clave(): string
    {
        return 'certificables';
    }

    public function titulo(): string
    {
        return 'Avance para certificar';
    }

    public function grano(): string
    {
        return 'Una fila es una MATRÍCULA-CARRERA: quien estudia dos programas se certifica dos veces, '
            .'con su propio avance.';
    }

    public function permiso(): string
    {
        return 'certificar-alumnos';
    }

    /**
     * Sin módulo, y comprobado: las rutas de certificación NO llevan middleware
     * `modulo:` —sólo `can:`—, a diferencia de bolsa, movilidad o disciplina.
     * Declararlo aquí devolvería 404 en una escuela donde la sección funciona.
     */
    public function modulo(): ?string
    {
        return null;
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

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
                sensible: true,
                permisoExtra: 'editar-alumnos',
                ancho: 20,
                ayuda: 'Viaja en el XML del certificado: sin ella la SEP lo rechaza.',
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
                ancho: 22,
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
            'aprobadas' => new ColumnaReporte(
                clave: 'aprobadas',
                etiqueta: 'Aprobadas',
                tipo: TipoDato::Entero,
                valor: fn (MatriculaOferta $m) => (int) ($m->aprobadas ?? 0),
                columnaSql: 'apr.aprobadas',
                ordenable: true,
                ancho: 10,
                ayuda: 'Materias DISTINTAS del plan aprobadas: un recursamiento aprobado dos veces cuenta una.',
                /*
                 * Sí se suma, al revés que el «Alumnos» de la fuente de grupos.
                 *
                 * Aquel conteo cuenta parejas porque el mismo alumno se le cuenta
                 * a varios grupos. Aquí lo que se cuenta —una materia del plan
                 * aprobada— cuelga de UNA matrícula (`historial.matricula_oferta_id`),
                 * así que ninguna aprobación se cuenta dos veces y el pie dice
                 * cuántas materias lleva aprobadas en total el conjunto
                 * consultado. Y el `count(distinct)` de `apr` va agrupado por
                 * matrícula y unido uno a uno, así que el join no multiplica
                 * filas ni infla la suma.
                 */
                total: Agregacion::Suma,
            ),
            'meta' => new ColumnaReporte(
                clave: 'meta',
                etiqueta: 'Exige el plan',
                tipo: TipoDato::Entero,
                valor: fn (MatriculaOferta $m) => (int) ($m->meta ?? 0),
                columnaSql: 'mp.meta',
                ordenable: true,
                ancho: 12,
                ayuda: 'Su `minimo_asignaturas`, o el número de materias de su malla si no lo fija. '
                    .'No se totaliza: es el umbral DEL PLAN y se repite igual en cada renglón de ese '
                    .'plan, así que sumarlo cuenta el plan una vez por alumno inscrito. Por eso la '
                    .'columna de al lado sí lleva pie y ésta no: el total de «Aprobadas» no es «de» '
                    .'ningún total de aquí.',
                /*
                 * Y promediarla tampoco: la media saldría ponderada por cuántos
                 * alumnos tiene cada plan, así que un plan con muchos inscritos
                 * arrastraría la cifra y nadie la podría explicar. Lo que exige
                 * un plan se lee plan por plan, en su renglón.
                 */
                total: Agregacion::Ninguno,
            ),
            'cerro_plan' => new ColumnaReporte(
                clave: 'cerro_plan',
                etiqueta: '¿Cerró el plan?',
                tipo: TipoDato::Booleano,
                valor: fn (MatriculaOferta $m) => (int) ($m->meta ?? 0) > 0
                    && (int) ($m->aprobadas ?? 0) >= (int) $m->meta,
                ancho: 13,
                ayuda: 'Es el requisito ACADÉMICO y nada más. Que la carrera expida documentos se pregunta aparte.',
            ),
            'emite_documentos' => new ColumnaReporte(
                clave: 'emite_documentos',
                etiqueta: '¿Expide papel?',
                tipo: TipoDato::Booleano,
                // Del servicio, que además decide qué contestar ante la duda.
                valor: fn (MatriculaOferta $m) => $this->estado->emiteDocumentos($m),
                ancho: 13,
                ayuda: 'Un diplomado vive en el mismo catálogo de carreras y no tiene RVOE que respalde '
                    .'papel oficial: cerrar su plan no lo vuelve certificable.',
            ),
            'ya_en_lote' => new ColumnaReporte(
                clave: 'ya_en_lote',
                etiqueta: '¿Ya en trámite?',
                tipo: TipoDato::Booleano,
                valor: fn (MatriculaOferta $m) => $m->certificacion_id !== null,
                ancho: 13,
                ayuda: 'Tiene certificado emitido o pendiente en un lote. Lo que quedó en ERROR no ocupa: '
                    .'se puede reintentar en otro lote.',
            ),
            'campus_identificador' => new ColumnaReporte(
                clave: 'campus_identificador',
                etiqueta: 'Id. del campus',
                valor: fn (MatriculaOferta $m) => $m->oferta?->campus?->identificador,
                ancho: 14,
                ayuda: 'OBLIGATORIO para firmar. Sin él el lote entero se detiene, y ninguna otra pantalla '
                    .'lo reclama: en el demo los tres campus lo tienen vacío y por eso hoy no se puede '
                    .'certificar a nadie.',
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'oferta',
                    fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
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
                aplicar: fn (Builder $q, string $v) => $q->where('matricula_oferta.generacion', $v),
            ),
            'cerro_plan' => new FiltroReporte(
                clave: 'cerro_plan',
                etiqueta: 'Sólo quien cerró su plan',
                tipo: TipoFiltro::Booleano,
                // La MISMA regla del servicio: meta > 0 Y aprobadas >= meta. El
                // `meta > 0` no sobra: sin él, un plan sin materias daría por
                // cerrado a quien no aprobó nada.
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereRaw('coalesce(mp.meta, 0) > 0 and coalesce(apr.aprobadas, 0) >= mp.meta')
                    : $q,
            ),
            'con_avance_sin_cerrar' => new FiltroReporte(
                clave: 'con_avance_sin_cerrar',
                etiqueta: 'Sólo con avance sin cerrar',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereRaw('coalesce(apr.aprobadas, 0) > 0
                        and (coalesce(mp.meta, 0) = 0 or coalesce(apr.aprobadas, 0) < mp.meta)')
                    : $q,
                ayuda: 'Los del certificado PARCIAL: avanzaron y todavía no cierran.',
            ),
            'expide_documentos' => new FiltroReporte(
                clave: 'expide_documentos',
                etiqueta: 'Sólo carreras que expiden papel',
                tipo: TipoFiltro::Booleano,
                /*
                 * La TERCERA condición de `elegibleParaLote()`, y faltaba.
                 *
                 * El reporte de «listos» tenía sólo dos —cerró el plan y no está
                 * en trámite— así que ofrecía a quien cursa un diplomado, que no
                 * tiene RVOE que respalde un certificado. No se vio hasta que la
                 * suite SEMBRÓ una carrera sin papel: en el demo todas expiden,
                 * y la comprobación «el reporte trae lo mismo que el servicio»
                 * pasaba por eso.
                 *
                 * El `or is null` traduce el `?? true` del servicio: ante la
                 * duda se responde que sí, para que una persona decida en vez de
                 * que el alumno desaparezca sin que nadie se entere.
                 */
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereHas('oferta.carrera', fn (Builder $c) => $c
                        ->where(fn (Builder $x) => $x->where('emite_documentos_oficiales', true)
                            ->orWhereNull('emite_documentos_oficiales')))
                    : $q,
            ),
            'sin_tramite' => new FiltroReporte(
                clave: 'sin_tramite',
                etiqueta: 'Sólo sin certificado ni lote',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->whereNull('oc.certificacion_id') : $q,
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return MatriculaOferta::query()
            ->select('matricula_oferta.*')
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp',
                'oferta:id,carrera_id,plan_id,campus_id',
                'oferta.carrera:id,nombre,emite_documentos_oficiales',
                'oferta.plan:id,nombre',
                'oferta.campus:id,nombre,identificador',
            ])
            // Las tres reglas del servicio, en su forma agrupada. Ninguna se
            // reescribe aquí: se unen.
            ->leftJoinSub($this->estado->aprobadasPorMatricula(), 'apr',
                'apr.matricula_oferta_id', '=', 'matricula_oferta.id')
            ->leftJoin('oferta as of_meta', 'of_meta.id', '=', 'matricula_oferta.oferta_id')
            ->leftJoinSub($this->estado->metaPorPlanConsulta(), 'mp',
                'mp.plan_id', '=', 'of_meta.plan_id')
            ->leftJoinSub($this->estado->ocupadasPorCertificacion(), 'oc',
                'oc.matricula_oferta_id', '=', 'matricula_oferta.id')
            ->addSelect(['apr.aprobadas', 'mp.meta', 'oc.certificacion_id']);
    }

    public function llavePrimaria(): string
    {
        return 'matricula_oferta.id';
    }
}
