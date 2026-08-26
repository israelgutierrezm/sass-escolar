<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\Pago;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto se debe: en UN solo sitio.
 *
 * ── Por qué existe ────────────────────────────────────────────────────────
 * Esta agregación estaba escrita DOS veces —`FinanzasController::saldosPorMatricula`
 * y la tarjeta `CarteraDeLaEscuela`— y ya habían divergido: la del controlador
 * lleva `whereNotNull('a.matricula_oferta_id')` y la de la tarjeta no. Como la
 * tarjeta ENLAZA a `/finanzas`, las dos cifras se leen una tras otra, así que
 * la diferencia se ve.
 *
 * Hoy la diferencia es de CERO en el demo porque no hay adeudos de aspirante;
 * o sea que es una trampa armada y no un número mal en pantalla. Pero
 * `adeudos` tiene titular DUAL a propósito —el aspirante paga antes de tener
 * matrícula— así que la primera escuela que cobre una ficha de admisión vería
 * dos totales distintos para lo mismo, y nadie sabría cuál creer.
 *
 * ── Las dos preguntas son DISTINTAS, y por eso son dos métodos ────────────
 * No se unifican en una: `porMatricula()` contesta «cuánto debe cada alumno»
 * —agrupa por matrícula, así que un adeudo de aspirante no tiene dónde caer— y
 * `totalDeLaEscuela()` contesta «cuánto se le debe a la escuela», que incluye
 * lo de los aspirantes porque es dinero que se debe igual. Fundirlas obligaría
 * a elegir entre dejar deuda fuera del total o inventarle matrícula a quien no
 * la tiene. Lo que no puede pasar es que las dos existan escritas aparte.
 */
class SaldosDeCartera
{
    /**
     * Lo aplicado a cada adeudo por pagos COBRADOS.
     *
     * Sólo los completados: un pago en espera de confirmación no baja el saldo
     * —si bajara, un depósito reportado y no verificado dejaría de aparecer como
     * deuda—.
     */
    private function aplicados(): Builder
    {
        return $this->reparto()
            ->groupBy('pa.adeudo_id')
            ->select('pa.adeudo_id', DB::raw('sum(pa.monto_aplicado) as aplicado'));
    }

    /**
     * QUÉ CUENTA COMO PAGADO, sin agrupar todavía.
     *
     * ── Por qué es público y por qué está separado ────────────────────────
     * La regla es una sola —pivote vivo, pago vivo, pago COMPLETADO— y este
     * servicio existe justamente porque había estado escrita dos veces y ya
     * había divergido. Las fuentes de reportes la necesitan desde los DOS lados
     * de la misma tabla puente: «cuánto se ha cobrado de este adeudo» y «cuánto
     * de este pago se repartió». Con `aplicados()` privado, la primera fuente
     * que la necesitara la copiaría, y el servicio dejaría de ser el único sitio
     * al día siguiente de haberse creado para eso.
     *
     * Se devuelve SIN `groupBy` ni `select` para que quien la use decida por
     * cuál de las dos columnas agrupa. Lo que no se puede tocar es el criterio.
     */
    public function reparto(): Builder
    {
        return DB::table('pago_adeudo as pa')
            ->join('pagos as p', 'p.id', '=', 'pa.pago_id')
            ->whereNull('pa.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.estatus', Pago::ESTATUS_COMPLETADO);
    }

    /**
     * Cuánto se ha cobrado de UN adeudo, como subconsulta correlacionada.
     *
     * Va así y no con `Adeudo::montoAplicado()` porque aquél consulta POR FILA:
     * en una exportación de cinco mil cargos son cinco mil consultas, y eso no
     * se nota en pantalla —donde hay veinticinco— sino de madrugada.
     */
    public function aplicadoDeAdeudo(string $columnaAdeudo = 'adeudos.id'): Builder
    {
        return $this->reparto()
            ->whereColumn('pa.adeudo_id', $columnaAdeudo)
            ->selectRaw('coalesce(sum(pa.monto_aplicado), 0)');
    }

    /** La otra cara: cuánto de UN pago se repartió entre adeudos. */
    public function aplicadoDePago(string $columnaPago = 'pagos.id'): Builder
    {
        return $this->reparto()
            ->whereColumn('pa.pago_id', $columnaPago)
            ->selectRaw('coalesce(sum(pa.monto_aplicado), 0)');
    }

