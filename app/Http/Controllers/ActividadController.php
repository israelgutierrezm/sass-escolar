<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Services\Lms\CalculadorComponente;
use App\Support\HtmlSeguro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Las actividades de una materia, desde el lado del docente.
 *
 * El alcance no lo da el permiso sino la ASIGNACIÓN, igual que en el resto del
 * portal del docente: `capturar-calificaciones` dice que puede calificar, la
 * asignación dice en qué materia. Un docente con el permiso y sin la materia
 * recibe 403.
 */
class ActividadController extends Controller
{
    use AutorizaMateriaPropia;

    public function __construct(private readonly CalculadorComponente $calculador) {}

    public function store(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);

        $curso = $this->cursoDe($asignaturaGrupo);
        $datos = $this->validar($request, $asignaturaGrupo, $curso);

        $datos['curso_id'] = $curso->id;
        $datos['orden'] = (int) Actividad::where('curso_id', $curso->id)->max('orden') + 1;

        Actividad::create($datos);

        return back()->with('exito', 'Actividad agregada.');
    }

    public function update(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        $actividad->update($this->validar($request, $asignaturaGrupo, $actividad->curso));

        // Cambiar a qué componente cuelga —o quitarle el amarre— altera lo que
        // promedia: hay que rehacer las cuentas de todos sus alumnos.
        $this->recalcularTodos($actividad);

        return back()->with('exito', 'Actividad actualizada.');
    }

    /**
     * Publicar o esconder, sin abrir el formulario.
     *
     * Es el gesto más repetido del docente —arma el curso en borrador y lo va
     * soltando conforme avanza el semestre— y era el más caro: abrir el editor,
     * buscar la casilla, guardar, y que el formulario reenviara los diez campos
     * con el riesgo de pisar algo sin querer. Aquí sólo viaja el interruptor.
     */
    public function visibilidad(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        $publicada = $request->boolean('publicada');

        $actividad->update(['publicada' => $publicada]);

        /*
         * 303 y no el 302 de `back()`.
         *
         * Ante un 302, el navegador repite el redirect CON EL MISMO MÉTODO: el
         * PATCH volvía a salir contra la pantalla de la materia, que sólo
         * responde GET, y el interruptor terminaba en un 405 aunque el cambio ya
         * estuviera guardado. El 303 es justo lo que dice «ya está hecho, ahora
         * ve a mirar allá con un GET».
         */
        return back(303)->with(
            'exito',
            $publicada
                ? "«{$actividad->titulo}» ya la ven tus alumnos."
                : "«{$actividad->titulo}» quedó oculta para tus alumnos.",
        );
    }

    public function destroy(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        $entregadas = $actividad->entregas()->whereNotNull('entregada_en')->count();
        $esquemaId = $actividad->esquema_evaluacion_id;
        $inscripciones = $actividad->entregas()->pluck('inscripcion_id');

        $actividad->delete();

        // Al desaparecer, lo que aportaba al componente deja de contar.
        if ($esquemaId !== null) {
            foreach ($inscripciones as $inscripcionId) {
                $this->calculador->recalcular((int) $inscripcionId, (int) $esquemaId);
            }
        }

        return back()->with(
            'exito',
            $entregadas > 0
                ? "Actividad eliminada junto con {$entregadas} entrega(s)."
                : 'Actividad eliminada.',
        );
    }

    /** Calificar la entrega de un alumno; el componente se recalcula solo. */
    public function calificar(Request $request, AsignaturaGrupo $asignaturaGrupo, Entrega $entrega): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($entrega->actividad, $asignaturaGrupo);

        $datos = $request->validate([
            'calificacion' => ['required', 'numeric', 'min:0', 'max:'.$entrega->actividad->puntos],
            'retroalimentacion' => ['nullable', 'string', 'max:4000'],
        ], [], ['calificacion' => 'calificación']);

        /** @var Usuario $usuario */
        $usuario = $request->user();

        $entrega->update([
            'calificacion' => $datos['calificacion'],
            'retroalimentacion' => $datos['retroalimentacion'] ?? null,
            'estado' => Entrega::CALIFICADA,
            'calificada_por' => $usuario->id,
            'calificada_en' => now(),
        ]);

        $this->calculador->tras($entrega);

        return back()->with('exito', 'Calificación registrada.');
    }

    /**
     * El curso de esta materia impartida, creándolo si es la primera actividad.
     *
     * Se crea al vuelo y no al abrir la materia: la mayoría de las materias
     * presenciales nunca cargarán contenido, y una fila vacía por cada una solo
     * ensucia.
     */
    private function cursoDe(AsignaturaGrupo $asignaturaGrupo): Curso
    {
        // `primeraOReviver` y no `actualizarOReviver`: el `publicado => true` es
        // el valor con el que NACE el curso, no algo que deba reimponerse cada
        // vez que se agrega una actividad.
        return Curso::primeraOReviver(
            ['asignatura_grupo_id' => $asignaturaGrupo->id],
            ['publicado' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, AsignaturaGrupo $asignaturaGrupo, Curso $curso): array
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoActividad::class)],
            'titulo' => ['required', 'string', 'max:180'],
            'instrucciones' => ['nullable', 'string', 'max:20000'],
            // Es HTML del editor y puede traer un SCORM o una lección larga:
            // el límite es generoso a propósito, pero existe para que un pegado
            // accidental no llene la tabla.
            'contenido' => ['nullable', 'string', 'max:200000'],
            'esquema_evaluacion_id' => ['nullable', 'integer'],
            'puntos' => ['required', 'numeric', 'min:1', 'max:1000'],
            'abre_en' => ['nullable', 'date'],
            'cierra_en' => ['nullable', 'date', 'after_or_equal:abre_en'],
            'permite_tarde' => ['boolean'],
            'permite_reentrega' => ['boolean'],
            'publicada' => ['boolean'],
        ], [], [
            'esquema_evaluacion_id' => 'componente de evaluación',
            'cierra_en' => 'fecha de cierre',
            'abre_en' => 'fecha de apertura',
        ]);

        // El material se pinta como HTML en la pantalla del alumno: entra por la
        // lista blanca antes de guardarse. La validación de arriba comprueba que
        // sea texto y quepa; no que sea inofensivo.
        $datos['contenido'] = HtmlSeguro::limpiar($datos['contenido'] ?? null);

        // Una lectura no se entrega, así que no pondera: dejarle un componente
        // amarrado prometería una calificación que nunca va a llegar.
        if ($datos['tipo'] === TipoActividad::Lectura->value) {
            $datos['esquema_evaluacion_id'] = null;
        }

        if ($datos['esquema_evaluacion_id'] !== null) {
            $this->exigirComponentePropio($datos['esquema_evaluacion_id'], $asignaturaGrupo, $curso);
        }

        return $datos;
    }

    /**
     * El componente tiene que ser de ESTA materia, y la escuela tiene que
     * dejar ponderar al docente. Las dos cosas se validan en el servidor: la
     * interfaz esconde el selector, pero el POST llega igual.
     */
    private function exigirComponentePropio(int $esquemaId, AsignaturaGrupo $asignaturaGrupo, Curso $curso): void
    {
        if (! $curso->docente_puede_ponderar) {
            throw ValidationException::withMessages([
                'esquema_evaluacion_id' => 'En este curso las actividades que agregas no pueden ponderar.',
            ]);
        }

        $esDeLaMateria = EsquemaEvaluacion::query()
            ->where('id', $esquemaId)
            ->where('plan_materia_id', $asignaturaGrupo->plan_materia_id)
            ->exists();

        if (! $esDeLaMateria) {
            throw ValidationException::withMessages([
                'esquema_evaluacion_id' => 'Ese componente no pertenece a esta materia.',
            ]);
        }
    }

    private function exigirDeLaMateria(?Actividad $actividad, AsignaturaGrupo $asignaturaGrupo): void
    {
        $suya = $actividad?->curso?->asignatura_grupo_id === $asignaturaGrupo->id;

        abort_unless($suya, 404);
    }

    /** Rehace el componente de todos los alumnos que entregaron la actividad. */
    private function recalcularTodos(Actividad $actividad): void
    {
        if ($actividad->esquema_evaluacion_id === null) {
            return;
        }

        foreach ($actividad->entregas()->pluck('inscripcion_id') as $inscripcionId) {
            $this->calculador->recalcular((int) $inscripcionId, (int) $actividad->esquema_evaluacion_id);
        }
    }

    /** Solo se toca una materia propia: se comprueba la asignación, no el permiso. */
    private function autorizar(Request $request, AsignaturaGrupo $asignaturaGrupo): void
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
    }
}
