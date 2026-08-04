<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionAsignaturaGrupo;
use App\Services\AsentadorActa;
use App\Services\CalculadoraCalificacion;
use App\Services\Lms\CopiadorDeCurso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apertura de materias dentro de un grupo y asignación de sus docentes.
 *
 * Abrir una materia es lo que la vuelve inscribible: hasta que existe una
 * `asignatura_grupo`, la materia solo es parte del plan, no algo que se pueda
 * cursar este ciclo.
 */
class AsignaturaGrupoController extends Controller
{
    public function __construct(private readonly CopiadorDeCurso $copiador) {}

    /**
     * Abre una o varias materias de golpe.
     *
     * Se reciben en lote porque abrir un grupo es cargar el semestre completo:
     * hacerlo de una en una son diez viajes al servidor y diez recargas para
     * una sola decisión del usuario.
     */
    public function store(Request $request, Grupo $grupo): RedirectResponse
    {
        $datos = $request->validate([
            'plan_materia_ids' => ['required', 'array', 'min:1'],
            'plan_materia_ids.*' => ['integer', Rule::exists('plan_materias', 'id')->whereNull('deleted_at')],
        ], [
            'plan_materia_ids.required' => 'Elige al menos una materia.',
            'plan_materia_ids.min' => 'Elige al menos una materia.',
        ], ['plan_materia_ids' => 'materias']);

        $pedidas = array_values(array_unique(array_map('intval', $datos['plan_materia_ids'])));

        $yaAbiertas = AsignaturaGrupo::query()
            ->where('grupo_id', $grupo->id)
            ->whereIn('plan_materia_id', $pedidas)
            ->pluck('plan_materia_id')
            ->all();

        $nuevas = array_values(array_diff($pedidas, $yaAbiertas));

        if ($nuevas === []) {
            throw ValidationException::withMessages([
                'plan_materia_ids' => count($pedidas) === 1
                    ? 'Esa materia ya está abierta en este grupo.'
                    : 'Todas las materias elegidas ya están abiertas en este grupo.',
            ]);
        }

        $activa = SituacionAsignaturaGrupo::query()->where('clave', 'activa')->value('id');
        $conPlantilla = 0;

        DB::transaction(function () use ($nuevas, $grupo, $activa, &$conPlantilla): void {
            foreach ($nuevas as $planMateriaId) {
                $abierta = AsignaturaGrupo::create([
                    'grupo_id' => $grupo->id,
                    'plan_materia_id' => $planMateriaId,
                    'situacion_id' => $activa,
                ]);

                /*
                 * Si la escuela armó el curso en el plan, el grupo nace con él.
                 * Se copia AQUÍ y no la primera vez que alguien entra a la
                 * materia: el docente tiene que encontrarlo listo el día que le
                 * asignan el grupo, no descubrirlo apareciendo solo.
                 */
                if ($this->copiador->alAbrirMateria($abierta) !== null) {
                    $conPlantilla++;
                }
            }
        });

        $mensaje = count($nuevas) === 1
            ? 'Materia abierta en el grupo.'
            : count($nuevas).' materias abiertas en el grupo.';

        if ($conPlantilla > 0) {
            $mensaje .= $conPlantilla === 1
                ? ' Una traía curso en línea, ya cargado.'
                : " {$conPlantilla} traían curso en línea, ya cargado.";
        }

        // Si venían repetidas se dice, en vez de fingir que se abrieron todas.
        if ($yaAbiertas !== []) {
            return back()->with('advertencia', $mensaje.' '.count($yaAbiertas).' ya estaban abiertas y se omitieron.');
        }

        return back()->with('exito', $mensaje);
    }

    /**
     * Asigna un docente a la materia. La spec fija una regla que el esquema no
     * puede imponer —MySQL no admite índices únicos parciales—: a lo más UN
     * titular por materia, porque es quien firma el acta.
     */
    public function asignarDocente(Request $request, Grupo $grupo, AsignaturaGrupo $asignatura): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $datos = $request->validate([
            'persona_id' => ['required', 'integer', Rule::exists('docentes', 'persona_id')],
            'tipo' => ['required', Rule::in(['titular', 'adjunto'])],
        ], [], ['persona_id' => 'docente']);

        if ($datos['tipo'] === 'titular') {
            $otroTitular = $asignatura->docentes()
                ->wherePivot('tipo', 'titular')
                ->where('docentes.persona_id', '!=', $datos['persona_id'])
                ->exists();

            if ($otroTitular) {
                throw ValidationException::withMessages([
                    'persona_id' => 'La materia ya tiene un titular. Quítalo antes de asignar otro.',
                ]);
            }
        }

        $asignatura->docentes()->syncWithoutDetaching([
            $datos['persona_id'] => ['tipo' => $datos['tipo']],
        ]);

