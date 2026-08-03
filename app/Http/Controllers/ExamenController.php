<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ArmaExamenes;
use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Lms\Actividad;
use App\Models\Lms\Examen;
use App\Models\Lms\Intento;
use App\Models\Lms\Reactivo;
use App\Models\Lms\Respuesta;
use App\Services\Lms\AplicadorExamen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Armado y revisión de exámenes, del lado del docente.
 *
 * Tiene pantalla propia y no vive dentro del editor de actividades porque son
 * dos trabajos distintos: poner fecha y ponderación toma un minuto, redactar
 * treinta reactivos toma una tarde. Meterlos en el mismo formulario obligaría a
 * cargar el banco entero cada vez que alguien corrige un título.
 *
 * El armado en sí está en `ArmaExamenes`: es el mismo trabajo que hace la
 * escuela sobre la plantilla del plan. Aquí queda lo propio del docente —quién
 * entra, y la revisión de lo que la máquina no puede calificar—.
 */
class ExamenController extends Controller
{
    use ArmaExamenes;
    use AutorizaMateriaPropia;

    public function __construct(private readonly AplicadorExamen $aplicador) {}

    /** La pantalla de armado: reglas, banco del curso y lo que arma el examen. */
    public function show(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): Response
    {
        $this->autorizar($request, $asignaturaGrupo, $actividad);

        return Inertia::render('Docencia/Examen', [
            ...$this->datosDeArmado($actividad, "/docencia/materias/{$asignaturaGrupo->id}/examenes/{$actividad->id}"),
            'volver' => [
                'href' => "/docencia/materias/{$asignaturaGrupo->id}",
                'texto' => $asignaturaGrupo->planMateria?->asignatura?->nombre ?? 'Materia',
            ],
            'intentos' => $this->intentosDelExamen($this->examenDe($actividad)),
            'ruta_calificar' => "/docencia/materias/{$asignaturaGrupo->id}/respuestas",
        ]);
    }

    /** Guarda las reglas de aplicación. */
    public function actualizar(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo, $actividad);
        $this->guardarReglas($request, $actividad);

        return back()->with('exito', 'Configuración del examen guardada.');
    }

    /** Alta o edición de un reactivo del banco. */
    public function guardarReactivo(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad, ?Reactivo $reactivo = null): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo, $actividad);
        $this->guardarReactivoEn($request, (int) $actividad->curso_id, $reactivo);

        return back()->with('exito', 'Reactivo guardado.');
    }

    public function eliminarReactivo(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad, Reactivo $reactivo): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo, $actividad);

        $motivo = $this->eliminarReactivoDe((int) $actividad->curso_id, $reactivo);

        return $motivo === null
            ? back()->with('exito', 'Reactivo eliminado del banco.')
            : back()->with('error', $motivo);
    }

    /** Mete o saca reactivos del examen, con el peso que tienen DENTRO de él. */
    public function armar(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo, $actividad);
        $this->armarExamen($request, $actividad);

        return back()->with('exito', 'Examen armado.');
    }

    /** El docente pone puntos a un reactivo que la máquina no puede calificar. */
    public function calificarRespuesta(Request $request, AsignaturaGrupo $asignaturaGrupo, Respuesta $respuesta): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);

        $suya = $respuesta->intento?->examen?->actividad?->curso?->asignatura_grupo_id === $asignaturaGrupo->id;
        abort_unless($suya, 404);

        $datos = $request->validate([
            'puntos' => ['required', 'numeric', 'min:0'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->aplicador->calificarAMano($respuesta, (float) $datos['puntos'], $datos['comentario'] ?? null);

        return back()->with('exito', 'Respuesta calificada.');
    }

    /**
     * Los intentos entregados, con lo que falta por revisar arriba: la pantalla
     * sirve para revisar, no para consultar historial.
     *
     * @return array<int, array<string, mixed>>
     */
    private function intentosDelExamen(Examen $examen): array
    {
        return Intento::query()
            ->with([
                'inscripcion.matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'respuestas.reactivo',
            ])
            ->where('examen_id', $examen->id)
            ->whereNotNull('entregado_en')
            ->orderByDesc('requiere_revision')
            ->orderByDesc('entregado_en')
            ->get()
            ->map(function (Intento $intento) use ($examen) {
                $persona = $intento->inscripcion?->matriculaOferta?->persona;

                return [
                    'id' => $intento->id,
                    'numero' => $intento->numero,
                    'alumno' => trim(implode(' ', array_filter([
                        $persona?->nombre,
                        $persona?->primer_apellido,
                        $persona?->segundo_apellido,
                    ]))) ?: 'Alumno',
                    'entregado_en' => $intento->entregado_en?->toDateTimeString(),
                    'puntos_obtenidos' => (float) $intento->puntos_obtenidos,
                    'puntos_posibles' => (float) $intento->puntos_posibles,
                    'requiere_revision' => (bool) $intento->requiere_revision,
                    /*
                     * Las capturas se cuentan aunque el examen las permita: que
                     * estuvieran permitidas no vuelve el dato inútil —el docente
                     * sigue queriendo saber quién fotografió su examen—, y que
                     * estuvieran prohibidas es cuando más importa.
                     */
                    'capturas' => (int) $intento->capturas_detectadas,
                    'primera_captura' => $intento->capturas[0]['en'] ?? null,
                    // Solo lo que espera al docente: lo autocalificado no se revisa.
                    'pendientes' => $intento->respuestas
                        ->filter(fn (Respuesta $r) => $r->puntos === null)
                        ->map(fn (Respuesta $r) => [
                            'id' => $r->id,
                            'enunciado' => $r->reactivo?->enunciado,
                            'respondio' => $r->valor['v'] ?? null,
                            'tope' => $examen->puntosDe($r->reactivo),
                        ])->values(),
                ];
            })
            ->values()
            ->all();
    }

    /** La materia tiene que ser suya, y la actividad tiene que ser de ella. */
    private function autorizar(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): void
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);

        abort_unless($actividad->curso?->asignatura_grupo_id === $asignaturaGrupo->id, 404);
    }
}
