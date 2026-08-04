<?php

declare(strict_types=1);

namespace App\Http\Controllers\Encuestas;

use App\Enums\DestinoEvento;
use App\Http\Controllers\Concerns\ArmaDestinos;
use App\Http\Controllers\Controller;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Encuesta;
use App\Models\Encuestas\Sujeto;
use App\Services\Encuestas\GeneradorDeSujetos;
use App\Services\Encuestas\ResultadosDeEncuesta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Poner una encuesta en marcha y ver qué contestó la escuela.
 *
 * ── Por qué se separa del cuestionario ─────────────────────────────────────
 * El mismo cuestionario de evaluación docente se aplica cada semestre con
 * fechas, destinatarios y docentes distintos. Si aplicación y cuestionario
 * fueran lo mismo, cada semestre habría que capturar las preguntas otra vez —y
 * comparar dos ciclos sería comparar dos instrumentos distintos—.
 */
class AplicacionController extends Controller
{
    use ArmaDestinos;

    public function index(): Response
    {
        $aplicaciones = AplicacionEncuesta::query()
            ->with('encuesta:id,titulo')
            ->withCount(['sujetos', 'participaciones'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (AplicacionEncuesta $a) => [
                'id' => $a->id,
                'titulo' => $a->titulo,
                'cuestionario' => $a->encuesta?->titulo,
                'tipo' => $a->tipo,
                'estado' => $a->estado,
                'abierta' => $a->estaAbierta(),
                'obligatoria' => $a->obligatoria,
                'anonima' => $a->anonima,
                'abre_en' => $a->abre_en?->format('Y-m-d\TH:i'),
                'cierra_en' => $a->cierra_en?->format('Y-m-d\TH:i'),
                'sujetos' => $a->sujetos_count,
                'respuestas' => $a->participaciones_count,
            ]);

        return Inertia::render('Encuestas/Aplicaciones', [
            'aplicaciones' => $aplicaciones,
            'cuestionarios' => Encuesta::query()->where('activa', true)
                ->orderByDesc('es_plantilla')->orderBy('titulo')
                ->get(['id', 'titulo', 'es_plantilla']),
            'tiposDestino' => DestinoEvento::paraSelect(),
            'opciones' => $this->opcionesDeDestino(),
            'ciclos' => Ciclo::query()->orderByDesc('fecha_inicio')->get(['id', 'clave', 'nombre']),
        ]);
    }

    public function guardar(Request $request, ?AplicacionEncuesta $aplicacion = null): RedirectResponse
    {
        $datos = $request->validate([
            'encuesta_id' => ['required', 'exists:encuestas,id'],
            'titulo' => ['required', 'string', 'max:180'],
            'instrucciones' => ['nullable', 'string', 'max:2000'],
            'tipo' => ['required', Rule::in([AplicacionEncuesta::GENERAL, AplicacionEncuesta::DOCENTE])],
            'abre_en' => ['nullable', 'date'],
            'cierra_en' => ['nullable', 'date', 'after_or_equal:abre_en'],
            'obligatoria' => ['boolean'],
            'anonima' => ['boolean'],
            // Sin destinatarios no la contesta nadie.
            'destinos' => ['required', 'array', 'min:1'],
            'destinos.*.tipo' => ['required', Rule::enum(DestinoEvento::class)],
            'destinos.*.destino_id' => ['nullable', 'integer'],
        ], [
            'destinos.required' => 'Elige a quién se le aplica.',
            'cierra_en.after_or_equal' => 'No puede cerrarse antes de abrirse.',
        ], [
            'encuesta_id' => 'el cuestionario',
            'abre_en' => 'la fecha de apertura',
            'cierra_en' => 'la fecha de cierre',
        ]);

        $guardada = DB::transaction(function () use ($datos, $aplicacion) {
            $registro = $aplicacion ?? new AplicacionEncuesta;

            /*
             * Al crear se COPIA el cuestionario.
             *
             * Si apuntara a la plantilla, editarla en marzo cambiaría la
             * encuesta que trescientos alumnos contestaron en febrero, y los
             * resultados quedarían atribuidos a preguntas que nadie vio.
             */
            if ($aplicacion === null) {
                $original = Encuesta::with('preguntas.opciones')->findOrFail($datos['encuesta_id']);
                $datos['encuesta_id'] = $original->duplicar($datos['titulo'])->id;
            } else {
                unset($datos['encuesta_id']);
            }

            $registro->fill($datos)->save();

            $registro->destinos()->delete();

            foreach ($datos['destinos'] as $destino) {
                $registro->destinos()->create([
                    'tipo' => $destino['tipo'],
                    'destino_id' => $destino['destino_id'] ?? null,
                ]);
            }

            return $registro;
        });

        return $aplicacion === null
            ? to_route('tenant.encuestas.aplicaciones.ver', $guardada)
                ->with('exito', 'Aplicación creada. Falta elegir a quién se evalúa y publicarla.')
            : back(303)->with('exito', 'Aplicación actualizada.');
    }

    public function ver(AplicacionEncuesta $aplicacion, ResultadosDeEncuesta $resultados): Response
    {
        $aplicacion->load('encuesta');

        return Inertia::render('Encuestas/Aplicacion', [
            'aplicacion' => [
                'id' => $aplicacion->id,
                'titulo' => $aplicacion->titulo,
                'instrucciones' => $aplicacion->instrucciones,
                'cuestionario' => $aplicacion->encuesta?->titulo,
                'tipo' => $aplicacion->tipo,
                'estado' => $aplicacion->estado,
                'abierta' => $aplicacion->estaAbierta(),
                'obligatoria' => $aplicacion->obligatoria,
                'anonima' => $aplicacion->anonima,
                'abre_en' => $aplicacion->abre_en?->format('Y-m-d\TH:i'),
                'cierra_en' => $aplicacion->cierra_en?->format('Y-m-d\TH:i'),
            ],
            'resultados' => $resultados->de($aplicacion),
            // El tablero por docente sólo tiene sentido en la evaluación docente.
            'porSujeto' => $aplicacion->esDocente() ? $resultados->porSujeto($aplicacion) : [],
            'ciclos' => Ciclo::query()->orderByDesc('fecha_inicio')->get(['id', 'clave', 'nombre']),
        ]);
    }

    /**
     * Genera los docentes a evaluar a partir de filtros.
     *
     * Es lo que convierte «evaluar el ciclo 2026-1» en las cien encuestas que
     * eso significa, sin capturarlas a mano.
     */
    public function generarSujetos(Request $request, AplicacionEncuesta $aplicacion, GeneradorDeSujetos $generador): RedirectResponse
    {
        abort_unless($aplicacion->esDocente(), 400, 'Sólo la evaluación docente tiene a quién evaluar.');

        $filtros = $request->validate([
            'ciclo' => ['nullable', 'integer'],
            'campus' => ['nullable', 'integer'],
            'grupos' => ['array'],
            'grupos.*' => ['integer'],
            'materias' => ['array'],
            'materias.*' => ['integer'],
            'papeles' => ['array'],
            'papeles.*' => [Rule::in([Sujeto::TITULAR, Sujeto::ADJUNTO])],
        ]);

        $agregados = $generador->generar($aplicacion, $filtros);

        return back(303)->with(
            'exito',
            $agregados === 0
                ? 'No se agregó ninguno: con esos filtros no hay docentes asignados, o ya estaban todos.'
                : "Se agregaron {$agregados} docentes a evaluar.",
        );
    }

    /** Publicar o cerrar. */
    public function estado(Request $request, AplicacionEncuesta $aplicacion): RedirectResponse
    {
        $estado = $request->validate([
            'estado' => ['required', Rule::in([
                AplicacionEncuesta::BORRADOR,
                AplicacionEncuesta::PUBLICADA,
                AplicacionEncuesta::CERRADA,
            ])],
        ])['estado'];

        if ($estado === AplicacionEncuesta::PUBLICADA && $aplicacion->esDocente() && ! $aplicacion->sujetos()->exists()) {
            return back(303)->with(
                'error',
                'Falta elegir a quién se evalúa: una evaluación docente sin docentes no le llega a nadie.',
            );
        }

        $aplicacion->update(['estado' => $estado]);

        return back(303)->with('exito', match ($estado) {
            AplicacionEncuesta::PUBLICADA => 'La encuesta ya se puede contestar.',
            AplicacionEncuesta::CERRADA => 'Encuesta cerrada. Ya no se admiten respuestas.',
            default => 'Encuesta devuelta a borrador.',
        });
    }

    /** Los resultados de UN docente evaluado. */
    public function sujeto(AplicacionEncuesta $aplicacion, Sujeto $sujeto, ResultadosDeEncuesta $resultados): Response
    {
        abort_unless($sujeto->aplicacion_id === $aplicacion->id, 404);

        $sujeto->load(['persona', 'materia.planMateria.asignatura', 'materia.grupo']);

        return Inertia::render('Encuestas/Sujeto', [
            'aplicacion' => ['id' => $aplicacion->id, 'titulo' => $aplicacion->titulo, 'anonima' => $aplicacion->anonima],
            'sujeto' => [
                'docente' => $sujeto->persona?->nombreCompleto(),
                'materia' => $sujeto->materia?->planMateria?->asignatura?->nombre,
                'grupo' => $sujeto->materia?->grupo?->clave,
                'papel' => $sujeto->papel,
            ],
            'resultados' => $resultados->de($aplicacion, $sujeto->id),
        ]);
    }

    public function eliminar(AplicacionEncuesta $aplicacion): RedirectResponse
    {
        if ($aplicacion->participaciones()->exists()) {
            return back(303)->with(
                'error',
                'Ya hay respuestas: ciérrala en vez de borrarla. Lo contestado es el dato que la escuela pidió.',
            );
        }

        $aplicacion->delete();

        return back(303)->with('exito', 'Aplicación eliminada.');
    }
}
