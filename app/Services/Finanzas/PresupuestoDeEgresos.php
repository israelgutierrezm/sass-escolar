<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\Egreso;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Presupuesto;
use App\Models\Nomina\PeriodoNomina;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto se autorizó gastar, y cuánto se lleva.
 *
 * ── El ejercido se MIDE de los egresos registrados ─────────────────────────
 * Una sola fuente. Derivarlo de otro lado —la nómina, las becas— crearía un
 * número que cambia según de dónde se mire y que nadie puede auditar renglón
 * por renglón.
 *
 * ── Pasarse NO se bloquea: se avisa ────────────────────────────────────────
 * Un tope duro impediría pagar una reparación urgente por unos pesos, y eso es
 * una decisión de la dirección, no del sistema. Lo que el sistema garantiza es
 * que nadie se pase sin enterarse. Mismo criterio que el presupuesto de becas.
 *
 * ── Y las becas NO se cuentan aquí ─────────────────────────────────────────
 * Tienen su propio presupuesto desde la entrega de patrocinadores, y ahí el
 * ejercido sale de `adeudo_ajustes`. Traerlas también a esta tabla contaría el
 * mismo dinero dos veces — y además no es lo mismo: una beca es ingreso que se
 * deja de cobrar, no dinero que sale de la cuenta.
 */
class PresupuestoDeEgresos
{
    /** Lo gastado en un cruce, medido de los egresos. */
    public function ejercido(int $centroId, int $partidaId, int $cicloId): float
    {
        return round((float) Egreso::query()
            ->where('centro_costo_id', $centroId)
            ->where('partida_id', $partidaId)
            ->where('ciclo_id', $cicloId)
            ->sum('monto'), 2);
    }

