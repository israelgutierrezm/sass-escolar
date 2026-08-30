<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Captacion\SeguimientoAspirante;
use App\Models\Identidad\Persona;
use App\Services\AgendaDelAspirante;
use App\Services\AsignadorAsesor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * La ACTIVIDAD de un prospecto, operada desde su ficha.
 *
 * ── Por qué aquí y no en `/captacion` ──────────────────────────────────────
 * El seguimiento se registraba sólo desde el tablero del embudo, y ahí se ve la
 * cartera entera: para anotar una llamada había que salir de la ficha de la
 * persona con la que se acababa de hablar, encontrarla en una lista y volver.
 * Quien atiende a un prospecto trabaja EN su ficha —ahí están su teléfono, su
 * expediente y lo que debe—, así que la bitácora vive donde se usa.
 *
 * El tablero se queda: sirve para lo otro, que es ver a todos a la vez.
 *
 * ── Quién puede ───────────────────────────────────────────────────────────
 * El mismo par de siempre: el permiso dice QUÉ (`ver-aspirantes` o
 * `ver-mis-prospectos`), y la asignación en `aspirante_asesor` dice SOBRE
 * QUIÉN. Un asesor con el permiso no toca los prospectos de otro.
 */
class ActividadAspiranteController extends Controller
{
    public function __construct(private readonly AgendaDelAspirante $agenda) {}

    /** Registra un contacto que ya ocurrió. */
    public function registrar(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $this->autorizar($request, $aspirante);

        $datos = $request->validate([
            'tipo_id' => ['nullable', Rule::exists('tipos_seguimiento', 'id')],
            'nota' => ['required', 'string', 'min:3', 'max:2000'],
            'resultado_id' => ['nullable', Rule::exists('resultados_seguimiento', 'id')],
            'respuesta' => ['nullable', 'string', 'max:2000'],
            // Si de paso se acordó el siguiente contacto, se agenda de verdad.
            'programado_para' => ['nullable', 'date'],
            'nota_siguiente' => ['nullable', 'string', 'max:2000'],
            'etapa_destino_id' => ['nullable', Rule::exists('etapas_crm', 'id')],
        ], [], $this->etiquetas());

        return $this->intentar(fn () => $this->agenda->registrarHecho(
            $aspirante,
            $datos,
            $request->user()?->persona_id,
        ), 'Contacto registrado.');
    }

    /** Agenda algo que falta por hacer. */
    public function agendar(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $this->autorizar($request, $aspirante);

        $datos = $request->validate([
            'tipo_id' => ['nullable', Rule::exists('tipos_seguimiento', 'id')],
            'nota' => ['required', 'string', 'min:3', 'max:2000'],
            'programado_para' => ['required', 'date'],
            // Se puede agendar para otro: el coordinador reparte trabajo.
            'responsable_id' => ['nullable', Rule::exists('personas', 'id')],
        ], [], $this->etiquetas());

        return $this->intentar(fn () => $this->agenda->agendar(
            $aspirante,
            $datos,
            $request->user()?->persona_id,
        ), 'Actividad agendada.');
    }

    /** Cierra una agendada diciendo cómo fue. */
    public function cerrar(Request $request, Aspirante $aspirante, SeguimientoAspirante $actividad): RedirectResponse
    {
        $this->autorizar($request, $aspirante);
        $this->exigirQueSeaSuya($aspirante, $actividad);

        $datos = $request->validate([
            'resultado_id' => ['required', Rule::exists('resultados_seguimiento', 'id')],
            'respuesta' => ['nullable', 'string', 'max:2000'],
            'etapa_destino_id' => ['nullable', Rule::exists('etapas_crm', 'id')],
        ], [], $this->etiquetas());

        return $this->intentar(fn () => $this->agenda->cerrar(
            $actividad,
            $datos,
            $request->user()?->persona_id,
        ), 'Actividad cerrada.');
    }

    /** La cancela, con motivo. No se borra. */
    public function cancelar(Request $request, Aspirante $aspirante, SeguimientoAspirante $actividad): RedirectResponse
    {
        $this->autorizar($request, $aspirante);
        $this->exigirQueSeaSuya($aspirante, $actividad);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->intentar(fn () => $this->agenda->cancelar(
            $actividad,
            $datos['motivo'],
            $request->user()?->persona_id,
        ), 'Actividad cancelada.');
    }

    /** La mueve de fecha sin inventar un intento que no ocurrió. */
    public function reprogramar(Request $request, Aspirante $aspirante, SeguimientoAspirante $actividad): RedirectResponse
    {
        $this->autorizar($request, $aspirante);
        $this->exigirQueSeaSuya($aspirante, $actividad);

        $datos = $request->validate([
            'programado_para' => ['required', 'date'],
        ], [], $this->etiquetas());

        return $this->intentar(fn () => $this->agenda->reprogramar(
            $actividad,
            $datos['programado_para'],
        ), 'Actividad reprogramada.');
    }

