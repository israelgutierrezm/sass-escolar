<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\TransicionExpediente;
use Illuminate\Support\Facades\DB;

/**
 * La ÚNICA puerta por la que se mueve un expediente formativo.
 *
 * ── Por qué no se hace con `update()` ─────────────────────────────────────
 * Mover un expediente son cinco cosas a la vez —validar el origen, el permiso y
 * el alcance, anotar la bitácora y bloquear la fila—, y un `update` suelto se
 * las salta todas. Repartidas por los controladores, el que se olvide de una no
 * falla: sólo deja un expediente movido sin rastro, o dos aprobaciones
 * simultáneas. Es la lección de `Postulador::mover` y de `RegistradorMovimientos`.
 *
 * ── Las siete reglas ──────────────────────────────────────────────────────
 *  1. **El origen manda.** Un destino que no cuelgue del estado actual se
 *     rehúsa CON SU MOTIVO. Nunca se «corrige» al estado más cercano: eso
 *     convierte un error de programación en un movimiento silencioso del
 *     expediente de alguien.
 *  2. **El permiso es el del ACTO, no el de la pantalla.** Entrar a la bandeja
 *     y aprobar son dos cosas; y aprobar una excepción es una tercera.
 *  3. **El alcance por campus se vuelve a comprobar**, porque el id del
 *     expediente viaja por la URL y la lista de la pantalla no es una defensa.
 *  4. **Se anota SIEMPRE**, con origen, destino, motivo, usuario e IP.
 *  5. **Corre en transacción con `lockForUpdate()`**: dos revisores aprobando
 *     a la vez no producen dos aprobaciones ni dos renglones de bitácora.
 *  6. **Es idempotente**: pedir el estado en el que ya se está no hace nada y
 *     NO anota. Sin eso, dos clics inflan la bitácora con renglones de cero
 *     minutos y falsean justo lo que esa tabla mide.
 *  7. **Los avisos salen DESPUÉS del commit.** Uno emitido dentro anuncia algo
 *     que todavía puede no haber ocurrido.
 *
 * ── El motivo obligatorio se comprueba AQUÍ ───────────────────────────────
 * Y no en cada FormRequest: con la regla repartida, la pantalla nueva que
 * alguien agregue el mes que viene dejará rechazar sin explicación, y quien lo
 * reciba no sabrá qué corregir.
 */
class TransicionDeExpediente
{
    /**
     * Qué permiso exige llegar a cada estado.
     *
     * `solicitado` no está: lo hace el ALUMNO desde su portal y su puerta es
     * `ver-mi-proceso-formativo` más ser dueño de la matrícula — el permiso
     * dice qué, la propiedad dice sobre quién, igual que el docente con sus
     * materias.
     *
     * @var array<string, string>
     */
    private const PERMISOS = [
        'en_revision' => 'revisar-solicitudes-formativas',
        'requiere_correccion' => 'revisar-solicitudes-formativas',
        'rechazado' => 'revisar-solicitudes-formativas',
        'aprobado' => 'revisar-solicitudes-formativas',
        'asignado' => 'revisar-solicitudes-formativas',
        'en_curso' => 'revisar-solicitudes-formativas',
        'suspendido' => 'revisar-solicitudes-formativas',
        'concluido' => 'revisar-solicitudes-formativas',
        'liberado' => 'liberar-expedientes-formativos',
    ];

    public function __construct(private readonly AlcanceDeExpedientes $alcance) {}

