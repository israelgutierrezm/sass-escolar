<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\SituacionPago;
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
use App\Services\Finanzas\SaldosDeCartera;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La CARTERA: quién debe cuánto.
 *
 * ── Una fila es una MATRÍCULA, no una persona ni un cargo ────────────────
 * Quien estudia dos carreras aparece dos veces y cada una debe lo suyo, igual
 * que en el historial académico. Y el saldo de la fila es la SUMA de sus cargos
 * abiertos, así que sumar la columna de una página NO da la cartera de la
 * escuela: da la de los que se están viendo. Para preguntar por CONCEPTO
 * —«cuánto se cobró de colegiaturas»— hace falta el otro grano, y por eso
 * existe {@see Adeudos} aparte.
 *
 * ── El saldo NO se recalcula aquí ────────────────────────────────────────
 * Sale de {@see SaldosDeCartera::porMatricula()}, que es la ÚNICA definición de
 * «cuánto se debe» del sistema. Ese servicio existe porque la agregación había
 * estado escrita dos veces y ya había divergido —una con `whereNotNull` y la
 * otra sin él—; escribirla aquí una tercera vez sería exactamente el defecto
 * que vino a cerrar.
 *
 * ── LA TRAMPA DEL ORDEN, y hay que entenderla antes de tocar esta clase ──
 * `f.saldo` y `f.vencido` llegan por un LEFT JOIN y son **NULL** para toda
 * matrícula sin cargos abiertos —30 de las 32 del demo—. El recorrido por lotes
 * compara el ATRIBUTO del último modelo contra la COLUMNA del SQL, así que la
 * columna que se declare `ordenable` tiene que viajar al SELECT **sin
 * transformar**: con `coalesce(f.saldo, 0) as saldo` el cursor leería 0 y
 * compararía contra NULL, la rama de nulos no se activaría nunca y la
 * exportación saldría corta, en un archivo que abre perfectamente.
 *
 * Por eso el `coalesce` vive en la closure `valor` —que es presentación— y el
 * SELECT lleva `f.saldo` crudo. Es la misma clase de defecto que ya costó dos
 * arreglos al motor.
 */
class Cartera implements FuenteAgrupable, FuenteDeReporte
{
    public function __construct(private readonly SaldosDeCartera $saldos) {}

    public function clave(): string
    {
        return 'cartera';
    }

    public function titulo(): string
    {
        return 'Cartera por alumno';
    }

    public function grano(): string
    {
        return 'Una fila es una MATRÍCULA con su saldo. Quien estudia dos carreras aparece dos veces, '
            .'y el saldo de la fila es la suma de sus cargos abiertos — no lo desglosa por concepto.';
    }

    public function permiso(): string
    {
        return 'ver-adeudos';
    }

    /**
     * NINGUNA fuente de finanzas declara módulo, y no es un descuido.
     *
     * `finanzas` existe en el catálogo `modulos` pero NO tiene fila en
     * `modulos_activos`, y `ModulosDeLaEscuela::activo()` falla cerrado. Las
     * rutas de `/finanzas` no llevan `modulo:` middleware —por eso funcionan—,
     * pero el ejecutor SÍ lo comprobaría y devolvería 404 en todos los reportes
     * de finanzas de esta escuela. Es la «trampa latente» de las secciones
     * núcleo que CLAUDE.md ya dejó anotada, aquí servida.
     */
    public function modulo(): ?string
    {
        return null;
    }

    /**
     * Sólo personal.
     *
     * `ver-adeudos` es también de la faceta ALUMNO y de la de PADRE —ahí
     * `VeLaCarteraDelAlumno` lo acota a las matrículas propias—, así que esta
     * lista es lo único que separa «la cartera de la escuela» de «mi estado de
     * cuenta».
     */
    public function facetas(): array
    {
        return ['administrativo'];
    }

