<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Examen;
use App\Models\Lms\Intento;
use App\Models\Lms\Reactivo;
use App\Services\Lms\AplicadorExamen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * El alumno presentando un examen.
 *
 * El alcance es la PERTENENCIA, igual que en el resto de su portal: se busca su
 * inscripción en la materia de esa actividad. Si no la tiene, responde lo mismo
 * que si el examen no existiera, para que probar ids no revele qué hay.
 *
 * Nada de lo que llega a esta pantalla dice cuál es la respuesta correcta: se
 * arma con `Reactivo::paraResolver()`, que la esconde en el modelo y no en cada
 * vista —esa decisión tomada en cada lugar se olvida en alguno, y ese día el
 * examen se contesta leyendo el código de la página—.
 */
class PresentacionExamenController extends Controller
{
    public function __construct(private readonly AplicadorExamen $aplicador) {}

    /** La portada: qué es, cuánto vale, qué intentos lleva. */
    public function show(Request $request, Actividad $actividad): Response
    {
        [$examen, $inscripcion] = $this->contexto($request, $actividad);

        $intentos = Intento::query()
            ->where('examen_id', $examen->id)
            ->where('inscripcion_id', $inscripcion->id)
            ->orderBy('numero')
            ->get();

        $enCurso = $intentos->firstWhere('entregado_en', null);

        return Inertia::render('MisCursos/Examen', [
            'actividad' => [
                'id' => $actividad->id,
                'titulo' => $actividad->titulo,
                'instrucciones' => $actividad->instrucciones,
                'puntos' => (float) $actividad->puntos,
                'cierra_en' => $actividad->cierra_en?->toDateTimeString(),
                'abierta' => $actividad->abierta(),
            ],
            'materia' => [
                'id' => $actividad->curso->asignatura_grupo_id,
                'nombre' => $actividad->curso->asignaturaGrupo?->planMateria?->asignatura?->nombre ?? 'Materia',
            ],
            'examen' => [
                'intentos_permitidos' => $examen->intentos_permitidos,
                'minutos_limite' => $examen->minutos_limite,
                'total_reactivos' => $examen->reactivos_a_presentar ?? $examen->reactivos()->count(),
                'intento_que_cuenta' => $examen->intento_que_cuenta,
            ],
            'intentos' => $intentos->map(fn (Intento $i) => [
                'id' => $i->id,
                'numero' => $i->numero,
                'entregado_en' => $i->entregado_en?->toDateTimeString(),
                'en_curso' => ! $i->entregado(),
                // Un resultado que todavía no se debe ver, no viaja.
                'resultado' => $this->resultadoVisible($examen, $actividad, $i),
            ])->values(),
            'puede_iniciar' => $actividad->abierta()
                && $enCurso === null
                && $examen->permiteOtroIntento($intentos->count()),
            'intento_en_curso' => $enCurso?->id,
        ]);
    }

