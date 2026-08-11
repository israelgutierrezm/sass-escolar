<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Promocion\ResultadoSeguimiento;
use App\Models\Promocion\SeguimientoAspirante;
use App\Models\Promocion\TipoSeguimiento;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las tres transiciones de una actividad del CRM: agendar, cerrar y cancelar.
 *
 * ── Por qué vive aparte de `EmbudoAdmision` ────────────────────────────────
 * El embudo responde «cómo va la cartera»: cuántos hay por etapa, de dónde
 * llegan, a quién toca contactar. Esto responde «qué le pasó a ESTE prospecto y
 * qué sigue». Son dos preguntas distintas y la segunda tiene reglas propias
 * —una actividad cerrada no se vuelve a cerrar, una cancelación pide motivo—
 * que no tienen nada que ver con el tablero.
 *
 * ── Lo que estas reglas protegen ──────────────────────────────────────────
 * Un CRM sirve para lo que se cerró, no para lo que se capturó. Si una llamada
 * agendada se puede cerrar dos veces, el conteo de intentos miente; si se puede
 * cerrar sin decir cómo fue, el historial no sirve para decidir el siguiente
 * paso; y si se puede cancelar sin motivo, nadie sabe después por qué se dejó
 * de insistir. Las tres son la misma idea: el registro tiene que poder
 * defenderse solo cuando alguien lo lea meses después.
 */
class AgendaDelAspirante
{
    /**
     * Agenda una actividad: se quedó de hacer algo, y todavía no se hace.
     *
     * @param  array{tipo_id:?int, nota:string, programado_para:string, responsable_id:?int}  $datos
     * @param  int|null  $quienAgenda  persona que la está creando
     */
    public function agendar(Aspirante $aspirante, array $datos, ?int $quienAgenda): SeguimientoAspirante
    {
        $cuando = $datos['programado_para'];

        /*
         * El responsable por omisión es quien la agenda.
         *
         * Un pendiente sin dueño no aparece en la bandeja de nadie, y lo que no
         * le sale a nadie en su pantalla no se hace. Se puede agendar para otro
         * —el coordinador reparte trabajo—, pero nunca queda huérfano.
         */
        $responsable = $datos['responsable_id'] ?? $quienAgenda;

        return SeguimientoAspirante::create([
            'aspirante_id' => $aspirante->id,
            'tipo_id' => $datos['tipo_id'] ?? null,
            'persona_id' => $responsable,
            // La etapa en la que estaba al agendarla: es lo que después permite
            // decir «se le agendó una llamada estando en documentación».
            'etapa_crm_id' => $aspirante->etapa_crm_id,
            'nota' => $datos['nota'],
            'programado_para' => $cuando,
            'estatus' => SeguimientoAspirante::AGENDADO,
            // `momento` es cuándo OCURRIÓ, y todavía no ocurre.
            'momento' => null,
        ]);
    }

    /**
     * Cierra una actividad agendada: se hizo, y así fue.
     *
     * @param  array{resultado_id:?int, respuesta:?string, etapa_destino_id:?int}  $datos
     */
    public function cerrar(
        SeguimientoAspirante $actividad,
        array $datos,
        ?int $quienCierra,
    ): SeguimientoAspirante {
        $this->exigirQueEsteAbierta($actividad, 'cerrar');

        $resultado = empty($datos['resultado_id'])
            ? null
            : ResultadoSeguimiento::find((int) $datos['resultado_id']);

        /*
         * El desenlace es obligatorio al cerrar.
         *
         * «Se hizo la llamada» sin decir cómo fue no sirve para decidir el
         * siguiente paso, que es para lo único que se lee un historial de
         * contactos. Es el mismo criterio con el que un tipo de seguimiento
         * puede exigir próximo contacto.
         */
        if ($resultado === null) {
            throw new RuntimeException(
                'Di cómo fue: una actividad cerrada sin desenlace no le dice nada '
                .'a quien tenga que retomar al prospecto.'
            );
        }

        return DB::transaction(function () use ($actividad, $datos, $quienCierra, $resultado) {
            $actividad->update([
                'estatus' => SeguimientoAspirante::REALIZADO,
                'resultado_id' => $resultado->id,
                'respuesta' => $datos['respuesta'] ?? null,
                'cerrado_por' => $quienCierra,
                'cerrado_en' => now(),
                // Ahora sí ocurrió.
                'momento' => now(),
            ]);

            $this->moverEtapa($actividad, $datos['etapa_destino_id'] ?? null);

            return $actividad->fresh();
        });
    }