    /**
     * Mueve el expediente, o explica por qué no.
     *
     * @param  string|null  $ip  La del solicitante. Se pide como parámetro y no
     *                           se lee de `request()` para que el servicio se
     *                           pueda invocar desde un comando sin fingir una
     *                           petición.
     *
     * @throws AvisoParaElUsuario 403 sin permiso o fuera de alcance, 422 si el
     *                            destino no cuelga del origen o falta el motivo
     */
    public function mover(
        ExpedienteProceso $expediente,
        EstadoExpediente $destino,
        ?Usuario $quien,
        ?string $motivo = null,
        ?string $ip = null,
        array $ademas = [],
    ): ExpedienteProceso {
        /*
         * La idempotencia se comprueba ANTES de pedir permisos: volver a pulsar
         * un botón que ya no hace nada no debería dar un 403 que confunda a
         * quien lo pulsa.
         */
        if ($expediente->estado === $destino) {
            return $expediente;
        }

        $this->exigirPermiso($destino, $quien);
        $this->alcance->exigirQueAlcance($expediente, $quien);

        AvisoParaElUsuario::aMenosQue(
            ! $destino->exigeMotivo() || trim((string) $motivo) !== '',
            422,
            'Para '.$destino->verbo().' hace falta escribir el motivo: sin él, quien lo reciba no '
            .'sabe qué corregir y dentro de un año nadie puede explicar por qué se hizo.',
        );

        $movido = DB::transaction(function () use ($expediente, $destino, $quien, $motivo, $ip, $ademas) {
            /*
             * Se relee CON BLOQUEO: el estado que traía el objeto en memoria
             * puede ser de hace medio segundo, y es exactamente el hueco por el
             * que dos revisores aprueban a la vez. Misma defensa que la firma
             * de las becas y el apartado de licencia de las clases en línea.
             */
            $fresco = ExpedienteProceso::query()->lockForUpdate()->findOrFail($expediente->id);

            // Y con la fila ya bloqueada se vuelve a mirar: entre el guard de
            // arriba y este bloqueo, otro pudo haberlo movido.
            if ($fresco->estado === $destino) {
                return $fresco;
            }

            AvisoParaElUsuario::aMenosQue(
                $fresco->estado->puedePasarA($destino),
                422,
                'No se puede '.$destino->verbo().': el expediente está en «'
                .$fresco->estado->etiqueta().'» y desde ahí '
                .($fresco->estado->esTerminal()
                    ? 'ya no se mueve a ninguna parte.'
                    : 'sólo se puede pasar a: '.$this->nombresDe($fresco->estado).'.'),
            );

            $origen = $fresco->estado;

            $fresco->forceFill(array_merge($ademas, [
                'estado' => $destino->value,
                // El motivo del ÚLTIMO acto que lo exigió. Los anteriores viven
                // en la bitácora; aquí sólo el vigente, que es lo que la
                // pantalla enseña arriba.
                'motivo_estado' => $destino->exigeMotivo() ? $motivo : null,
            ]))->save();

            TransicionExpediente::create([
                'expediente_id' => $fresco->id,
                'estado_origen' => $origen->value,
                'estado_destino' => $destino->value,
                'motivo' => $motivo,
                'usuario_id' => $quien?->id,
                'ip' => $ip,
                'momento' => now(),
            ]);

            return $fresco->refresh();
        });

        return $movido;
    }

    /**
     * Abre el expediente y anota su primer renglón, en la misma transacción.
     *
     * El renglón del alta lleva el origen en NULL: sin él no hay desde cuándo
     * contar nada, y «cuánto tarda un servicio social» se mediría desde el
     * primer movimiento en vez de desde que existe.
     */
    public function abrir(array $datos, ?Usuario $quien, ?string $ip = null): ExpedienteProceso
    {
        return DB::transaction(function () use ($datos, $quien, $ip) {
            /*
             * El estado inicial se escribe con `forceFill`: no es asignable en
             * masa a propósito —un formulario no puede mover el trámite— y sin
             * ponerlo aquí el objeto recién creado lo trae en NULL, porque el
             * valor por omisión lo pone MySQL y Eloquent no lo relee.
             */
            $expediente = new ExpedienteProceso;
            $expediente->fill($datos)
                ->forceFill(['estado' => EstadoExpediente::Borrador->value])
                ->save();

            TransicionExpediente::create([
                'expediente_id' => $expediente->id,
                'estado_origen' => null,
                'estado_destino' => $expediente->estado->value,
                'motivo' => null,
                'usuario_id' => $quien?->id,
                'ip' => $ip,
                'momento' => now(),
            ]);

            return $expediente;
        });
    }

    private function exigirPermiso(EstadoExpediente $destino, ?Usuario $quien): void
    {
        $permiso = self::PERMISOS[$destino->value] ?? null;

        if ($permiso === null) {
            return;
        }

        AvisoParaElUsuario::aMenosQue(
            $quien?->can($permiso) === true,
            403,
            'Tu rol no puede '.$destino->verbo().'.',
        );
    }

    private function nombresDe(EstadoExpediente $estado): string
    {
        return implode(', ', array_map(
            fn (EstadoExpediente $e) => '«'.$e->etiqueta().'»',
            $estado->siguientes(),
        ));
    }
}
