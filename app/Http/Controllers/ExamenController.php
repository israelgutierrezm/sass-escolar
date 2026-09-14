<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ArmaExamenes;
use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Lms\Actividad;
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
            'intentos' => $this->aplicador->intentosParaRevisar($this->examenDe($actividad)),
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

    /** La materia tiene que ser suya, y la actividad tiene que ser de ella. */
    private function autorizar(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): void
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);

        abort_unless($actividad->curso?->asignatura_grupo_id === $asignaturaGrupo->id, 404);
    }
}