    /**
     * Lo mismo, AGRUPADO, para unirlo como tabla derivada.
     *
     * ── Por qué existen las dos formas ────────────────────────────────────
     * Las correlacionadas de arriba producen un ALIAS en el SELECT, y MySQL
     * acepta un alias en el `ORDER BY` pero **no en el `WHERE`**. El recorrido
     * por lotes de una exportación avanza con un `WHERE` sobre la columna de
     * orden, así que un reporte ordenado por «Cobrado» ordenaba bien en la
     * pantalla y reventaba al pulsar «Excel». Uniendo la versión agrupada, la
     * columna queda calificada y sirve para las dos cosas.
     *
     * Van ya agrupadas, así que NO multiplican filas: hay a lo sumo una por
     * adeudo o por pago. El criterio sigue siendo uno solo: `reparto()`.
     */
    public function repartoPorAdeudo(): Builder
    {
        return $this->reparto()
            ->groupBy('pa.adeudo_id')
            ->select('pa.adeudo_id')
            ->selectRaw('sum(pa.monto_aplicado) as aplicado');
    }

    public function repartoPorPago(): Builder
    {
        return $this->reparto()
            ->groupBy('pa.pago_id')
            ->select('pa.pago_id')
            ->selectRaw('sum(pa.monto_aplicado) as aplicado')
            ->selectRaw('count(*) as cargos');
    }

    /** Los adeudos que siguen abiertos, con lo ya aplicado descontado. */
    private function abiertos(): Builder
    {
        return DB::table('adeudos as a')
            ->leftJoinSub($this->aplicados(), 'ap', 'ap.adeudo_id', '=', 'a.id')
            ->whereNull('a.deleted_at')
            ->whereIn('a.estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL]);
    }

    /**
     * Saldo y vencido POR MATRÍCULA, para el listado de cartera.
     *
     * Deja fuera los adeudos de aspirante por construcción: agrupa por
     * `matricula_oferta_id` y los suyos son NULL, así que sin el `whereNotNull`
     * caerían todos juntos en un renglón sin dueño.
     *
     * @param  array<int, int>|null  $matriculaIds  null = todas; acotado = sólo ésas
     */
    public function porMatricula(string $hoy, ?array $matriculaIds = null): Builder
    {
        return $this->abiertos()
            ->whereNotNull('a.matricula_oferta_id')
            ->when($matriculaIds !== null, fn ($q) => $q->whereIn('a.matricula_oferta_id', $matriculaIds))
            ->groupBy('a.matricula_oferta_id')
            ->select('a.matricula_oferta_id')
            ->selectRaw('sum(a.monto_total - coalesce(ap.aplicado, 0)) as saldo')
            // La fecha va como binding y no interpolada: es de `now()` y no del
            // usuario, pero una consulta cruda con fechas pegadas a mano es la
            // que alguien copia mañana para un filtro que sí viene de fuera.
            ->selectRaw(
                'sum(case when a.fecha_vencimiento < ? then a.monto_total - coalesce(ap.aplicado, 0) else 0 end) as vencido',
                [$hoy]
            )
            ->selectRaw('count(*) as adeudos');
    }

    /**
     * El total de la escuela, INCLUIDO lo que deben los aspirantes.
     *
     * `de_aspirantes` viaja aparte para que la tarjeta pueda decirlo en vez de
     * esconderlo dentro del total: es la parte que el listado de cartera no
     * puede enseñar, y callarla es lo que hace que dos cifras no cuadren.
     *
     * @return array{saldo: float, vencido: float, de_aspirantes: float}
     */
    public function totalDeLaEscuela(string $hoy): array
    {
        $fila = $this->abiertos()
            ->selectRaw('coalesce(sum(a.monto_total - coalesce(ap.aplicado, 0)), 0) as saldo')
            ->selectRaw(
                'coalesce(sum(case when a.fecha_vencimiento < ? then a.monto_total - coalesce(ap.aplicado, 0) else 0 end), 0) as vencido',
                [$hoy]
            )
            ->selectRaw('coalesce(sum(case when a.matricula_oferta_id is null then a.monto_total - coalesce(ap.aplicado, 0) else 0 end), 0) as de_aspirantes')
            ->first();

        return [
            'saldo' => round((float) ($fila->saldo ?? 0), 2),
            'vencido' => round((float) ($fila->vencido ?? 0), 2),
            'de_aspirantes' => round((float) ($fila->de_aspirantes ?? 0), 2),
        ];
    }
}