    /** Igual que `Matriculas`: `matricula_oferta` llega al campus por su oferta. */
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
            ),
            'carrera' => new ColumnaReporte(
                clave: 'carrera',
                etiqueta: 'Carrera',
                valor: fn (MatriculaOferta $m) => $m->oferta?->carrera?->nombre,
                ancho: 32,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (MatriculaOferta $m) => $m->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'situacion' => new ColumnaReporte(
                clave: 'situacion',
                etiqueta: 'Situación escolar',
                valor: fn (MatriculaOferta $m) => $m->situacion?->nombre,
                ancho: 16,
            ),
            'saldo' => new ColumnaReporte(
                clave: 'saldo',
                etiqueta: 'Saldo',
                tipo: TipoDato::Dinero,
                // El `coalesce` va AQUÍ y no en el SQL: ver la trampa del orden
                // en el docblock de la clase.
                valor: fn (MatriculaOferta $m) => (float) ($m->saldo ?? 0),
                columnaSql: 'f.saldo',
                ordenable: true,
                ancho: 12,
                /*
                 * Se suma, y el resultado ES la cartera de lo consultado.
                 *
                 * Cada adeudo abierto cae en UNA sola fila —`porMatricula()`
                 * agrupa por `matricula_oferta_id`—, así que sumar las filas no
                 * cuenta ningún cargo dos veces. La advertencia del docblock de
                 * la clase es sobre sumar LA PÁGINA; el motor agrega sobre la
                 * consulta entera ya filtrada y recortada, que es otra cosa.
                 *
                 * Lo que este total NO incluye es la deuda de los ASPIRANTES:
                 * el servicio la deja fuera por construcción porque no tiene
                 * matrícula donde caer. Para «cuánto se le debe a la escuela»
                 * está `SaldosDeCartera::totalDeLaEscuela()`.
                 */
                total: Agregacion::Suma,
            ),
            'vencido' => new ColumnaReporte(
                clave: 'vencido',
                etiqueta: 'Vencido',
                tipo: TipoDato::Dinero,
                valor: fn (MatriculaOferta $m) => (float) ($m->vencido ?? 0),
                columnaSql: 'f.vencido',
                ordenable: true,
                ancho: 12,
                ayuda: 'Lo que ya pasó su fecha de vencimiento HOY. Deber no es lo mismo que deber tarde.',
                // Misma partición que el saldo, y es la mitad de la pregunta que
                // se le hace a una cartera: no «cuánto se debe» sino «cuánto de
                // eso ya está tarde».
                total: Agregacion::Suma,
            ),
            'cargos_abiertos' => new ColumnaReporte(
                clave: 'cargos_abiertos',
                etiqueta: 'Cargos',
                tipo: TipoDato::Entero,
                valor: fn (MatriculaOferta $m) => (int) ($m->adeudos ?? 0),
                columnaSql: 'f.adeudos',
                ordenable: true,
                ancho: 8,
                ayuda: 'Cuántos cargos siguen abiertos, sin importar de qué concepto.',
                /*
                 * Este conteo SÍ se suma, al revés que el de `docentes.grupos`.
                 *
                 * Aquélla es una cuenta sobre una relación de muchos a muchos y
                 * su suma da parejas docente-grupo. Aquí un adeudo pertenece a
                 * UNA matrícula —`porMatricula()` agrupa por ella y descarta los
                 * de aspirante—, así que las filas PARTEN el conjunto y sumarlas
                 * devuelve el número de cargos abiertos, sin repetir ninguno.
                 *
                 * Ojo al cuadrar contra `SaldosDeCartera` a mano: este total
                 * cuenta lo de las matrículas que EXISTEN, y el servicio cuenta
                 * también lo de las huérfanas. En el demo hay un adeudo colgado
                 * de la matrícula 288, que ya no está —una de las filas rotas
                 * que reporta `acadion:auditar-datos`—, así que el servicio dice
                 * 5 cargos y el reporte 4. No es un error de la suma: es que esa
                 * fila no tiene alumno, carrera ni campus que enseñar, y el
                 * `left join` la descarta de las filas y del pie a la vez.
                 */
                total: Agregacion::Suma,
            ),
            'situacion_financiera' => new ColumnaReporte(
                clave: 'situacion_financiera',
                etiqueta: 'Situación financiera',
                valor: fn (MatriculaOferta $m) => $m->situacion_financiera,
                ancho: 20,
                ayuda: 'La vigente en su bitácora. Es la que decide si se le bloquea, no el saldo.',
            ),
            'bloqueado' => new ColumnaReporte(
                clave: 'bloqueado',
                etiqueta: '¿Bloqueado?',
                tipo: TipoDato::Booleano,
                valor: fn (MatriculaOferta $m) => (bool) $m->bloquea,
                ancho: 10,
                ayuda: 'Lo dice la bandera `bloquea` de su situación financiera, NO el saldo: una escuela '
                    .'puede deber y no estar bloqueada, y al revés.',
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
            'situacion_pago_id' => new FiltroReporte(
                clave: 'situacion_pago_id',
                etiqueta: 'Situación financiera',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('sf.situacion_id', $v),
                opciones: fn (Usuario $u) => SituacionPago::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'solo_con_saldo' => new FiltroReporte(
                clave: 'solo_con_saldo',
                etiqueta: 'Sólo quien debe',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->where('f.saldo', '>', 0) : $q,
            ),
            'solo_vencido' => new FiltroReporte(
                clave: 'solo_vencido',
                etiqueta: 'Sólo con vencido',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->where('f.vencido', '>', 0) : $q,
            ),
            'solo_bloqueados' => new FiltroReporte(
                clave: 'solo_bloqueados',
                etiqueta: 'Sólo bloqueados',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->where('sp.bloquea', true) : $q,
                ayuda: 'Por la bandera de su situación financiera, no por el saldo.',
            ),
        ];
    }

    /**
     * El saldo entra por JOIN y la situación financiera por SUBCONSULTA.
     *
     * Son dos formas distintas a propósito:
     *
     *  - El saldo ya viene AGREGADO por matrícula desde el servicio, así que un
     *    `leftJoinSub` no multiplica filas: hay a lo sumo una por matrícula.
     *  - La situación financiera es la ÚLTIMA de una bitácora que tiene varias
     *    filas por matrícula. Un join ahí multiplicaría —una matrícula con
     *    cuatro cambios de situación saldría cuatro veces— y ése es el error de
     *    conteo que no avisa. Va como subconsulta correlacionada.
     */
    /**
     * Por qué se puede agrupar la cartera.
     *
     * Es la fuente donde agrupar de verdad paga: «cuánto se debe por campus» y
     * «por carrera» son las dos preguntas con las que se arma una junta de
     * dirección, y hasta ahora había que exportar las 32 filas y sumarlas en
     * Excel.
     *
     * Y aquí las MEDIDAS son dinero —saldo y vencido—, así que el agrupado
     * enseña importes por grupo y no sólo cuántos son. En `Matriculas` no hay
     * ninguna columna sumable y el agrupado sale con puros conteos, que para esa
     * pregunta es lo correcto.
     *
     * `situacion_financiera` NO se ofrece a propósito: sale de la bitácora por
     * su último renglón, con una subconsulta correlacionada, y meterla en un
     * `GROUP BY` la evaluaría una vez por fila del grupo. La respuesta que da
     * hoy la columna es la misma; lo que no vale la pena es el agrupado.
     */
    public function dimensiones(): array
    {
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
                ayuda: 'Cuánto se debe en cada plantel. El campus sale de la OFERTA, que es donde estudia.',
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
                ayuda: 'Quien estudia dos carreras aporta a las dos: una fila es una MATRÍCULA, no una persona.',
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
                ayuda: 'Separa lo que deben los activos de lo que deben los dados de baja, que se cobra distinto.',
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        $hoy = now()->toDateString();

        return MatriculaOferta::query()
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp',
                'oferta:id,carrera_id,campus_id',
                'oferta.carrera:id,nombre',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->leftJoinSub(
                $this->saldos->porMatricula($hoy),
                'f',
                'f.matricula_oferta_id',
                '=',
                'matricula_oferta.id',
            )
            /*
             * La bitácora, por su ÚLTIMO renglón.
             *
             * El desempate por `id` no es adorno: dos cambios pueden compartir
             * `momento` y sin él «la vigente» sería la que saliera primero, que
             * en MySQL no está definida.
             */
            ->leftJoinSub(
                DB::table('bitacora_situacion_financiera as b1')
                    ->whereNull('b1.deleted_at')
                    ->whereRaw('b1.id = (
                        select b2.id from bitacora_situacion_financiera b2
                        where b2.matricula_oferta_id = b1.matricula_oferta_id
                          and b2.deleted_at is null
                        order by b2.momento desc, b2.id desc
                        limit 1
                    )')
                    ->select('b1.matricula_oferta_id', 'b1.situacion_id'),
                'sf',
                'sf.matricula_oferta_id',
                '=',
                'matricula_oferta.id',
            )
            ->leftJoin('situaciones_pago as sp', 'sp.id', '=', 'sf.situacion_id')
            /*
             * El SELECT lleva `f.saldo` y `f.vencido` SIN transformar.
             *
             * Ver la trampa del orden en el docblock de la clase: envolverlas en
             * un `coalesce` aquí rompe el recorrido por lotes en silencio.
             */
            ->select([
                'matricula_oferta.*',
                'f.saldo',
                'f.vencido',
                'f.adeudos',
                'sp.nombre as situacion_financiera',
                'sp.bloquea as bloquea',
            ]);
    }

    public function llavePrimaria(): string
    {
        return 'matricula_oferta.id';
    }
}
