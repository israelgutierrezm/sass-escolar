<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Http\Controllers\Concerns\ArmaExamenes;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Reactivo;
use App\Services\Lms\CopiadorDeCurso;
use App\Support\HtmlSeguro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El curso en línea de una materia del plan: la PLANTILLA.
 *
 * La arma la escuela una vez y cada grupo que abre esa materia nace con ella
 * copiada. Cuelga de `plan_materia` y no del grupo porque es del PLAN: la misma
 * materia impartida en tres grupos y dos campus espera el mismo contenido.
 *
 * Editar aquí no toca los grupos ya abiertos —lo suyo ya se copió— y alcanza a
 * los que se abran después. Es lo que permite corregir el plan sin cambiarle el
 * examen a un grupo que lo está contestando.
 */
class CursoPlantillaController extends Controller
{
    use ArmaExamenes;

    public function __construct(private readonly CopiadorDeCurso $copiador) {}

    /** El armado de la plantilla: presentación, permisos del docente y actividades. */
    public function show(PlanEstudio $plan, PlanMateria $materia): Response
    {
        $this->exigirDelPlan($plan, $materia);

        $curso = $this->plantillaDe($materia);

        return Inertia::render('Academico/Planes/CursoPlantilla', [
            'plan' => ['id' => $plan->id, 'nombre' => $plan->nombre, 'carrera' => $plan->carrera?->nombre],
            'materia' => [
                'id' => $materia->id,
                'clave' => $materia->clave_en_plan,
                'nombre' => $materia->asignatura?->nombre ?? 'Materia',
            ],
            'curso' => $curso === null ? null : [
                'id' => $curso->id,
                'titulo' => $curso->titulo,
                'presentacion' => $curso->presentacion,
                'docente_puede_agregar' => (bool) $curso->docente_puede_agregar,
                'docente_puede_ponderar' => (bool) $curso->docente_puede_ponderar,
                'publicado' => (bool) $curso->publicado,
            ],
            'actividades' => $curso === null ? [] : $curso->actividades->map(fn (Actividad $a) => [
                'id' => $a->id,
                'tipo' => $a->tipo->value,
                'tipo_etiqueta' => $a->tipo->etiqueta(),
                'se_entrega' => $a->tipo->seEntrega(),
                'titulo' => $a->titulo,
                'instrucciones' => $a->instrucciones,
                'contenido' => $a->contenido,
                'tiene_contenido' => $a->tieneContenido(),
                'puntos' => (float) $a->puntos,
                'permite_tarde' => (bool) $a->permite_tarde,
                'publicada' => (bool) $a->publicada,
                'esquema_evaluacion_id' => $a->esquema_evaluacion_id,
                'componente' => $a->componente?->etiquetaCompleta(),
                'tiene_examen' => $a->tipo === TipoActividad::Examen,
            ])->values(),
            'componentes' => EsquemaEvaluacion::query()
                ->where('plan_materia_id', $materia->id)
                ->orderBy('parcial')->orderBy('orden')
                ->get(['id', 'componente', 'parcial', 'porcentaje'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'etiqueta' => "Parcial {$c->parcial} · {$c->componente} ({$c->porcentaje}%)",
                ])->values(),
            'tiposActividad' => array_map(
                fn (TipoActividad $t) => ['valor' => $t->value, 'etiqueta' => $t->etiqueta(), 'se_entrega' => $t->seEntrega()],
                TipoActividad::cases(),
            ),
            // Cuántos grupos ya se la llevaron: dice si editar todavía alcanza a
            // alguien o si lo de allá afuera ya es independiente.
            'grupos_copiados' => $curso === null ? 0 : Curso::where('plantilla_origen_id', $curso->id)->count(),
            'grupos_abiertos' => AsignaturaGrupo::where('plan_materia_id', $materia->id)->count(),
        ]);
    }

    /** Crea o actualiza la plantilla en sí (lo que no son actividades). */
    public function guardar(Request $request, PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);

