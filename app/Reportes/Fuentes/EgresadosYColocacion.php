<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
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
 * EGRESADOS y su colocación: el número que una escuela presenta.
 *
 * ── Una fila es una MATRÍCULA EGRESADA — el DENOMINADOR ──────────────────
 * Quien egresó de dos programas académicos egresó de las dos y sale dos veces: cada
 * programa reporta lo suyo. Y quien cambió de trabajo tres veces sigue siendo
 * UN egresado colocado, porque la colocación entra como conteo y no como filas.
 * Sin esa distinción el porcentaje puede pasar del 100 %.
 *
 * ── El denominador sale del CATÁLOGO, no de una lista de claves ──────────
 * Quién cuenta como egresado lo dice `situaciones_alumno.cuenta_como_egresado`,
 * leído por `SituacionAlumno::deEgresados()` — el mismo scope que usa
 * `IndicadorEmpleabilidad`. Una escuela que agregue «Pasante» decide sola si
 * entra al porcentaje, sin tocar código.
 *
 * ── Lo que este reporte NO puede contar, y lo dice ───────────────────────
 * Las colocaciones **sin matrícula señalada** no aparecen: no se pueden
 * atribuir a ningún programa, y repartirlas exigiría inventarle un programa académico a
 * alguien. Tampoco las de quien todavía no egresa —una práctica profesional—.
 * Las dos cifras las da el tablero de empleabilidad; aquí se declaran en el
 * grano para que la diferencia entre lo registrado y lo contado no sea un
 * misterio.
 *
 * ── El demo tiene CERO colocaciones, y es deliberado ─────────────────────
 * Se sembraron para mirar las pantallas en agosto y se retiraron por decisión
 * del cliente. Así que la suite de esta fuente construye su escenario dentro de
 * la transacción y mide POR DIFERENCIA — medir contra cero es lo que ya hizo
 * pasar en verde a dos suites vacías de este proyecto.
 */
