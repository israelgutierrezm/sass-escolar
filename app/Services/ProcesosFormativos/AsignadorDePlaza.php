<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\PlazaProceso;
use Illuminate\Support\Facades\DB;

/**
 * Mandar a un alumno a una organización: la asignación.
 *
 * ── El CUPO lo protege la BASE, no un `SELECT` previo ─────────────────────
 * Dos coordinadores asignando la última plaza a la vez pasan los dos un conteo
 * previo, y el segundo deja `cupo_ocupado` por encima de `cupo` sin que nada
 * falle. Aquí van las tres defensas juntas: `lockForUpdate()` sobre la plaza
 * dentro de la transacción, la comprobación con la fila ya bloqueada, y el
 * CHECK `plaza_cupo_no_rebasado` debajo de todo. Es la lección del apartado de
 * licencia de las clases en línea.
 *
 * ── El convenio se comprueba al ASIGNAR ───────────────────────────────────
 * No al listar organizaciones: un convenio puede vencer entre que se abre la
 * pantalla y se pulsa el botón. Y **vencido no interrumpe lo ya asignado** —el
 * alumno no tiene la culpa—, sólo impide asignaciones nuevas.
 *
 * ── Y el ALCANCE de la organización también ───────────────────────────────
 * `organizacion_alcances` dice a qué programas y campus se le puede mandar
 * gente. El padrón es institucional y se ve entero; lo que se acota es a quién
 * se le manda, y esa comprobación tiene que vivir donde tiene consecuencias.
 */
class AsignadorDePlaza
{
    public function __construct(private readonly TransicionDeExpediente $transiciones) {}

    /**
     * Asigna y pasa el expediente a `asignado`, todo en una transacción.
     *
     * @throws AvisoParaElUsuario 422 con el motivo concreto
     */
    public function asignar(
        ExpedienteProceso $expediente,
        array $datos,
        ?Usuario $quien,
        ?string $ip = null,
    ): ExpedienteProceso {
        $organizacion = OrganizacionReceptora::query()
            ->with('convenios', 'alcances')
            ->findOrFail($datos['organizacion_id']);

        $this->exigirQuePuedaRecibir($organizacion, $expediente);

        return DB::transaction(function () use ($expediente, $datos, $organizacion, $quien, $ip) {
            $plaza = $this->plazaBloqueada($datos['plaza_id'] ?? null, $organizacion, $expediente);

            $movido = $this->transiciones->mover(
                $expediente,
                EstadoExpediente::Asignado,
                $quien,
                null,
                $ip,
                [
                    'organizacion_id' => $organizacion->id,
                    'plaza_id' => $plaza?->id,
                    /*
                     * La modalidad se captura, y sin ella se hereda de la
                     * plaza: es lo que esa plaza ya declara, así que pedirla
                     * otra vez sería teclear un dato que el sistema tiene. Sin
                     * plaza y sin captura queda en null — «no se ha decidido»
                     * es distinto de «presencial».
                     */
                    'modalidad_id' => $datos['modalidad_id'] ?? $plaza?->modalidad_id,
                    'contacto_supervisor_id' => $datos['contacto_supervisor_id'] ?? null,
                    'responsable_interno_id' => $datos['responsable_interno_id'] ?? null,
                    'fecha_inicio' => $datos['fecha_inicio'],
                    'fecha_fin_programada' => $datos['fecha_fin_programada'],
                ],
            );

            /*
             * El cupo se sube DESPUÉS de mover, y dentro de la misma
             * transacción: si la transición se rehúsa —el expediente ya no
             * estaba en `aprobado`—, el lugar no se ocupa. Con el incremento
             * antes, un rechazo dejaría la plaza llena de nadie.
             *
             * `increment` y no `cupo_ocupado + 1` calculado en PHP: lo segundo
             * escribe un número leído hace un momento.
             */
            $plaza?->increment('cupo_ocupado');

            return $movido;
        });
    }

    /**
     * Libera el lugar cuando el expediente deja de ocuparlo.
     *
     * Se hace al cancelar, no al concluir: quien termina su servicio social
     * ocupó esa plaza y su lugar no vuelve a la bolsa —la plaza se cierra o la
     * organización sube el cupo—. Devolverlo al concluir haría que una plaza de
     * cinco recibiera a treinta a lo largo del año sin que nadie lo decidiera.
     */
    public function liberarLugar(ExpedienteProceso $expediente): void
    {
        if ($expediente->plaza_id === null) {
            return;
        }

        DB::transaction(function () use ($expediente) {
            $plaza = PlazaProceso::query()->lockForUpdate()->find($expediente->plaza_id);

            // Nunca por debajo de cero: un contador que puede quedar negativo
            // deja de significar nada y el CHECK no lo impediría.
            if ($plaza !== null && $plaza->cupo_ocupado > 0) {
                $plaza->decrement('cupo_ocupado');
            }
        });
    }