        $datos = $request->validate([
            'titulo' => ['nullable', 'string', 'max:180'],
            'presentacion' => ['nullable', 'string', 'max:20000'],
            'docente_puede_agregar' => ['boolean'],
            'docente_puede_ponderar' => ['boolean'],
            'publicado' => ['boolean'],
        ], [], ['presentacion' => 'presentación']);

        Curso::actualizarOReviver(['plan_materia_id' => $materia->id], $datos);

        return back()->with(
            'exito',
            ($datos['publicado'] ?? false)
                ? 'Plantilla guardada. Los grupos que se abran de aquí en adelante la traerán cargada.'
                : 'Plantilla guardada como borrador: todavía no se copia a ningún grupo.',
        );
    }

    /** Alta y edición de actividades de la plantilla. */
    public function guardarActividad(Request $request, PlanEstudio $plan, PlanMateria $materia, ?Actividad $actividad = null): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);

        $curso = $this->plantillaDe($materia) ?? Curso::create(['plan_materia_id' => $materia->id]);

        if ($actividad !== null && (int) $actividad->curso_id !== $curso->id) {
            abort(404);
        }

        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoActividad::class)],
            'titulo' => ['required', 'string', 'max:180'],
            'instrucciones' => ['nullable', 'string', 'max:20000'],
            'contenido' => ['nullable', 'string', 'max:200000'],
            'esquema_evaluacion_id' => ['nullable', 'integer'],
            'puntos' => ['required', 'numeric', 'min:1', 'max:1000'],
            'permite_tarde' => ['boolean'],
            'publicada' => ['boolean'],
        ], [], ['esquema_evaluacion_id' => 'componente de evaluación']);

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
            $esDeLaMateria = EsquemaEvaluacion::query()
                ->where('id', $datos['esquema_evaluacion_id'])
                ->where('plan_materia_id', $materia->id)
                ->exists();

            if (! $esDeLaMateria) {
                throw ValidationException::withMessages([
                    'esquema_evaluacion_id' => 'Ese componente no pertenece a esta materia.',
                ]);
            }
        }

        if ($actividad === null) {
            $datos['curso_id'] = $curso->id;
            $datos['orden'] = (int) Actividad::where('curso_id', $curso->id)->max('orden') + 1;

            Actividad::create($datos);
        } else {
            $actividad->update($datos);
        }

        return back()->with('exito', 'Actividad guardada en la plantilla.');
    }

    public function eliminarActividad(PlanEstudio $plan, PlanMateria $materia, Actividad $actividad): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);
        $this->exigirDeLaPlantilla($actividad, $materia);

        $actividad->delete();

        return back()->with('exito', 'Actividad eliminada de la plantilla.');
    }

    /**
     * Publicar o esconder desde el listado, sin abrir el formulario.
     *
     * En la plantilla el borrador es lo normal: la escuela arma el curso entero
     * y decide después qué sale. Reenviar los diez campos del formulario para
     * mover un interruptor era caro y arriesgaba pisar lo que no se tocó.
     */
    public function visibilidadActividad(Request $request, PlanEstudio $plan, PlanMateria $materia, Actividad $actividad): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);
        $this->exigirDeLaPlantilla($actividad, $materia);

        $publicada = $request->boolean('publicada');

        $actividad->update(['publicada' => $publicada]);

        // 303 y no el 302 de `back()`: ante un 302 el navegador repite el
        // redirect con el mismo PATCH contra una pantalla que sólo responde GET.
        return back(303)->with(
            'exito',
            $publicada
                ? "«{$actividad->titulo}» sale publicada a los grupos que abran."
                : "«{$actividad->titulo}» queda como borrador.",
        );
    }

    /* ── Exámenes de la plantilla ──────────────────────────────────────── */

    public function examen(PlanEstudio $plan, PlanMateria $materia, Actividad $actividad): Response
    {
        $this->exigirDelPlan($plan, $materia);
        $this->exigirDeLaPlantilla($actividad, $materia);

        $base = "/academico/planes/{$plan->id}/materias/{$materia->id}/curso/examenes/{$actividad->id}";

        return Inertia::render('Docencia/Examen', [
            ...$this->datosDeArmado($actividad, $base),
            'volver' => [
                'href' => "/academico/planes/{$plan->id}/materias/{$materia->id}/curso",
                'texto' => $materia->asignatura?->nombre ?? 'La plantilla',
            ],
            // Una plantilla no se presenta: no hay intentos que revisar.
            'intentos' => [],
            'ruta_calificar' => null,
        ]);
    }

    public function actualizarExamen(Request $request, PlanEstudio $plan, PlanMateria $materia, Actividad $actividad): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);
        $this->exigirDeLaPlantilla($actividad, $materia);
        $this->guardarReglas($request, $actividad);

        return back()->with('exito', 'Configuración del examen guardada.');
    }

    public function guardarReactivo(Request $request, PlanEstudio $plan, PlanMateria $materia, Actividad $actividad, ?Reactivo $reactivo = null): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);
        $this->exigirDeLaPlantilla($actividad, $materia);
        $this->guardarReactivoEn($request, (int) $actividad->curso_id, $reactivo);

        return back()->with('exito', 'Reactivo guardado.');
    }

    public function eliminarReactivo(PlanEstudio $plan, PlanMateria $materia, Actividad $actividad, Reactivo $reactivo): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);
        $this->exigirDeLaPlantilla($actividad, $materia);

        $motivo = $this->eliminarReactivoDe((int) $actividad->curso_id, $reactivo);

        return $motivo === null
            ? back()->with('exito', 'Reactivo eliminado del banco.')
            : back()->with('error', $motivo);
    }

    public function armarExamenDe(Request $request, PlanEstudio $plan, PlanMateria $materia, Actividad $actividad): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);
        $this->exigirDeLaPlantilla($actividad, $materia);
        $this->armarExamen($request, $actividad);

        return back()->with('exito', 'Examen armado.');
    }

    /**
     * Copia la plantilla a los grupos YA abiertos que todavía no tienen curso.
     *
     * Existe porque la copia automática ocurre al abrir la materia, y una
     * plantilla escrita después deja fuera a los grupos de ese ciclo. Sin esto
     * habría que cerrarlos y volverlos a abrir —perdiendo sus inscripciones—
     * para que llegara el contenido.
     *
     * No toca a los que ya tienen curso: ahí puede haber trabajo del docente y
     * hasta entregas de alumnos.
     */
    public function copiarAGrupos(PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        $this->exigirDelPlan($plan, $materia);

        $plantilla = $this->plantillaDe($materia);

        if ($plantilla === null || ! $plantilla->publicado) {
            return back()->with('error', 'Publica la plantilla antes de copiarla a los grupos.');
        }

        $copiados = 0;

        foreach (AsignaturaGrupo::where('plan_materia_id', $materia->id)->get() as $abierta) {
            if ($this->copiador->alAbrirMateria($abierta) !== null) {
                $copiados++;
            }
        }

        return back()->with(
            $copiados > 0 ? 'exito' : 'info',
            match (true) {
                $copiados === 0 => 'Ningún grupo estaba sin curso: no había nada que copiar.',
                $copiados === 1 => 'La plantilla se copió a un grupo.',
                default => "La plantilla se copió a {$copiados} grupos.",
            },
        );
    }

    private function plantillaDe(PlanMateria $materia): ?Curso
    {
        return Curso::with('actividades.componente')
            ->where('plan_materia_id', $materia->id)
            ->first();
    }

    private function exigirDelPlan(PlanEstudio $plan, PlanMateria $materia): void
    {
        abort_unless($materia->plan_id === $plan->id, 404);
    }

    private function exigirDeLaPlantilla(Actividad $actividad, PlanMateria $materia): void
    {
        abort_unless($actividad->curso?->plan_materia_id === $materia->id, 404);
    }
}