        return back()->with('exito', 'Docente asignado.');
    }

    public function quitarDocente(Grupo $grupo, AsignaturaGrupo $asignatura, int $personaId): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $asignatura->docentes()->detach($personaId);

        return back()->with('exito', 'Docente retirado.');
    }

    /**
     * Una materia con alumnos inscritos no se cierra borrándola: se les perdería
     * la inscripción y, si ya hay calificaciones, el acta.
     */
    public function destroy(Grupo $grupo, AsignaturaGrupo $asignatura): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        if (Inscripcion::query()->where('asignatura_grupo_id', $asignatura->id)->exists()) {
            return back()->with('error', 'No se puede quitar: hay alumnos inscritos en esa materia.');
        }

        $asignatura->delete();

        return back()->with('exito', 'Materia retirada del grupo.');
    }

    /**
     * La lista de una materia: quién la cursa, quién la da y cómo van.
     *
     * ── Por qué hacía falta ────────────────────────────────────────────────
     * El grupo enseña cuántos inscritos tiene cada materia, y ahí se acababa:
     * para saber QUIÉNES eran había que ir al listado de alumnos y filtrar, y
     * para saber cómo iban, entrar a la captura del docente. Dos preguntas que
     * en control escolar se hacen juntas —«¿quién está en esta materia y cómo
     * va?»— y que no tenían una sola pantalla.
     *
     * ── El avance se calcula, no se lee ────────────────────────────────────
     * La calificación final sólo existe cuando se asienta el acta; hasta
     * entonces lo único que hay son componentes capturados. Se pondera aquí con
     * la MISMA calculadora que usa el cierre del acta, así que lo que se ve es
     * exactamente lo que saldría si se cerrara hoy —y no una segunda cuenta que
     * podría diverger—.
     */
    public function show(
        Grupo $grupo,
        AsignaturaGrupo $asignatura,
        CalculadoraCalificacion $calculadora,
        AsentadorActa $asentador,
    ): Response {
        // 404 y no 403: una materia de otro grupo no está en esta dirección.
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $asignatura->load([
            'planMateria.asignatura',
            'planMateria.plan',
            'docentes.persona',
            'grupo.ciclo',
            'grupo.campus',
        ]);

        $esquema = $asentador->esquema($asignatura);
        $plan = $asignatura->planMateria?->plan;

        $inscripciones = Inscripcion::query()
            ->where('asignatura_grupo_id', $asignatura->id)
            ->with(['matriculaOferta.persona', 'situacion', 'calificaciones'])
            ->get()
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->values();

        $alumnos = $inscripciones->map(function (Inscripcion $inscripcion) use ($esquema, $plan, $calculadora) {
            $resultado = $calculadora->calcular($inscripcion, $esquema, $plan);
            $capturadas = $inscripcion->calificaciones->keyBy('esquema_evaluacion_id');

            return [
                'inscripcion_id' => $inscripcion->id,
                'matricula_id' => $inscripcion->matricula_oferta_id,
                'matricula' => $inscripcion->matriculaOferta?->matricula,
                'nombre' => $inscripcion->matriculaOferta?->persona?->nombreCompleto() ?? 'Sin nombre',
                'situacion' => $inscripcion->situacion?->nombre,
                'de_baja' => $inscripcion->situacion?->clave === 'baja',
                // Una celda por componente del esquema, en su mismo orden.
                'componentes' => $esquema
                    ->map(fn ($c) => $capturadas->get($c->id)?->calificacion)
                    ->values(),
                'final' => $resultado->final,
                'completa' => $resultado->completa,
                'aprobada' => $resultado->aprobada,
                'faltantes' => $resultado->faltantes,
                // Ya asentada: el número dejó de ser provisional.
                'asentada' => $inscripcion->calificacion_final !== null,
            ];
        })->all();

        return Inertia::render('ControlEscolar/Grupos/Materia', [
            'grupo' => [
                'id' => $grupo->id,
                'clave' => $grupo->clave,
                'ciclo' => $grupo->ciclo?->clave,
                'campus' => $grupo->campus?->nombre,
            ],
            'materia' => [
                'id' => $asignatura->id,
                'nombre' => $asignatura->planMateria?->asignatura?->nombre ?? 'Sin nombre',
                'clave' => $asignatura->planMateria?->clave_en_plan,
                'plan' => $plan?->nombre,
                'minima_aprobatoria' => $plan?->calificacion_minima_aprobatoria,
            ],
            'docentes' => $asignatura->docentes->map(fn ($d) => [
                'nombre' => $d->persona?->nombreCompleto() ?? 'Sin nombre',
                'tipo' => $d->pivot->tipo ?? 'titular',
            ])->values(),
            /*
             * Las columnas de la tabla salen del esquema del plan, no de un
             * listado fijo: cada materia puede evaluarse distinto y una tabla
             * con columnas inventadas mostraría celdas que no existen.
             */
            'esquema' => $esquema->map(fn ($c) => [
                'id' => $c->id,
                'componente' => $c->componente,
                'porcentaje' => (float) $c->porcentaje,
            ])->values(),
            'alumnos' => $alumnos,
        ]);
    }
}