    /**
     * Mueve al prospecto de etapa desde su propia ficha.
     *
     * Antes sólo se podía como efecto secundario de registrar un seguimiento en
     * el tablero: para corregir una etapa mal puesta había que inventarse un
     * contacto que no ocurrió. Ahora es su propio gesto —y deja rastro en la
     * bitácora, porque mover a alguien de etapa ES parte de su historia—.
     */
    public function moverEtapa(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $this->autorizar($request, $aspirante);

        $datos = $request->validate([
            'etapa_crm_id' => ['required', Rule::exists('etapas_crm', 'id')],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        $destino = (int) $datos['etapa_crm_id'];

        if ($destino === $aspirante->etapa_crm_id) {
            return back(303)->with('advertencia', 'Ya estaba en esa etapa.');
        }

        $desde = $aspirante->etapa?->nombre ?? 'sin etapa';
        $hasta = EtapaCrm::find($destino)?->nombre ?? 'otra etapa';

        return $this->intentar(fn () => $this->agenda->registrarHecho($aspirante, [
            'tipo_id' => null,
            'nota' => trim(($datos['nota'] ?? '')." (movido de «{$desde}» a «{$hasta}»)"),
            'etapa_destino_id' => $destino,
        ], $request->user()?->persona_id), "Movido a «{$hasta}».");
    }

    /**
     * Pone (o cambia) al asesor titular desde la ficha.
     *
     * Es de quien COORDINA, no de quien da seguimiento: si el asesor pudiera
     * reasignar, podría quitarse de encima un prospecto difícil y la cartera
     * dejaría de significar nada.
     */
    public function asignarAsesor(Request $request, Aspirante $aspirante): RedirectResponse
    {
        abort_unless($request->user()->can('gestionar-captacion'), 403);

        $datos = $request->validate([
            'persona_id' => ['required', Rule::exists('asesores', 'persona_id')],
        ], [], ['persona_id' => 'el asesor']);

        $antes = $aspirante->asesores()->with('persona')->wherePivot('titular', true)->first();

        app(AsignadorAsesor::class)->atarComoTitular($aspirante, (int) $datos['persona_id']);

        $ahora = Persona::find((int) $datos['persona_id'])?->nombreCompleto() ?? 'otro asesor';

        /*
         * El cambio queda en la BITÁCORA del prospecto.
         *
         * Quién lo atendía y desde cuándo explica su historia: sin el rastro,
         * un prospecto que pasó por tres manos parece que nadie lo trabajó, y
         * la comisión del que sí lo trabajó no se puede defender.
         */
        $this->agenda->registrarHecho($aspirante, [
            'tipo_id' => null,
            'nota' => $antes === null
                ? "Asignado a {$ahora}."
                : 'Reasignado de '.($antes->persona?->nombreCompleto() ?? 'otro asesor')." a {$ahora}.",
        ], $request->user()?->persona_id);

        return back(303)->with('exito', "Ahora lo atiende {$ahora}.");
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Corre la acción y traduce el «no se puede» del servicio a un mensaje.
     *
     * Las reglas viven en el servicio y no aquí: el mismo cierre lo va a
     * disparar la pantalla, un comando y las pruebas, y una regla escrita en el
     * controlador sólo se cumple cuando se entra por la pantalla.
     */
    private function intentar(callable $accion, string $exito): RedirectResponse
    {
        try {
            $accion();
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', $exito);
    }

    /**
     * Una actividad se cierra desde la ficha de SU prospecto.
     *
     * Sin esto se podría pasar el id de una actividad ajena por la URL y
     * cerrarla desde una ficha a la que sí se tiene acceso: la comprobación de
     * arriba mira al aspirante, no a la actividad.
     */
    private function exigirQueSeaSuya(Aspirante $aspirante, SeguimientoAspirante $actividad): void
    {
        abort_unless($actividad->aspirante_id === $aspirante->id, 404);
    }

    /**
     * El permiso dice qué; la asignación, sobre quién.
     *
     * Quien coordina captación o ve aspirantes alcanza a todos. El asesor sólo
     * a los suyos — misma regla que el tablero, para que no haya una puerta
     * lateral por la ficha.
     */
    private function autorizar(Request $request, Aspirante $aspirante): void
    {
        $usuario = $request->user();

        if ($usuario->can('ver-aspirantes') || $usuario->can('gestionar-captacion')) {
            return;
        }

        abort_unless($usuario->can('ver-mis-prospectos'), 403);

        abort_unless(
            // La relación va a `asesores` (PK `persona_id`), no a `personas`:
            // filtrar por `personas.id` habría dado «Unknown column».
            $aspirante->asesores()->where('asesores.persona_id', $usuario->persona_id)->exists(),
            403,
            'Este prospecto no está asignado a ti.',
        );
    }

    /** @return array<string, string> */
    private function etiquetas(): array
    {
        return [
            'nota' => 'la nota',
            'programado_para' => 'la fecha',
            'resultado_id' => 'el desenlace',
            'tipo_id' => 'el tipo de contacto',
            'responsable_id' => 'el responsable',
        ];
    }
}