    private function exigirQuePuedaRecibir(OrganizacionReceptora $organizacion, ExpedienteProceso $expediente): void
    {
        /*
         * Que reciba lo dice la BANDERA de su situación, y nada más.
         *
         * La primera versión de esto exigía además una columna `activa` que
         * NO EXISTE en `organizaciones_receptoras` —la organización se apaga
         * con su situación, que es lo que `scopeQueReciben` ya consulta—, así
         * que la condición era `null && …`: siempre falsa. No fallaba: se
         * negaba TODA asignación con un mensaje que parecía de captura. Lo
         * cazó la suite.
         */
        AvisoParaElUsuario::aMenosQue(
            $organizacion->situacion?->acepta_asignaciones === true,
            422,
            'La organización «'.$organizacion->razon_social.'» no está recibiendo alumnos ahora mismo: '
            .'su situación es «'.($organizacion->situacion?->nombre ?? 'sin capturar').'».',
        );

        $expediente->loadMissing('matricula.oferta');

        AvisoParaElUsuario::aMenosQue(
            $organizacion->alcanzaA(
                $expediente->matricula?->oferta?->campus_id,
                $expediente->matricula?->oferta?->programa_academico_id,
                $expediente->tipo_proceso_id,
            ),
            422,
            'Esa organización no está autorizada para este programa, campus o tipo de proceso. '
            .'Amplía sus alcances antes de mandarle gente.',
        );

        /*
         * El convenio se exige sólo si la regla congelada lo pide. Y se mira la
         * VIGENCIA de verdad —fecha y situación—, no una sola de las dos: un
         * convenio con la situación «vigente» y la fecha pasada se ve bien en
         * cualquier pantalla que mire una.
         */
        $expediente->loadMissing('reglaVersion', 'excepciones');

        if ($expediente->reglaVersion?->exige_convenio_vigente !== true) {
            return;
        }

        if ($expediente->excepcionDe('convenio') !== null) {
            return;
        }

        AvisoParaElUsuario::aMenosQue(
            $organizacion->convenios->contains(fn ($c) => $c->estaVigente()),
            422,
            'La regla de este proceso exige convenio vigente y «'.$organizacion->razon_social
            .'» no tiene ninguno hoy. Renuévalo, o autoriza la excepción con su motivo.',
        );
    }

    /**
     * La plaza, bloqueada y comprobada. Null cuando el tipo no exige una.
     */
    private function plazaBloqueada(
        ?int $plazaId,
        OrganizacionReceptora $organizacion,
        ExpedienteProceso $expediente,
    ): ?PlazaProceso {
        $expediente->loadMissing('tipoProceso');

        if ($plazaId === null) {
            AvisoParaElUsuario::aMenosQue(
                $expediente->tipoProceso?->exige_plaza !== true,
                422,
                'Este tipo de proceso se hace sobre una plaza publicada: elige cuál.',
            );

            return null;
        }

        $plaza = PlazaProceso::query()->lockForUpdate()->findOrFail($plazaId);

        AvisoParaElUsuario::aMenosQue(
            (int) $plaza->organizacion_id === (int) $organizacion->id,
            422,
            'Esa plaza no es de la organización que elegiste.',
        );

        /*
         * Las dos preguntas van SEPARADAS y con su propio mensaje: «no queda
         * cupo» y «no es para tu programa» se resuelven de forma distinta, y
         * juntarlas en un solo motivo manda a quien captura a adivinar cuál de
         * las cuatro cosas falló.
         */
        AvisoParaElUsuario::aMenosQue(
            $plaza->admiteA(),
            422,
            'La plaza «'.$plaza->nombre.'» no admite a nadie ahora mismo: '
            .($plaza->lugaresLibres() > 0 ? '' : 'se le acabó el cupo. ')
            .($plaza->abierta ? '' : 'está cerrada. ')
            .($plaza->estaVencida() ? 'su fecha de cierre ya pasó.' : ''),
        );

        AvisoParaElUsuario::aMenosQue(
            $plaza->aceptaAlPrograma($expediente->matricula?->oferta?->programa_academico_id),
            422,
            'La plaza «'.$plaza->nombre.'» no se ofrece al programa de este alumno.',
        );

        return $plaza;
    }
}