class EgresadosYColocacion implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'egresados-colocacion';
    }

    public function titulo(): string
    {
        return 'Egresados y su colocación';
    }

    public function grano(): string
    {
        return 'Una fila es una MATRÍCULA EGRESADA. Quien cambió de trabajo tres veces sale UNA vez. '
            .'NO incluye colocaciones sin matrícula señalada ni de quien todavía no egresa: ésas se '
            .'cuentan aparte en el tablero de empleabilidad.';
    }

    public function permiso(): string
    {
        return 'gestionar-bolsa-trabajo';
    }

    /** Aquí SÍ hay módulo apagable, y las rutas de la bolsa ya lo llevan. */
    public function modulo(): ?string
    {
        return 'bolsa_trabajo';
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
            'egresado' => new ColumnaReporte(
                clave: 'egresado',
                etiqueta: 'Egresado',
                valor: fn (MatriculaOferta $m) => $m->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'programa_academico' => new ColumnaReporte(
                clave: 'programa_academico',
                etiqueta: 'Programa académico',
                valor: fn (MatriculaOferta $m) => $m->oferta?->programaAcademico?->nombre,
                ancho: 32,
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
            'situacion' => new ColumnaReporte(
                clave: 'situacion',
                etiqueta: 'Situación',
                valor: fn (MatriculaOferta $m) => $m->situacion?->nombre,
                ancho: 14,
                ayuda: 'Sólo salen las que la escuela marcó como egreso en su catálogo.',
            ),
            'colocado' => new ColumnaReporte(
                clave: 'colocado',
                etiqueta: '¿Colocado?',
                tipo: TipoDato::Booleano,
                valor: fn (MatriculaOferta $m) => (int) ($m->colocaciones ?? 0) > 0,
                ancho: 11,
            ),
            'colocaciones' => new ColumnaReporte(
                clave: 'colocaciones',
                etiqueta: 'Colocaciones',
                tipo: TipoDato::Entero,
                valor: fn (MatriculaOferta $m) => (int) ($m->colocaciones ?? 0),
                columnaSql: 'col.colocaciones',
                ordenable: true,
                ancho: 12,
                /*
                 * Se SUMA, al revés que `docentes.grupos`, y la diferencia está
                 * en la foránea: una colocación cuelga de UNA matrícula
                 * (`colocaciones.matricula_oferta_id`), así que ningún renglón
                 * comparte colocación con otro y la suma no cuenta parejas —es
                 * el número de empleos registrados a los egresados que se están
                 * viendo—.
                 *
                 * Lo que NO es, y por eso la `ayuda` ya lo advertía antes de que
                 * hubiera pie de tabla: el número de COLOCADOS. Quien cambió de
                 * trabajo dos veces suma dos aquí y sigue siendo un egresado
                 * colocado; ese otro número lo da la columna «¿Colocado?».
                 */
                total: Agregacion::Suma,
                ayuda: 'Cuántas veces se le ha registrado un empleo. Para el indicador cuenta UNA: quien '
                    .'cambió de trabajo dos veces sigue siendo un egresado colocado.',
            ),
            'empresa' => new ColumnaReporte(
                clave: 'empresa',
                etiqueta: 'Empresa (la última)',
                valor: fn (MatriculaOferta $m) => $m->empresa,
                ancho: 30,
            ),
            'puesto' => new ColumnaReporte(
                clave: 'puesto',
                etiqueta: 'Puesto (el último)',
                valor: fn (MatriculaOferta $m) => $m->puesto,
                ancho: 28,
            ),
            'ingreso' => new ColumnaReporte(
                clave: 'ingreso',
                etiqueta: 'Ingresó',
                tipo: TipoDato::Fecha,
                valor: fn (MatriculaOferta $m) => $m->ultimo_ingreso,
                columnaSql: 'col.ultimo_ingreso',
                ordenable: true,
                ancho: 12,
            ),
            'en_su_area' => new ColumnaReporte(
                clave: 'en_su_area',
                etiqueta: '¿De su área?',
                valor: fn (MatriculaOferta $m) => match (true) {
                    $m->en_su_area === null => null,
                    (bool) $m->en_su_area => 'Sí',
                    default => 'No',
                },
                ancho: 12,
                // Tres estados y no dos: `relacionado_con_programa_academico` es NULLABLE a
                // propósito, porque «no se preguntó» no es «no es de su área».
                ayuda: 'En blanco cuando nadie lo capturó: eso NO es un «no».',
            ),
            'por_la_bolsa' => new ColumnaReporte(
                clave: 'por_la_bolsa',
                etiqueta: '¿Por la bolsa?',
                tipo: TipoDato::Booleano,
                valor: fn (MatriculaOferta $m) => (int) ($m->por_la_bolsa ?? 0) > 0,
                ancho: 12,
                ayuda: 'Salió de una postulación de la escuela. Lo demás lo consiguió por su cuenta y la '
                    .'escuela se enteró dándole seguimiento — y eso cuenta igual para la acreditación.',
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'generacion' => new FiltroReporte(
                clave: 'generacion',
                etiqueta: 'Generación',
                tipo: TipoFiltro::Texto,
                aplicar: fn (Builder $q, string $v) => $q->where('matricula_oferta.generacion', $v),
                ayuda: 'Acota las DOS cifras: quiénes egresaron y cuántos de ésos están colocados.',
            ),
            'programa_academico_id' => new FiltroReporte(
                clave: 'programa_academico_id',
                etiqueta: 'Programa académico',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'oferta',
                    fn (Builder $o) => $o->whereIn('programa_academico_id', $v),
                ),
                opciones: fn (Usuario $u) => ProgramaAcademico::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
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
            'solo_colocados' => new FiltroReporte(
                clave: 'solo_colocados',
                etiqueta: 'Sólo los colocados',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->where('col.colocaciones', '>', 0) : $q,
            ),
            'solo_sin_colocar' => new FiltroReporte(
                clave: 'solo_sin_colocar',
                etiqueta: 'Sólo los que faltan por colocar',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where(fn (Builder $x) => $x->whereNull('col.colocaciones')->orWhere('col.colocaciones', '=', 0))
                    : $q,
            ),
        ];
    }

    /**
     * Las colocaciones entran AGRUPADAS por matrícula.
     *
     * Con un join en crudo, quien cambió de trabajo tres veces saldría tres
     * veces y el conteo de «egresados» diría tres donde hay uno — el error que
     * hace que un porcentaje pase del 100 %. Ya agrupadas hay a lo sumo una fila
     * por matrícula.
     *
     * `max(...)` sobre empresa, puesto y área devuelve los de la ÚLTIMA
     * colocación por fecha, y las etiquetas de las columnas lo dicen: enseñar
     * una de las tres sin decir cuál invitaría a leerla como «su trabajo».
     */
    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        // El MISMO criterio del indicador: el catálogo decide quién egresó.
        $deEgreso = SituacionAlumno::query()->deEgresados()->pluck('id');

        return MatriculaOferta::query()
            ->select('matricula_oferta.*')
            ->whereIn('matricula_oferta.situacion_id', $deEgreso)
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido',
                'oferta:id,programa_academico_id,campus_id',
                'oferta.programaAcademico:id,nombre',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->leftJoinSub(
                DB::table('colocaciones as c')
                    ->leftJoin('empresas as e', 'e.id', '=', 'c.empresa_id')
                    ->whereNull('c.deleted_at')
                    /*
                     * Las colocaciones SIN matrícula no hace falta excluirlas:
                     * quedan fuera por construcción, porque el JOIN las une por
                     * `matricula_oferta_id` y un NULL no casa con ninguna fila.
                     *
                     * Llevaba un `whereNotNull` explícito y se retiró al ver que
                     * su mutación sobrevivía: no cambiaba ni una fila. Una
                     * salvaguarda que no salva nada da confianza falsa —el mismo
                     * desenlace que el `if ($diseno->exists)` de los firmantes—.
                     * Lo que sí queda es dicho: la exclusión es estructural, no
                     * un filtro que alguien pueda quitar sin darse cuenta.
                     */
                    ->select('c.matricula_oferta_id')
                    ->selectRaw('count(*) as colocaciones')
                    ->selectRaw('max(c.fecha_ingreso) as ultimo_ingreso')
                    /*
                     * El de la ÚLTIMA colocación, con el truco estándar de
                     * MySQL: se concatenan ordenados y se toma el primero. Un
                     * `max()` daría el alfabéticamente mayor, que no es el
                     * último ni significa nada.
                     *
                     * El separador es un carácter de control (`0x1F`, «unit
                     * separator») y no una coma: una razón social lleva comas y
                     * partiría el valor por la mitad.
                     */
                    ->selectRaw('substring_index(group_concat(
                        e.razon_social order by c.fecha_ingreso desc, c.id desc separator 0x1f
                    ), 0x1f, 1) as empresa')
                    ->selectRaw('substring_index(group_concat(
                        c.puesto order by c.fecha_ingreso desc, c.id desc separator 0x1f
                    ), 0x1f, 1) as puesto')
                    ->selectRaw("nullif(substring_index(group_concat(
                        coalesce(c.relacionado_con_programa_academico, '') order by c.fecha_ingreso desc, c.id desc separator 0x1f
                    ), 0x1f, 1), '') as en_su_area")
                    ->selectRaw('sum(case when c.postulacion_id is not null then 1 else 0 end) as por_la_bolsa')
                    ->groupBy('c.matricula_oferta_id'),
                'col',
                'col.matricula_oferta_id',
                '=',
                'matricula_oferta.id',
            )
            ->addSelect([
                'col.colocaciones', 'col.ultimo_ingreso', 'col.empresa',
                'col.puesto', 'col.en_su_area', 'col.por_la_bolsa',
            ]);
    }

    public function llavePrimaria(): string
    {
        return 'matricula_oferta.id';
    }
}
