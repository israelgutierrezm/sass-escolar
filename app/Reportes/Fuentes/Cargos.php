<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Identidad\Usuario;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use App\Services\Finanzas\SaldosDeCartera;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los CARGOS emitidos: qué se cobró, de qué concepto y por cuánto.
 *
 * ── Una fila es un ADEUDO, no un alumno ──────────────────────────────────
 * Un alumno con tres colegiaturas aparece TRES veces. Es el grano donde vive el
 * CONCEPTO, y por eso es el único sobre el que se puede preguntar «cuánto se
 * cobró de colegiaturas»: en el grano de matrícula esa pregunta no existe,
 * porque el saldo de una matrícula ya viene sumado. {@see Cartera} contesta la
 * otra mitad.
 *
 * ── El titular DUAL obliga a un filtro fijo, y hay que decirlo ────────────
 * `adeudos` tiene `matricula_oferta_id` **o** `aspirante_id`, exactamente uno,
 * con CHECK en MySQL — el aspirante paga su ficha antes de tener matrícula. Los
 * cinco modos de `Recorte` no cubren las dos ramas a la vez: un aspirante NO
 * llega al campus por una oferta, llega por su propia columna. Así que esta
 * fuente se queda con la rama de las matrículas y **lo declara en su grano**,
 * en vez de mezclar dos universos que se acotan por caminos incompatibles.
 *
 * El dinero de los aspirantes tiene su propia fuente. Callarlo sería peor que
 * dejarlo fuera: la diferencia entre este reporte y el total de la escuela
 * quedaría sin explicación, y es exactamente lo que hace que alguien deje de
 * creerle a un tablero.
 *
 * ── Lo aplicado NO se pregunta por fila ──────────────────────────────────
 * `Adeudo::montoAplicado()` hace una consulta por fila: en una exportación de
 * cinco mil cargos son cinco mil consultas, y eso no se nota en pantalla —donde
 * hay veinticinco— sino de madrugada. Va como subconsulta correlacionada, con
 * el criterio de `SaldosDeCartera` y no con uno propio.
 */
class Cargos implements FuenteDeReporte
{
    public function __construct(private readonly SaldosDeCartera $saldos) {}

    public function clave(): string
    {
        return 'cargos';
    }

    public function titulo(): string
    {
        return 'Cargos emitidos';
    }

    public function grano(): string
    {
        return 'Una fila es un CARGO. Un alumno con tres colegiaturas aparece tres veces. '
            .'NO incluye los cargos de ASPIRANTES —todavía no tienen matrícula y se acotan por otro camino—: '
            .'ésos van en «Cobros de aspirantes».';
    }

    public function permiso(): string
    {
        return 'ver-adeudos';
    }