    /**
     * El tablero del ciclo: cada cruce con lo autorizado y lo gastado.
     *
     * Salen TODOS los cruces que tienen presupuesto O gasto. Uno con gasto y
     * sin presupuesto es exactamente lo que hay que ver —se está gastando en
     * algo que nadie autorizó—; listando sólo los presupuestados, ese gasto
     * sería invisible.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panorama(int $cicloId): array
    {
        $presupuestos = Presupuesto::query()
            ->where('ciclo_id', $cicloId)
            ->get()
            ->keyBy(fn (Presupuesto $p) => $p->centro_costo_id.':'.$p->partida_id);

        $gastos = Egreso::query()
            ->where('ciclo_id', $cicloId)
            ->selectRaw('centro_costo_id, partida_id, sum(monto) as total, count(*) as renglones')
            ->groupBy('centro_costo_id', 'partida_id')
            ->get()
            ->keyBy(fn ($g) => $g->centro_costo_id.':'.$g->partida_id);

        $centros = CentroCosto::query()->withTrashed()->get()->keyBy('id');
        $partidas = PartidaPresupuesto::query()->withTrashed()->get()->keyBy('id');

        $claves = $presupuestos->keys()->merge($gastos->keys())->unique();

        return $claves
            ->map(function (string $clave) use ($presupuestos, $gastos, $centros, $partidas) {
                [$centroId, $partidaId] = array_map('intval', explode(':', $clave));

                $asignado = $presupuestos->has($clave) ? (float) $presupuestos[$clave]->monto : null;
                $ejercido = round((float) ($gastos[$clave]->total ?? 0), 2);

                return [
                    'centro_costo_id' => $centroId,
                    'centro' => $centros[$centroId]->nombre ?? 'Ya no existe',
                    'campus' => $centros[$centroId]?->campus?->nombre,
                    'partida_id' => $partidaId,
                    'partida' => $partidas[$partidaId]->nombre ?? 'Ya no existe',
                    'asignado' => $asignado,
                    'ejercido' => $ejercido,
                    'renglones' => (int) ($gastos[$clave]->renglones ?? 0),
                    /*
                     * Null cuando nadie asignó: un «disponible» de cero se
                     * leería como «ya no queda», que es distinto de «nadie ha
                     * dicho cuánto hay». Misma decisión que en las bolsas de
                     * beca.
                     */
                    'disponible' => $asignado === null ? null : round($asignado - $ejercido, 2),
                    'excedido' => $asignado !== null && $ejercido > $asignado,
                    'sin_presupuesto' => $asignado === null && $ejercido > 0,
                    'notas' => $presupuestos[$clave]->notas ?? null,
                ];
            })
            ->sortBy([['centro', 'asc'], ['partida', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Trae la nómina de un periodo al presupuesto, como egresos.
     *
     * ── Por qué es un ACTO y no una derivación ─────────────────────────────
     * Para que «ejercido» signifique lo mismo siempre: un solo lugar donde
     * mirar, con su rastro. Y porque quien decide contra qué partida se carga
     * la nómina es la escuela, no una regla adivinada.
     *
     * ── El periodo tiene que estar CERRADO ─────────────────────────────────
     * Uno abierto todavía cambia de importe cada vez que alguien recalcula, y
     * el presupuesto quedaría persiguiendo una cifra móvil.
     *
     * ── Y va por CAMPUS, que es lo que el periodo ya dice ──────────────────
     * `periodos_nomina.campus_id` existe desde que existe la nómina, así que no
     * hay que adivinar ninguna atribución: se busca el centro de costo de ese
     * campus. Si no hay ninguno, se DICE — repartirlo a un centro cualquiera
     * metería el gasto más grande de la escuela en el sitio equivocado y
     * cuadraría igual.
     *
     * @return array{egreso: Egreso, neto: float}
     */
    public function traerNomina(PeriodoNomina $periodo, PartidaPresupuesto $partida, int $cicloId): array
    {
        AvisoParaElUsuario::aMenosQue(
            $periodo->estado === PeriodoNomina::CERRADO,
            422,
            'Ese periodo de nómina todavía no está cerrado: su importe puede cambiar en cuanto alguien lo recalcule.',
        );

        $centro = CentroCosto::query()
            ->activos()
            ->when(
                $periodo->campus_id !== null,
                fn ($q) => $q->where('campus_id', $periodo->campus_id),
                fn ($q) => $q->whereNull('campus_id'),
            )
            ->first();

        AvisoParaElUsuario::aMenosQue(
            $centro !== null,
            422,
            $periodo->campus_id !== null
                ? 'No hay ningún centro de costo para el campus de ese periodo. Crea uno antes de traer su nómina: cargarla a otro centro metería el gasto más grande de la escuela en el sitio equivocado.'
                : 'Ese periodo no es de ningún campus y no hay un centro de costo general. Crea uno antes de traer su nómina.',
        );

        $neto = round((float) $periodo->recibos()->sum('neto'), 2);

        AvisoParaElUsuario::si($neto <= 0, 422, 'Ese periodo no tiene recibos con importe.');

        $yaEsta = Egreso::query()
            ->where('origen', Egreso::ORIGEN_NOMINA)
            ->where('origen_id', $periodo->id)
            ->where('centro_costo_id', $centro->id)
            ->first();

        AvisoParaElUsuario::si(
            $yaEsta !== null,
            422,
            "La nómina de ese periodo ya está en el presupuesto (egreso #{$yaEsta?->id}).",
        );

        return DB::transaction(function () use ($periodo, $partida, $centro, $cicloId, $neto) {
            $egreso = Egreso::create([
                'fecha' => $periodo->fecha_pago ?? $periodo->fecha_fin,
                'centro_costo_id' => $centro->id,
                'partida_id' => $partida->id,
                'ciclo_id' => $cicloId,
                'monto' => $neto,
                'descripcion' => 'Nómina: '.$periodo->nombre,
                'beneficiario' => 'Personal de la escuela',
                'referencia' => 'Periodo de nómina #'.$periodo->id,
                'origen' => Egreso::ORIGEN_NOMINA,
                'origen_id' => $periodo->id,
            ]);

            return ['egreso' => $egreso, 'neto' => $neto];
        });
    }

    /** Los periodos de nómina cerrados que todavía no se han traído. */
    public function nominasPendientes()
    {
        return PeriodoNomina::query()
            ->where('estado', PeriodoNomina::CERRADO)
            ->whereNotIn(
                'id',
                Egreso::query()->where('origen', Egreso::ORIGEN_NOMINA)->whereNotNull('origen_id')->select('origen_id'),
            )
            ->orderByDesc('fecha_fin')
            ->get();
    }
}
