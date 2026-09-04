<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\TransicionCaso;
use Illuminate\Support\Facades\DB;

/**
 * LA PUERTA por la que pasa todo movimiento de un caso.
 *
 * ── Por qué una sola ───────────────────────────────────────────────────────
 * Mover un caso son seis cosas a la vez: validar el origen, validar el permiso,
 * respetar el campus, bloquear la fila, anotar la bitácora y hacerlo todo en una
 * transacción. Un `update(['estado' => …])` suelto se las salta todas, y
 * repartidas por los controladores el que se olvide de una **no falla**: deja un
 * caso movido sin rastro de quién lo hizo, o dos coordinadores cerrándolo a la
 * vez. Es el molde de `TransicionDeExpediente` y de `Postulador::mover`.
 *
 * ── La idempotencia tiene DOS guardas, y las dos hacen falta ───────────────
 * La de FUERA evita un 403 confuso al re-pulsar un botón que ya no hace nada. La
 * de DENTRO, con la fila releída y bloqueada, es la única que detiene la CARRERA
 * de dos personas con la pantalla abierta. En una petición suelta hacen lo
 * mismo, así que la segunda sólo se puede comprobar reproduciendo la carrera.
 *
 * ── Las notificaciones se emiten DESPUÉS del commit ────────────────────────
 * `DB::afterCommit()`. Un aviso de un movimiento que la transacción luego
 * deshizo es un aviso sobre algo que no pasó — y quien lo recibe actúa sobre
 * ello. La fase 6 engancha aquí sus avisos; la puerta ya está preparada.
 */
class TransicionDeCaso
{
    /**
     * Qué permiso exige llegar a cada estado.
     *
     * ── Y por qué no basta con uno solo para todo ──────────────────────────
     * Asignar es del coordinador, registrar intervenciones del equipo, escalar y
     * cerrar de quien supervisa. Con un permiso único, quien captura una llamada
     * podría cerrar el caso — y cerrar es la afirmación de que la situación se
     * atendió.
     */
    public const PERMISOS = [
        'abierto' => 'abrir-casos',
        'asignado' => 'asignar-casos',
        'contacto_pendiente' => 'registrar-intervenciones',
        'en_intervencion' => 'registrar-intervenciones',
        'en_seguimiento' => 'registrar-intervenciones',
        'escalado' => 'escalar-casos',
        'resuelto' => 'registrar-intervenciones',
        'cerrado' => 'cerrar-casos',
    ];

    public function __construct(private readonly AlcanceDeCasos $alcance) {}

    /**
     * Mueve el caso, o se rehúsa diciendo por qué.
     *
     * @param  array<string, mixed>  $ademas  columnas que este movimiento también escribe
     */
    public function mover(
        CasoPermanencia $caso,
        EstadoCaso $destino,
        ?Usuario $quien,
        ?string $motivo = null,
        ?string $ip = null,
        array $ademas = [],
    ): CasoPermanencia {
        /*
         * La idempotencia se comprueba ANTES de pedir permisos: volver a pulsar
         * un botón que ya no hace nada no debería dar un 403 que confunda a
         * quien lo pulsa.
         */
        if ($caso->estado === $destino) {
            return $caso;
        }

        AvisoParaElUsuario::aMenosQue(
            $quien?->can(self::PERMISOS[$destino->value]) === true,
            403,
            'Tu rol no puede mover un caso a «'.$destino->etiqueta().'».',
        );

        $this->alcance->exigirQueAlcance($caso, $quien);

        /*
         * `exigeMotivo` mira el DESTINO, así que no hace falta excluir «abierto»
         * a mano: sólo escalar y cerrar lo piden. Una condición de más que
         * ninguna mutación puede matar es una regla aparente que no existe.
         */
        AvisoParaElUsuario::si(
            $caso->estado->exigeMotivo($destino) && trim((string) $motivo) === '',
            422,
            $destino === EstadoCaso::Escalado
                ? 'Escalar exige decir por qué: quien lo reciba empieza a ciegas sin eso.'
                : 'Cerrar exige decir por qué: un caso cerrado sin explicación no se puede auditar.',
        );

        return DB::transaction(function () use ($caso, $destino, $quien, $motivo, $ip, $ademas) {
            /*
             * Se RELEE con bloqueo. El objeto en memoria puede ser de hace un
             * minuto, y en ese minuto otra persona pudo mover el caso: sin esto,
             * la segunda petición borraría del acta la decisión de la primera.
             */
            $fresco = CasoPermanencia::query()->lockForUpdate()->findOrFail($caso->id);

            if ($fresco->estado === $destino) {
                return $fresco;
            }

            AvisoParaElUsuario::aMenosQue(
                $fresco->estado->puedePasarA($destino),
                422,
                'Un caso «'.$fresco->estado->etiqueta().'» no puede pasar a «'.$destino->etiqueta().'». '
                .'Desde aquí se puede ir a: '
                .(implode(', ', array_map(fn (EstadoCaso $e) => $e->etiqueta(), $fresco->estado->siguientes()))
                    ?: 'ningún otro estado'),
            );

            $origen = $fresco->estado;

            $fresco->forceFill(array_merge($ademas, ['estado' => $destino->value]))->save();

            TransicionCaso::create([
                'caso_id' => $fresco->id,
                'estado_origen' => $origen->value,
                'estado_destino' => $destino->value,
                'motivo' => $motivo,
                'quien' => $quien?->id,
                'ip' => $ip,
                'momento' => now(),
            ]);

            /*
             * Aquí engancha la fase 6 sus avisos, y va DESPUÉS del commit: un
             * aviso de un movimiento que la transacción luego deshizo es un
             * aviso sobre algo que no pasó.
             */
            DB::afterCommit(function () {
                // Todavía nada que notificar: la fase 6 lo llena.
            });

            return $fresco;
        });
    }

    /**
     * Anota el PRIMER CONTACTO si este movimiento lo implica.
     *
     * Se separa de `mover()` a propósito: el primer contacto es un hecho —«se
     * habló con alguien»— y no un estado. Escrito dentro del movimiento, pasar a
     * «en intervención» desde «contacto pendiente» lo marcaría aunque no se haya
     * hablado con nadie, y el indicador de «cuánto tardamos» mediría otra cosa.
     */
    public function anotarPrimerContacto(CasoPermanencia $caso): void
    {
        if ($caso->primer_contacto_en !== null) {
            return;
        }

        $caso->forceFill(['primer_contacto_en' => now()])->save();
    }
}
