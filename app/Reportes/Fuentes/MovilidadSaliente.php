<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Identidad\Usuario;
use App\Models\Movilidad\ConvocatoriaMovilidad;
use App\Models\Movilidad\EtapaMovilidad;
use App\Models\Movilidad\PostulacionMovilidad;
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
 * MOVILIDAD SALIENTE: a quién manda la escuela, y cómo le fue.
 *
 * ── Una fila es una POSTULACIÓN, con su estancia si la tiene ─────────────
 * Quien se postuló a dos convocatorias son dos filas. La estancia es `hasOne`,
 * así que no multiplica, y las revalidaciones entran como CONTEO: desplegarlas
 * convertiría a quien revalidó ocho materias en ocho postulaciones.
 *
 * ── Sólo SALIENTES, y por qué no es un recorte cobarde ───────────────────
 * `postulaciones_movilidad` tiene titular DUAL con CHECK: un saliente es una
 * MATRÍCULA nuestra y un entrante es una persona externa. Y ahí está la
 * asimetría que decide esta fuente: **un saliente llega al campus por su
 * oferta; un entrante no tiene campus nuestro por ningún camino** —ni la
 * convocatoria ni el convenio ni la institución aliada tienen columna de
 * campus—.
 *
 * Mezclarlos obligaría a declarar `sinCampus`, que **lanza 403 a todo rol
 * acotado a un plantel**: un coordinador de campus se quedaría sin el área
 * entera para poder ver a los entrantes, que ni siquiera son suyos. Es el mismo
 * razonamiento que separó el dinero de los aspirantes del de las matrículas en
 * finanzas: dos ramas que llegan al campus por caminos incompatibles no caben
 * en una fuente.
 *
 * Los ENTRANTES quedan fuera y se dice en el grano. Su fuente, si hace falta,
 * es otra —con `sinCampus` y su razón escrita, que ahí sí es correcto: de
 * verdad no se pueden acotar—.
 *
 * ── El demo tiene el módulo VACÍO ────────────────────────────────────────
 * Cero convenios, cero convocatorias, cero postulaciones y cero estancias. La
 * suite construye su escenario dentro de la transacción.
 */