    /**
     * Cancela una actividad agendada: ya no se va a hacer.
     *
     * No se borra. Que se haya agendado una llamada y luego se desistiera es
     * información sobre el prospecto y sobre quien lo atiende; borrarla deja el
     * historial diciendo que nunca se intentó.
     */
    public function cancelar(
        SeguimientoAspirante $actividad,
        string $motivo,
        ?int $quienCancela,
    ): SeguimientoAspirante {
        $this->exigirQueEsteAbierta($actividad, 'cancelar');

        $actividad->update([
            'estatus' => SeguimientoAspirante::CANCELADO,
            'respuesta' => $motivo,
            'cerrado_por' => $quienCancela,
            'cerrado_en' => now(),
        ]);

        return $actividad->fresh();
    }

    /**
     * Mueve una actividad agendada a otra fecha.
     *
     * Reprogramar no es cerrar y volver a agendar: sería inventar un intento
     * que no ocurrió y dejar el conteo de llamadas hecho a mano.
     */
    public function reprogramar(SeguimientoAspirante $actividad, string $cuando): SeguimientoAspirante
    {
        $this->exigirQueEsteAbierta($actividad, 'reprogramar');

        $actividad->update(['programado_para' => $cuando]);

        return $actividad->fresh();
    }

    /**
     * Registra un contacto que YA ocurrió, sin pasar por la agenda.
     *
     * Un correo que se acaba de mandar o un WhatsApp que se acaba de contestar
     * no se agendan para cerrarlos un segundo después.
     *
     * @param  array{tipo_id:?int, nota:string, resultado_id:?int, respuesta:?string, proximo_contacto:?string, etapa_destino_id:?int}  $datos
     */
    public function registrarHecho(Aspirante $aspirante, array $datos, ?int $quien): SeguimientoAspirante
    {
        $tipo = empty($datos['tipo_id']) ? null : TipoSeguimiento::find((int) $datos['tipo_id']);

        /*
         * Esta regla ya existía en `EmbudoAdmision` y se conserva: un contacto
         * sin próximo paso es un prospecto que nadie vuelve a marcar. Aquí vale
         * igual, y por eso el tipo puede exigirlo.
         */
        if ($tipo?->exige_proximo_contacto && empty($datos['proximo_contacto']) && empty($datos['programado_para'])) {
            throw new RuntimeException(
                "«{$tipo->nombre}» exige decir cuándo es el siguiente contacto: "
                .'un contacto sin próximo paso es un prospecto que nadie vuelve a marcar.'
            );
        }

        return DB::transaction(function () use ($aspirante, $datos, $quien, $tipo) {
            $actividad = SeguimientoAspirante::create([
                'aspirante_id' => $aspirante->id,
                'tipo_id' => $tipo?->id,
                'persona_id' => $quien,
                'etapa_crm_id' => $aspirante->etapa_crm_id,
                'nota' => $datos['nota'],
                'proximo_contacto' => $datos['proximo_contacto'] ?? null,
                'estatus' => SeguimientoAspirante::REALIZADO,
                'resultado_id' => empty($datos['resultado_id']) ? null : (int) $datos['resultado_id'],
                'respuesta' => $datos['respuesta'] ?? null,
                'cerrado_por' => $quien,
                'cerrado_en' => now(),
                'momento' => now(),
            ]);

            $this->moverEtapa($actividad, $datos['etapa_destino_id'] ?? null);

            /*
             * Si de paso se acordó un siguiente contacto, queda AGENDADO de
             * verdad y no como fecha suelta.
             *
             * La forma vieja —`proximo_contacto` a secas— salía en el tablero
             * pero no se podía cerrar ni cancelar: el pendiente se quedaba ahí
             * para siempre aunque ya se hubiera atendido.
             */
            if (! empty($datos['programado_para'])) {
                $this->agendar($aspirante->fresh(), [
                    'tipo_id' => $tipo?->id,
                    'nota' => $datos['nota_siguiente'] ?? 'Siguiente contacto acordado.',
                    'programado_para' => $datos['programado_para'],
                    'responsable_id' => $datos['responsable_id'] ?? $quien,
                ], $quien);
            }

            return $actividad;
        });
    }

    /** Una actividad ya cerrada no se vuelve a tocar. */
    private function exigirQueEsteAbierta(SeguimientoAspirante $actividad, string $accion): void
    {
        if ($actividad->estatus === SeguimientoAspirante::AGENDADO) {
            return;
        }

        $como = $actividad->estatus === SeguimientoAspirante::CANCELADO ? 'cancelada' : 'cerrada';

        throw new RuntimeException(
            "Esta actividad ya está {$como}; no se puede {$accion} otra vez. "
            .'Registra una nueva si hubo un contacto más.'
        );
    }

    /** El movimiento de etapa que puede viajar con el cierre. */
    private function moverEtapa(SeguimientoAspirante $actividad, mixed $destino): void
    {
        if (empty($destino)) {
            return;
        }

        $aspirante = $actividad->aspirante;

        if ($aspirante !== null && (int) $destino !== $aspirante->etapa_crm_id) {
            $aspirante->update(['etapa_crm_id' => (int) $destino]);
        }
    }
}
