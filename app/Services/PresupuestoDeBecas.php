<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\Patrocinador;
use App\Models\Finanzas\PresupuestoBeca;

/**
 * Cuánto se ha becado de cada bolsa, y cuánto quedaba.
 *
 * ── Se MIDE, no se estima ──────────────────────────────────────────────────
 * `adeudo_ajustes` guarda un renglón por cada beca que movió un cargo, con
 * `origen_id` apuntando a la beca otorgada. El ejercido es la suma de esos
 * renglones: un hecho, auditable renglón a renglón, y no una proyección.
 *
 * ── Lo que este número NO es ───────────────────────────────────────────────
 * Y hay que decirlo en la pantalla, porque se va a leer al revés: es lo YA
 * aplicado, no lo que va a costar el ciclo. Una beca del 40 % no tiene importe
 * hasta que existen los cargos, así que proyectarla exigiría inventar cuántos
 * cargos faltan y de cuánto — y ese número, puesto al lado de uno real, se lee
 * igual de cierto que él.
 *
 * ── Pasarse NO se bloquea ──────────────────────────────────────────────────
 * Se avisa. Un tope duro impediría la última beca del año a alguien que la
 * necesita por unos pesos, y esa es una decisión de la dirección y no del
 * sistema. Lo que el sistema debe garantizar es que nadie se pase sin enterarse.
 */
class PresupuestoDeBecas
{
    /**
     * Lo que las becas de este patrocinador han descontado de verdad en el
     * ciclo.
     *
     * El ciclo se toma de la beca OTORGADA y no del cargo: es la bolsa de ese
     * ciclo la que se está gastando, y un cargo puede no llevar ciclo.
     */
    public function ejercido(int $patrocinadorId, int $cicloId): float
    {
        $otorgadas = BecaAlumno::query()
            ->where('ciclo_id', $cicloId)
            ->whereIn('beca_id', Beca::query()->where('patrocinador_id', $patrocinadorId)->select('id'))
            ->select('id');

        // Los ajustes de beca van con signo NEGATIVO —así el total del cargo es
        // `monto + SUM(ajustes)`—, y lo que se presupuesta es cuánto se dejó de
        // cobrar: un número positivo.
        return round(abs((float) AdeudoAjuste::query()
            ->where('tipo', AdeudoAjuste::TIPO_BECA)
            ->whereIn('origen_id', $otorgadas)
            ->sum('monto')), 2);
    }

    /**
     * El panorama de un ciclo: cada bolsa con lo asignado y lo ejercido.
     *
     * Salen TODOS los patrocinadores activos, tengan presupuesto o no. Uno sin
     * bolsa asignada que ya lleva becas dadas es exactamente lo que hay que
     * ver: si sólo se listaran los que tienen presupuesto, ese gasto sería
     * invisible.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panorama(int $cicloId): array
    {
        $presupuestos = PresupuestoBeca::query()
            ->where('ciclo_id', $cicloId)
            ->get()
            ->keyBy('patrocinador_id');

        return Patrocinador::query()
            ->activos()
            ->withCount(['becas'])
            ->orderBy('nombre')
            ->get()
            ->map(function (Patrocinador $p) use ($cicloId, $presupuestos) {
                $asignado = $presupuestos->has($p->id)
                    ? (float) $presupuestos[$p->id]->monto
                    : null;
                $ejercido = $this->ejercido($p->id, $cicloId);

                return [
                    'patrocinador_id' => $p->id,
                    'patrocinador' => $p->nombre,
                    'protegido' => (bool) $p->protegido,
                    'becas' => $p->becas_count,
                    'asignado' => $asignado,
                    'ejercido' => $ejercido,
                    // Null cuando no hay bolsa asignada: un «disponible» de cero
                    // se leería como «ya no queda», que es distinto de «nadie ha
                    // dicho cuánto hay».
                    'disponible' => $asignado === null ? null : round($asignado - $ejercido, 2),
                    'excedido' => $asignado !== null && $ejercido > $asignado,
                    'notas' => $presupuestos[$p->id]->notas ?? null,
                    'otorgadas' => BecaAlumno::query()
                        ->where('ciclo_id', $cicloId)
                        ->whereIn('beca_id', Beca::query()->where('patrocinador_id', $p->id)->select('id'))
                        ->count(),
                ];
            })
            ->all();
    }

    /** Los ciclos que se pueden presupuestar, del más reciente al más viejo. */
    public function ciclos()
    {
        return Ciclo::query()->orderByDesc('id')->get(['id', 'nombre']);
    }
}