class MovilidadSaliente implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'movilidad-saliente';
    }

    public function titulo(): string
    {
        return 'Movilidad saliente';
    }

    public function grano(): string
    {
        return 'Una fila es una POSTULACIÓN de un alumno NUESTRO. Quien se postuló a dos convocatorias '
            .'sale dos veces. NO incluye a los alumnos ENTRANTES: no tienen matrícula nuestra ni campus '
            .'por donde acotarlos.';
    }

    public function permiso(): string
    {
        return 'gestionar-movilidad';
    }

    public function modulo(): ?string
    {
        return 'movilidad';
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /** El saliente llega al campus por la oferta de su matrícula. */
    public function recorte(): Recorte
    {
        return Recorte::porOferta('matricula');
    }

    public function columnas(): array
    {
        return [
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                valor: fn (PostulacionMovilidad $p) => $p->matricula?->matricula,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (PostulacionMovilidad $p) => $p->matricula?->persona?->nombreCompleto(),
                ancho: 32,
            ),
            'programa_academico' => new ColumnaReporte(
                clave: 'programa_academico',
                etiqueta: 'Programa académico',
                valor: fn (PostulacionMovilidad $p) => $p->matricula?->oferta?->programaAcademico?->nombre,
                ancho: 30,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (PostulacionMovilidad $p) => $p->matricula?->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'convocatoria' => new ColumnaReporte(
                clave: 'convocatoria',
                etiqueta: 'Convocatoria',
                valor: fn (PostulacionMovilidad $p) => $p->convocatoria?->titulo,
                ancho: 30,
            ),
            'institucion' => new ColumnaReporte(
                clave: 'institucion',
                etiqueta: 'Institución destino',
                valor: fn (PostulacionMovilidad $p) => $p->convocatoria?->convenio?->institucion?->nombre,
                ancho: 32,
            ),
            'periodo' => new ColumnaReporte(
                clave: 'periodo',
                etiqueta: 'Periodo',
                valor: fn (PostulacionMovilidad $p) => $p->convocatoria?->periodo,
                ancho: 14,
            ),
            'etapa' => new ColumnaReporte(
                clave: 'etapa',
                etiqueta: 'Etapa',
                valor: fn (PostulacionMovilidad $p) => $p->etapa?->nombre,
                ancho: 16,
            ),
            'acepta' => new ColumnaReporte(
                clave: 'acepta',
                etiqueta: '¿Ocupa lugar?',
                tipo: TipoDato::Booleano,
                // La BANDERA del catálogo, no la clave: quien está en curso o
                // concluyó sigue ocupando su plaza. Contando sólo la etapa
                // llamada «aceptado», el cupo se liberaría en cuanto alguien
                // avanzara y la escuela mandaría a dos personas al mismo lugar.
                valor: fn (PostulacionMovilidad $p) => (bool) $p->etapa?->acepta,
                ancho: 12,
            ),
            'promedio' => new ColumnaReporte(
                clave: 'promedio',
                etiqueta: 'Promedio acreditado',
                tipo: TipoDato::Decimal,
                valor: fn (PostulacionMovilidad $p) => $p->promedio_acreditado,
                columnaSql: 'postulaciones_movilidad.promedio_acreditado',
                ordenable: true,
                ancho: 14,
                ayuda: 'CONGELADO al postularse. No se recalcula: sería una tercera verdad sobre el '
                    .'promedio de un alumno, y tecleado sería un número que alguien puede acomodar. '
                    .'No se totaliza: es un promedio YA CALCULADO por alumno, así que sumarlo no da '
                    .'ninguna cifra y promediarlo sin ponderar mezclaría medias sacadas de distinto '
                    .'número de materias, contando además dos veces a quien se postuló dos veces.',
                /*
                 * Y aquí la duplicación no es hipotética: una fila es una
                 * POSTULACIÓN, no un alumno, así que quien va a dos
                 * convocatorias pesa el doble en cualquier media. «Qué promedio
                 * traen los que mandamos» es una pregunta legítima, pero se
                 * contesta sobre personas y ponderando, no con `avg()` sobre
                 * este renglón.
                 */
                total: Agregacion::Ninguno,
            ),
            'postulada_en' => new ColumnaReporte(
                clave: 'postulada_en',
                etiqueta: 'Se postuló',
                tipo: TipoDato::Fecha,
                valor: fn (PostulacionMovilidad $p) => $p->fecha_postulacion,
                columnaSql: 'postulaciones_movilidad.fecha_postulacion',
                ordenable: true,
                ancho: 12,
            ),
            'estancia_inicio' => new ColumnaReporte(
                clave: 'estancia_inicio',
                etiqueta: 'Inicio de estancia',
                tipo: TipoDato::Fecha,
                valor: fn (PostulacionMovilidad $p) => $p->estancia?->fecha_inicio,
                ancho: 13,
            ),
            'estancia_concluida' => new ColumnaReporte(
                clave: 'estancia_concluida',
                etiqueta: 'Concluyó',
                tipo: TipoDato::Fecha,
                valor: fn (PostulacionMovilidad $p) => $p->estancia?->concluida_en,
                ancho: 12,
                ayuda: 'Sólo con la estancia CONCLUIDA se pueden revalidar sus materias: mientras siga '
                    .'en curso, las calificaciones de allá todavía pueden cambiar.',
            ),
            'revalidaciones' => new ColumnaReporte(
                clave: 'revalidaciones',
                etiqueta: 'Revalidadas',
                tipo: TipoDato::Entero,
                valor: fn (PostulacionMovilidad $p) => (int) ($p->revalidaciones ?? 0),
                columnaSql: 'rev.revalidaciones',
                ordenable: true,
                ancho: 12,
                ayuda: 'Materias que se le asentaron en su historial por esta estancia. Las revocadas no '
                    .'cuentan: se dan de baja lógica y se conservan como historia escolar.',
                /*
                 * Aquí SÍ se suma, al revés que los conteos que no se suman
                 * entre sí: una revalidación cuelga de UNA estancia y una
                 * estancia de UNA postulación, así que los conteos por fila
                 * reparten sin repetir y el pie son las materias revalidadas
                 * del conjunto consultado —no parejas de nada—.
                 *
                 * Se agrega por `columnaSql`, que es el alias de la subconsulta
                 * YA AGRUPADA que arma `consulta()`: contar ahí y sumar aquí es
                 * lo mismo que contar una sola vez.
                 */
                total: Agregacion::Suma,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'convocatoria_id' => new FiltroReporte(
                clave: 'convocatoria_id',
                etiqueta: 'Convocatoria',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('postulaciones_movilidad.convocatoria_id', $v),
                opciones: fn (Usuario $u) => ConvocatoriaMovilidad::query()
                    ->orderByDesc('id')->pluck('titulo', 'id')->all(),
            ),
            'etapa_id' => new FiltroReporte(
                clave: 'etapa_id',
                etiqueta: 'Etapa',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('postulaciones_movilidad.etapa_id', $v),
                opciones: fn (Usuario $u) => EtapaMovilidad::query()->orderBy('id')->pluck('nombre', 'id')->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matricula.oferta',
                    fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'solo_aceptados' => new FiltroReporte(
                clave: 'solo_aceptados',
                etiqueta: 'Sólo los que ocupan lugar',
                tipo: TipoFiltro::Booleano,
                // Por la bandera del catálogo, que es lo que decide el cupo.
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereHas('etapa', fn (Builder $e) => $e->where('acepta', true))
                    : $q,
            ),
            'solo_concluidas' => new FiltroReporte(
                clave: 'solo_concluidas',
                etiqueta: 'Sólo estancias concluidas',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereHas('estancia', fn (Builder $e) => $e->whereNotNull('concluida_en'))
                    : $q,
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return PostulacionMovilidad::query()
            ->select('postulaciones_movilidad.*')
            // SÓLO salientes: ver el docblock de la clase.
            ->whereNotNull('postulaciones_movilidad.matricula_oferta_id')
            ->with([
                'matricula:id,persona_id,oferta_id,matricula',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'matricula.oferta:id,programa_academico_id,campus_id',
                'matricula.oferta.programaAcademico:id,nombre',
                'matricula.oferta.campus:id,nombre',
                'convocatoria:id,convenio_id,titulo,periodo',
                'convocatoria.convenio:id,institucion_aliada_id',
                'convocatoria.convenio.institucion:id,nombre',
                'etapa:id,nombre,acepta',
                'estancia',
            ])
            /*
             * Las revalidaciones, AGRUPADAS por estancia y contadas.
             *
             * Cuelgan de la ESTANCIA y son a-muchos: quien revalidó ocho
             * materias saldría ocho veces con un join en crudo. Y las revocadas
             * no cuentan —se dan de baja lógica y se conservan como historia—.
             */
            ->leftJoin('estancias as es', 'es.postulacion_id', '=', 'postulaciones_movilidad.id')
            ->leftJoinSub(
                DB::table('revalidaciones as r')
                    ->whereNull('r.deleted_at')
                    ->select('r.estancia_id')
                    ->selectRaw('count(*) as revalidaciones')
                    ->groupBy('r.estancia_id'),
                'rev',
                'rev.estancia_id',
                '=',
                'es.id',
            )
            ->addSelect(['rev.revalidaciones']);
    }

    public function llavePrimaria(): string
    {
        return 'postulaciones_movilidad.id';
    }
}
