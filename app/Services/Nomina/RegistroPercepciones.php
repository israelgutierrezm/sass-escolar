<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\Nomina\EsquemaPercepcion;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\ModalidadPercepcion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Abrir y cerrar el esquema de percepción de un expediente.
 *
 * ── Las reglas viven aquí y no en el formulario ───────────────────────────
 * Porque son dos escrituras —cerrar el anterior y abrir el nuevo— y una sola de
 * las dos deja al expediente con dos sueldos vigentes a la vez. Y porque el
 * cálculo de la nómina va a entrar por aquí también.
 */
class RegistroPercepciones
{
    /** Los tres componentes posibles, con su etiqueta para el mensaje. */
    private const ETIQUETAS = [
        'monto_base' => 'el monto base',
        'tarifa_hora' => 'la tarifa por hora',
        'tarifa_asignatura' => 'la tarifa por asignatura',
    ];

    /**
     * Abre un esquema y cierra el anterior el día antes.
     *
     * @param  array{monto_base?:float|null, tarifa_hora?:float|null, tarifa_asignatura?:float|null, notas?:string|null}  $montos
     *
     * @throws RuntimeException si el expediente ya no está contratado, si la
     *                          modalidad no paga nada, si falta un componente
     *                          que ésta exige, o si la vigencia no avanza
     */
    public function abrir(
        ExpedienteLaboral $expediente,
        ModalidadPercepcion $modalidad,
        string $desde,
        array $montos,
    ): EsquemaPercepcion {
        if (! $expediente->sigueContratado()) {
            throw new RuntimeException('No se le puede poner sueldo a quien ya está dado de baja.');
        }

        /*
         * Una modalidad sin ninguna bandera encendida no puede pagar nada.
         * Se puede crear desde la pantalla de catálogos —nada lo impide ahí— y
         * un esquema colgado de ella produciría recibos en cero sin un solo
         * error por ningún lado.
         */
        if (! $modalidad->esUtilizable()) {
            throw new RuntimeException(
                'Esa modalidad no tiene ningún componente encendido, así que pagaría cero. '
                .'Márcale al menos uno en el catálogo.'
            );
        }

        $limpios = $this->componentesExigidos($modalidad, $montos);

        $anterior = $expediente->esquemas()->abiertos()->orderByDesc('vigente_desde')->first();

        /*
         * El nuevo tiene que empezar DESPUÉS del que va a cerrar. Si no, el
         * cierre le pondría al viejo una fecha de fin anterior a su propio
         * inicio y quedaría un tramo invertido que ninguna consulta por fecha
         * puede interpretar.
         */
        if ($anterior !== null && $desde <= $anterior->vigente_desde->toDateString()) {
            throw new RuntimeException(
                'El nuevo esquema tiene que empezar después del que está vigente ('
                .$anterior->vigente_desde->toDateString().').'
            );
        }

        return DB::transaction(function () use ($expediente, $modalidad, $desde, $limpios, $montos, $anterior) {
            // Un día antes: dos esquemas no pueden cubrir la misma fecha.
            $anterior?->update([
                'vigente_hasta' => Carbon::parse($desde)->subDay()->toDateString(),
            ]);

            return $expediente->esquemas()->create(array_merge($limpios, [
                'modalidad_id' => $modalidad->id,
                'vigente_desde' => $desde,
                'vigente_hasta' => null,
                'notas' => $montos['notas'] ?? null,
            ]));
        });
    }

    /**
     * Corrige los montos de un esquema.
     *
     * ── Se puede corregir, y la vigencia NO ───────────────────────────────
     * Un dedazo en la cifra es real y hay que poder arreglarlo. Mover las
     * fechas es otra cosa: reacomodaría el tramo que otro esquema ya cubre, y
     * para eso está abrir uno nuevo, que deja el rastro de cuándo cambió.
     *
     * Lo que ya se pagó no se toca: el recibo se lleva sus propios importes al
     * emitirse, igual que `esquema_evaluacion` con las calificaciones.
     */
    public function corregir(EsquemaPercepcion $esquema, array $montos): EsquemaPercepcion
    {
        $modalidad = $esquema->modalidad;

        if ($modalidad === null) {
            throw new RuntimeException('Ese esquema perdió su modalidad; ábrele uno nuevo.');
        }

        $esquema->update(array_merge(
            $this->componentesExigidos($modalidad, $montos),
            ['notas' => $montos['notas'] ?? null],
        ));

        return $esquema->refresh();
    }

    /**
     * Deja sólo los componentes que la modalidad usa, y exige que estén.
     *
     * Los que no usa se ponen en NULL a propósito: guardar la tarifa por hora
     * de alguien a quien se le paga fijo dejaría un número que nadie va a
     * mirar y que el día que cambie de modalidad se aplicaría sin que nadie lo
     * haya vuelto a autorizar.
     *
     * @return array<string, float|null>
     */
    private function componentesExigidos(ModalidadPercepcion $modalidad, array $montos): array
    {
        $usa = $modalidad->componentes();
        $limpios = ['monto_base' => null, 'tarifa_hora' => null, 'tarifa_asignatura' => null];

        foreach ($usa as $componente) {
            $valor = $montos[$componente] ?? null;

            /*
             * Cero NO cuenta como capturado.
             *
             * Un esquema por horas con la tarifa en cero pagaría cero y el
             * recibo saldría, con el neto en nada y sin un solo error: es
             * exactamente el defecto que no se descubre hasta el día de pago.
             */
            if ($valor === null || $valor === '' || (float) $valor <= 0) {
                throw new RuntimeException(
                    'Con la modalidad «'.$modalidad->nombre.'» hace falta '
                    .self::ETIQUETAS[$componente].', y mayor que cero.'
                );
            }

            $limpios[$componente] = (float) $valor;
        }

        return $limpios;
    }
}
