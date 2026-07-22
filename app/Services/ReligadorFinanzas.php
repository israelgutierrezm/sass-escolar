<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\Pago;

/**
 * Pasa los adeudos y pagos de un aspirante a la matrícula que acaba de nacer.
 *
 * Es la otra mitad de la decisión vinculante del Módulo 7. `adeudos` y `pagos`
 * admiten como titular una `matricula_oferta` O un `aspirante` porque el
 * aspirante paga su ficha e inscripción antes de existir como alumno; si el
 * dinero se quedara colgando del aspirante, el estado de cuenta del alumno
 * nacería partido en dos y justo el pago de inscripción —el que siempre se
 * reclama después— quedaría del lado invisible.
 *
 * Se llama DENTRO de la transacción que genera la matrícula: una conversión que
 * fallara a medias dejaría pagos apuntando a una matrícula que no existe.
 *
 * El intercambio de columnas es incondicional (`aspirante_id` a NULL al poner
 * `matricula_oferta_id`) porque la base impone que haya exactamente uno de los
 * dos. Dejar los dos puestos rompería el CHECK, y es lo correcto: el titular
 * del adeudo pasó a ser la matrícula, y de qué aspirante venía lo cuenta
 * `aspirantes.persona_id`, que no se pierde.
 */
class ReligadorFinanzas
{
    /**
     * @return array{adeudos: int, pagos: int} cuántos renglones cambiaron de titular
     */
    public function religar(Aspirante $aspirante, MatriculaOferta $matricula): array
    {
        $adeudos = Adeudo::query()
            ->where('aspirante_id', $aspirante->id)
            ->update([
                'matricula_oferta_id' => $matricula->id,
                'aspirante_id' => null,
            ]);

        $pagos = Pago::query()
            ->where('aspirante_id', $aspirante->id)
            ->update([
                'matricula_oferta_id' => $matricula->id,
                'aspirante_id' => null,
            ]);

        return ['adeudos' => $adeudos, 'pagos' => $pagos];
    }

    /**
     * Re-liga lo que trajera la persona de su etapa de aspirante a ESA MISMA
     * oferta, si es que pasó por ahí.
     *
     * Sirve al camino que no es la conversión: quien ya es alumno de la casa y
     * se matricula en otra oferta (`MatriculadorOferta`) pudo haber pagado su
     * ficha como aspirante de esa oferta. Se acota a la oferta y no a la
     * persona porque los pagos de OTRA candidatura suya no son de esta
     * matrícula.
     *
     * @return array{adeudos: int, pagos: int}
     */
    public function religarPorOferta(int $personaId, MatriculaOferta $matricula): array
    {
        $aspirante = Aspirante::query()
            ->where('persona_id', $personaId)
            ->where('oferta_interes_id', $matricula->oferta_id)
            ->first();

        if ($aspirante === null) {
            return ['adeudos' => 0, 'pagos' => 0];
        }

        return $this->religar($aspirante, $matricula);
    }
}