    /** Ver el docblock de {@see Cartera}: `finanzas` está apagado y fallaría cerrado. */
    public function modulo(): ?string
    {
        return null;
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /** `adeudos` no tiene campus: llega por su matrícula y la oferta de ésta. */
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
                valor: fn (Adeudo $a) => $a->matriculaOferta?->matricula,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (Adeudo $a) => $a->matriculaOferta?->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'carrera' => new ColumnaReporte(
                clave: 'carrera',
                etiqueta: 'Carrera',
                valor: fn (Adeudo $a) => $a->matriculaOferta?->oferta?->carrera?->nombre,
                ancho: 30,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (Adeudo $a) => $a->matriculaOferta?->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'concepto' => new ColumnaReporte(
                clave: 'concepto',
                etiqueta: 'Concepto',
                valor: fn (Adeudo $a) => $a->concepto?->nombre,
                ancho: 24,
            ),
            'periodo' => new ColumnaReporte(
                clave: 'periodo',
                etiqueta: 'Periodo',
                // La clave es «periodo» y el atributo `periodo_etiqueta`: sin
                // esta closure la columna saldria VACIA en todas las filas.
                valor: fn (Adeudo $a) => $a->periodo_etiqueta,
                columnaSql: 'adeudos.periodo_etiqueta',
                ordenable: true,
                ancho: 16,
                ayuda: 'La etiqueta con la que el motor de cobro generó el cargo: «Agosto 2026», «Parcialidad 3».',
            ),
            'ciclo' => new ColumnaReporte(
                clave: 'ciclo',
                etiqueta: 'Ciclo',
                valor: fn (Adeudo $a) => $a->ciclo?->clave,
                ancho: 12,
            ),
            'monto' => new ColumnaReporte(
                clave: 'monto',
                etiqueta: 'Monto base',
                tipo: TipoDato::Dinero,
                columnaSql: 'adeudos.monto',
                ordenable: true,
                ancho: 12,
            ),
            'recargos' => new ColumnaReporte(
                clave: 'recargos',
                etiqueta: 'Recargos',
                tipo: TipoDato::Dinero,
                valor: fn (Adeudo $a) => $a->monto_recargos,
                columnaSql: 'adeudos.monto_recargos',
                ordenable: true,
                ancho: 11,
            ),
            'descuentos' => new ColumnaReporte(
                clave: 'descuentos',
                etiqueta: 'Descuentos',
                tipo: TipoDato::Dinero,
                valor: fn (Adeudo $a) => $a->monto_descuentos,
                columnaSql: 'adeudos.monto_descuentos',
                ordenable: true,
                ancho: 11,
                ayuda: 'Becas y descuentos ya aplicados al cargo. Viaja en positivo aunque reste.',
            ),
            'monto_total' => new ColumnaReporte(
                clave: 'monto_total',
                etiqueta: 'Total',
                tipo: TipoDato::Dinero,
                columnaSql: 'adeudos.monto_total',
                ordenable: true,
                ancho: 12,
            ),
            'cobrado' => new ColumnaReporte(
                clave: 'cobrado',
                etiqueta: 'Cobrado',
                tipo: TipoDato::Dinero,
                // Sale de la subconsulta correlacionada del SELECT, no de
                // `montoAplicado()`, que consulta por fila.
                valor: fn (Adeudo $a) => (float) ($a->cobrado ?? 0),
                columnaSql: 'cobrado',
                ordenable: true,
                ancho: 12,
                ayuda: 'Sólo lo cubierto por pagos COMPLETADOS: un depósito en espera de confirmación no baja nada.',
            ),
            'por_cobrar' => new ColumnaReporte(
                clave: 'por_cobrar',
                etiqueta: 'Por cobrar',
                tipo: TipoDato::Dinero,
                valor: fn (Adeudo $a) => round((float) $a->monto_total - (float) ($a->cobrado ?? 0), 2),
                ancho: 12,
            ),
            'generado' => new ColumnaReporte(
                clave: 'generado',
                etiqueta: 'Generado',
                tipo: TipoDato::Fecha,
                valor: fn (Adeudo $a) => $a->fecha_generacion,
                columnaSql: 'adeudos.fecha_generacion',
                ordenable: true,
                ancho: 12,
            ),
            'vence' => new ColumnaReporte(
                clave: 'vence',
                etiqueta: 'Vence',
                tipo: TipoDato::Fecha,
                valor: fn (Adeudo $a) => $a->fecha_vencimiento,
                columnaSql: 'adeudos.fecha_vencimiento',
                ordenable: true,
                ancho: 12,
            ),
            'estatus' => new ColumnaReporte(
                clave: 'estatus',
                etiqueta: 'Estatus',
                columnaSql: 'adeudos.estatus',
                ordenable: true,
                ancho: 12,
            ),
            'vencido' => new ColumnaReporte(
                clave: 'vencido',
                etiqueta: '¿Vencido?',
                tipo: TipoDato::Booleano,
                // El modelo ya sabe qué es estar vencido —estatus abierto Y
                // fecha pasada—; preguntarlo aquí sería una segunda definición.
                valor: fn (Adeudo $a) => $a->estaVencido(),
                ancho: 10,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'concepto_id' => new FiltroReporte(
                clave: 'concepto_id',
                etiqueta: 'Concepto',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('adeudos.concepto_id', $v),
                opciones: fn (Usuario $u) => ConceptoPago::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
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
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'estatus' => new FiltroReporte(
                clave: 'estatus',
                etiqueta: 'Estatus del cargo',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('adeudos.estatus', $v),
                opciones: fn (Usuario $u) => [
                    Adeudo::ESTATUS_PENDIENTE => 'Pendiente',
                    Adeudo::ESTATUS_PARCIAL => 'Pagado en parte',
                    Adeudo::ESTATUS_PAGADO => 'Pagado',
                    Adeudo::ESTATUS_CANCELADO => 'Cancelado',
                    Adeudo::ESTATUS_CONDONADO => 'Condonado',
                ],
            ),
            'generado_desde' => new FiltroReporte(
                clave: 'generado_desde',
                etiqueta: 'Generado desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('adeudos.fecha_generacion', '>=', $v),
            ),
            'generado_hasta' => new FiltroReporte(
                clave: 'generado_hasta',
                etiqueta: 'Generado hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('adeudos.fecha_generacion', '<=', $v),
            ),
            'periodo' => new FiltroReporte(
                clave: 'periodo',
                etiqueta: 'Periodo',
                tipo: TipoFiltro::Texto,
                aplicar: fn (Builder $q, string $v) => $q->where('adeudos.periodo_etiqueta', $v),
                ayuda: 'Tal como lo escribió el motor de cobro: «Agosto 2026».',
            ),
            'solo_por_cobrar' => new FiltroReporte(
                clave: 'solo_por_cobrar',
                etiqueta: 'Sólo lo que sigue abierto',
                tipo: TipoFiltro::Booleano,
                // El guard `$v ?` no es opcional: el motor sólo salta null,
                // cadena vacía y arreglo vacío — un `false` SÍ llega aquí.
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereIn('adeudos.estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL])
                    : $q,
            ),
            'solo_perdonados' => new FiltroReporte(
                clave: 'solo_perdonados',
                etiqueta: 'Sólo cancelados y condonados',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereIn('adeudos.estatus', [Adeudo::ESTATUS_CANCELADO, Adeudo::ESTATUS_CONDONADO])
                    : $q,
            ),
        ];
    }

    /**
     * Sólo la rama de MATRÍCULA, y la subconsulta de lo cobrado.
     *
     * `whereNotNull('matricula_oferta_id')` es lo que hace coherente al recorte:
     * un cargo de aspirante no tiene matrícula por donde llegar al campus, así
     * que con alcance acotado desaparecería sin explicación y con alcance global
     * aparecería sin campus. Se declara la rama y se dice en el grano.
     */
    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return Adeudo::query()
            ->whereNotNull('adeudos.matricula_oferta_id')
            ->with([
                'matriculaOferta:id,persona_id,oferta_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'matriculaOferta.oferta:id,carrera_id,campus_id',
                'matriculaOferta.oferta.carrera:id,nombre',
                'matriculaOferta.oferta.campus:id,nombre',
                'concepto:id,nombre',
                'ciclo:id,clave',
            ])
            ->select('adeudos.*')
            ->selectSub($this->saldos->aplicadoDeAdeudo('adeudos.id'), 'cobrado');
    }

    public function llavePrimaria(): string
    {
        return 'adeudos.id';
    }
}