    /** Abre el intento y manda directo a resolverlo. */
    public function iniciar(Request $request, Actividad $actividad): RedirectResponse
    {
        [$examen, $inscripcion] = $this->contexto($request, $actividad);

        if (! $actividad->abierta()) {
            return back()->with('error', 'Este examen ya está cerrado.');
        }

        try {
            $intento = $this->aplicador->iniciar($examen, $inscripcion);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tenant.misexamenes.resolver', $intento->id);
    }

    /** La pantalla de resolver: los reactivos de ESTE intento y lo ya contestado. */
    public function resolver(Request $request, Intento $intento): Response
    {
        $this->exigirMio($request, $intento);

        // Un intento ya entregado no se vuelve a abrir: se ve su resultado.
        if ($intento->entregado()) {
            return $this->verResultado($intento);
        }

        // Si el reloj ya se acabó, se cierra con lo que alcanzó a contestar en
        // vez de dejarlo abierto para siempre.
        if ($intento->expirado()) {
            $this->aplicador->entregar($intento);

            return $this->verResultado($intento->refresh());
        }

        $examen = $intento->examen;
        $actividad = $examen->actividad;
        $contestadas = $intento->respuestas()->get()->keyBy('reactivo_id');

        return Inertia::render('MisCursos/Resolver', [
            'intento' => [
                'id' => $intento->id,
                'numero' => $intento->numero,
                'expira_en' => $intento->expira_en?->toIso8601String(),
                'segundos_restantes' => $intento->expira_en !== null
                    ? max(0, (int) now()->diffInSeconds($intento->expira_en, false))
                    : null,
            ],
            'actividad' => ['id' => $actividad->id, 'titulo' => $actividad->titulo],
            'materia' => ['id' => $actividad->curso->asignatura_grupo_id],
            'reactivos' => $this->aplicador->reactivosDelIntento($intento)
                ->map(fn (Reactivo $r) => $r->paraResolver($examen->barajar_opciones) + [
                    'puntos' => $examen->puntosDe($r),
                    'respuesta' => $contestadas->get($r->id)?->valor['v'] ?? null,
                    // Para que pueda volver a bajar el archivo que subió.
                    'respuesta_id' => $contestadas->get($r->id)?->id,
                ])
                ->values(),
        ]);
    }

    /**
     * Guarda una respuesta conforme la contesta; no califica todavía.
     *
     * Responde 204 y NO por Inertia. El alumno contesta varias preguntas en
     * segundos y las visitas de Inertia se cancelan entre sí cuando se
     * encabalgan: de cinco respuestas seguidas sobrevivía la última y las otras
     * cuatro se perdían sin que nada avisara. Sin cuerpo de respuesta tampoco
     * hay props que recargar —la pantalla ya sabe lo que el alumno escribió—.
     */
    public function responder(Request $request, Intento $intento): JsonResponse
    {
        $this->exigirMio($request, $intento);

        $datos = $request->validate([
            'reactivo_id' => ['required', 'integer'],
            'valor' => ['nullable'],
        ]);

        try {
            $this->aplicador->guardarRespuesta($intento, (int) $datos['reactivo_id'], $datos['valor'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['guardado' => true]);
    }

    /**
     * Un reactivo de tipo archivo: se guarda en disco y en la respuesta queda la
     * ruta.
     *
     * Va por su propio endpoint porque un archivo no cabe en el mismo POST de
     * JSON con el que se guardan las demás respuestas, y mezclarlos obligaría a
     * mandar todas como multipart.
     */
    public function responderArchivo(Request $request, Intento $intento): RedirectResponse
    {
        $this->exigirMio($request, $intento);

        $datos = $request->validate([
            'reactivo_id' => ['required', 'integer'],
            'archivo' => ['required', 'file', 'max:20480'],
        ], [], ['archivo' => 'archivo']);

        $subido = $request->file('archivo');
        $ruta = $subido->store("examenes/{$intento->id}", 'local');

        try {
            $this->aplicador->guardarRespuesta($intento, (int) $datos['reactivo_id'], [
                'ruta' => $ruta,
                'nombre' => $subido->getClientOriginalName(),
            ]);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', 'Archivo adjuntado.');
    }

    /** Cierra el intento y lo califica. */
    public function entregar(Request $request, Intento $intento): RedirectResponse
    {
        $this->exigirMio($request, $intento);

        if ($intento->entregado()) {
            return redirect()->route('tenant.misexamenes.resolver', $intento->id);
        }

        $this->aplicador->entregar($intento);

        return redirect()
            ->route('tenant.misexamenes.resolver', $intento->id)
            ->with('exito', 'Examen entregado.');
    }

    /** El resultado de un intento ya cerrado, según lo que el examen permita ver. */
    private function verResultado(Intento $intento): Response
    {
        $examen = $intento->examen;
        $actividad = $examen->actividad;
        $resultado = $this->resultadoVisible($examen, $actividad, $intento);

        return Inertia::render('MisCursos/Resultado', [
            'actividad' => ['id' => $actividad->id, 'titulo' => $actividad->titulo],
            'materia' => ['id' => $actividad->curso->asignatura_grupo_id],
            'intento' => [
                'id' => $intento->id,
                'numero' => $intento->numero,
                'entregado_en' => $intento->entregado_en?->toDateTimeString(),
                'requiere_revision' => (bool) $intento->requiere_revision,
            ],
            'resultado' => $resultado,
            // El detalle reactivo por reactivo solo si ya se puede ver el
            // resultado: si no, es el examen resuelto servido en bandeja.
            'detalle' => $resultado === null ? [] : $this->detalle($intento, $examen),
        ]);
    }

    /**
     * El puntaje, si ya toca mostrarlo.
     *
     * `al_cerrar` significa después de la fecha de cierre de la actividad: es lo
     * que impide que el primero en entregar le pase las respuestas al resto.
     * Mientras haya un reactivo esperando al docente tampoco se muestra: sería
     * una nota parcial que el alumno leería como definitiva.
     *
     * @return array<string, mixed>|null
     */
    private function resultadoVisible(Examen $examen, Actividad $actividad, Intento $intento): ?array
    {
        if (! $intento->entregado() || $examen->mostrar_resultado === Examen::RESULTADO_NUNCA) {
            return null;
        }

        if ($intento->requiere_revision) {
            return null;
        }

        $yaCerro = $actividad->cierra_en !== null && now()->gt($actividad->cierra_en);

        if ($examen->mostrar_resultado === Examen::RESULTADO_AL_CERRAR && ! $yaCerro) {
            return null;
        }

        return [
            'puntos_obtenidos' => (float) $intento->puntos_obtenidos,
            'puntos_posibles' => (float) $intento->puntos_posibles,
            'en_diez' => $intento->enDiez(),
        ];
    }

    /**
     * Reactivo por reactivo: qué contestó, si acertó y la retroalimentación.
     *
     * @return array<int, array<string, mixed>>
     */
    private function detalle(Intento $intento, Examen $examen): array
    {
        $respuestas = $intento->respuestas()->with('reactivo.opciones')->get();

        return $this->aplicador->reactivosDelIntento($intento)
            ->map(function (Reactivo $reactivo) use ($respuestas, $examen) {
                $mia = $respuestas->firstWhere('reactivo_id', $reactivo->id);

                return [
                    'id' => $reactivo->id,
                    'enunciado' => $reactivo->enunciado,
                    'puntos' => $examen->puntosDe($reactivo),
                    'obtenidos' => $mia?->puntos !== null ? (float) $mia->puntos : null,
                    'correcta' => $mia?->correcta,
                    'retroalimentacion' => $reactivo->retroalimentacion,
                    'comentario' => $mia?->comentario,
                    // Ahora sí se dice cuál era la buena: el examen ya cerró.
                    'esperada' => $reactivo->opciones
                        ->where('correcta', true)
                        ->pluck('texto')
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * El examen y la inscripción de quien entró, o 403.
     *
     * @return array{0: Examen, 1: Inscripcion}
     */
    private function contexto(Request $request, Actividad $actividad): array
    {
        $inscripcion = $this->miInscripcionEn($request, $actividad);

        abort_if($inscripcion === null, 403, 'Ese examen no es de una materia que curses.');

        $examen = $actividad->examen;

        abort_if($examen === null, 404);
        abort_unless((bool) $actividad->publicada, 403, 'Ese examen todavía no está publicado.');

        return [$examen, $inscripcion];
    }

    /** Un intento solo lo abre quien lo presentó. */
    private function exigirMio(Request $request, Intento $intento): void
    {
        $mio = Inscripcion::query()
            ->whereKey($intento->inscripcion_id)
            ->whereIn('matricula_oferta_id', $this->misMatriculas($request))
            ->exists();

        abort_unless($mio, 403, 'Ese intento no es tuyo.');
    }

    private function miInscripcionEn(Request $request, Actividad $actividad): ?Inscripcion
    {
        $asignaturaGrupoId = $actividad->curso?->asignatura_grupo_id;

        if ($asignaturaGrupoId === null) {
            return null;
        }

        return Inscripcion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupoId)
            ->whereIn('matricula_oferta_id', $this->misMatriculas($request))
            ->first();
    }

    private function misMatriculas(Request $request)
    {
        return $request->user()->persona?->matriculas()->pluck('matricula_oferta.id') ?? collect();
    }
}
