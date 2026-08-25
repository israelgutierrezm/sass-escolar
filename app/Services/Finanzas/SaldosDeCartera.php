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
        return DB::table('pago_adeudo as pa')
            ->join('pagos as p', 'p.id', '=', 'pa.pago_id')
            ->whereNull('pa.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.estatus', Pago::ESTATUS_COMPLETADO)
            ->groupBy('pa.adeudo_id')
            ->select('pa.adeudo_id', DB::raw('sum(pa.monto_aplicado) as aplicado'));
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
