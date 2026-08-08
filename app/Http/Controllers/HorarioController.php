<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Aula;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\HorarioAsignaturaGrupo;
use App\Models\ControlEscolar\ReglaHorario;
use App\Services\Horarios\AplicadorHorario;
use App\Services\Horarios\GeneradorHorarios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El horario de un grupo: verlo, capturarlo a mano y proponerlo automáticamente.
 *
 * ── Las tres cosas en una pantalla, a propósito ────────────────────────────
 * Generar, revisar y corregir son el mismo trabajo hecho en tres momentos. Con
 * el generador en otra pantalla, la propuesta habría que aceptarla a ciegas y
 * volver aquí a ver cómo quedó; y el día que el motor no cuadre una materia
 * —que va a pasar— habría que salir a buscar dónde se arregla.
 *
 * ── Proponer no es aplicar ─────────────────────────────────────────────────
 * `generar` no escribe nada: devuelve la propuesta para que se vea encima del
 * horario actual. `aplicar` es un segundo acto deliberado, y revalida todo
 * contra el estado de la base porque entre una cosa y otra pueden pasar veinte
 * minutos y otra persona.
 */
class HorarioController extends Controller
{
    use AcotaPorCampus;

    public function __construct(
        private readonly GeneradorHorarios $generador,
        private readonly AplicadorHorario $aplicador,
    ) {}

    public function index(Request $request): Response
    {
        $cicloId = $request->integer('ciclo_id') ?: Ciclo::enCurso()?->id;
        $grupoId = $request->integer('grupo_id') ?: null;

        $grupos = Grupo::query()
            ->with('campus:id,nombre')
            ->when($cicloId, fn ($q) => $q->where('ciclo_id', $cicloId))
            ->tap(fn ($q) => $this->acotarPorCampusPropio($q, $request))
            ->orderBy('clave')
            ->get(['id', 'clave', 'nombre', 'campus_id', 'ciclo_id']);

        // El primero, para que la pantalla no abra vacía pidiendo un clic.
        $grupoId ??= $grupos->first()?->id;
        $grupo = $grupos->firstWhere('id', $grupoId);

        return Inertia::render('Horarios/Index', [
            'ciclos' => Ciclo::query()->orderByDesc('id')->limit(8)->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'nombre' => $c->clave ?? $c->nombre]),
            'cicloId' => $cicloId,
            'grupos' => $grupos->map(fn (Grupo $g) => [
                'id' => $g->id,
                'clave' => $g->clave,
                'campus' => $g->campus?->nombre,
            ]),
            'grupoId' => $grupoId,
            'materias' => $grupoId === null ? [] : $this->materiasDelGrupo($grupoId),
            'bloques' => $grupoId === null ? [] : $this->bloquesDelGrupo($grupoId),
            'aulas' => $grupo === null ? [] : Aula::query()
                ->where('campus_id', $grupo->campus_id)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'capacidad']),
            /*
             * Si hay reglas configuradas. Sin ellas se puede capturar a mano
             * —eso nunca dependió de nada— pero no generar, y la pantalla tiene
             * que decir por qué en vez de ofrecer un botón que va a fallar.
             */
            'regla' => $grupo === null ? null : ReglaHorario::resolver($grupo->ciclo_id, $grupo->campus_id)
                ?->only(['id', 'nombre', 'hora_apertura', 'hora_cierre', 'minutos_bloque', 'dias']),
            'puedeEditar' => $request->user()->can('editar-horarios'),
            'puedeGenerar' => $request->user()->can('generar-horarios'),
        ]);
    }

    /** Propone. No escribe nada. */
    public function generar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'grupo_ids' => ['required', 'array', 'min:1'],
            'grupo_ids.*' => ['integer', Rule::exists('grupos', 'id')->whereNull('deleted_at')],
        ], [], ['grupo_ids' => 'grupos']);

        $propuesta = $this->generador->paraGrupos($datos['grupo_ids']);

        // Viaja por sesión y no como respuesta: la pantalla se recarga con el
        // horario actual y la propuesta encima, y así un F5 no la repite.
        return back()->with('propuesta', $propuesta);
    }

    /** Escribe la propuesta ya revisada. */
    public function aplicar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'bloques' => ['required', 'array', 'min:1'],
            'bloques.*.asignatura_grupo_id' => ['required', 'integer'],
            'bloques.*.dia' => ['required', 'integer', 'between:1,7'],
            'bloques.*.hora_inicio' => ['required', 'date_format:H:i'],
            'bloques.*.hora_fin' => ['required', 'date_format:H:i'],
            'bloques.*.aula_id' => ['nullable', 'integer', Rule::exists('aulas', 'id')->whereNull('deleted_at')],
            'bloques.*.persona_id' => ['nullable', 'integer'],
            'bloques.*.modalidad' => ['required', 'in:presencial,en_linea'],
            'asignar_docentes' => ['boolean'],
        ]);

        $resultado = $this->aplicador->aplicar(
            $datos['bloques'],
            (bool) ($datos['asignar_docentes'] ?? false),
        );

        return back()->with(
            'exito',
            "Se aplicó el horario: {$resultado['aplicados']} bloques en {$resultado['materias']} materias.",
        );
    }

    // ── Captura manual ─────────────────────────────────────────────────────

    /**
     * Un bloque a mano.
     *
     * Existe aunque exista el generador, y no como respaldo de segunda: el
     * motor va a dejar materias sin colocar —lo dice él mismo— y sin esto la
     * única salida sería volver a generar todo esperando que salga distinto.
     */
    public function guardarBloque(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'asignatura_grupo_id' => ['required', 'integer', Rule::exists('asignatura_grupo', 'id')->whereNull('deleted_at')],
            'dia_semana' => ['required', 'integer', 'between:1,7'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'aula_id' => ['nullable', 'integer', Rule::exists('aulas', 'id')->whereNull('deleted_at')],
            'modalidad' => ['required', 'in:presencial,en_linea'],
        ], [
            'hora_fin.after' => 'La hora de fin tiene que ser posterior a la de inicio.',
        ]);

        /*
         * Un bloque suelto se valida con el MISMO aplicador que la propuesta.
         *
         * Es lo que impide que la captura manual meta los choques que el
         * generador tiene prohibido crear: dos puertas al mismo dato con dos
         * criterios distintos es cómo se llena una base de datos de horarios
         * imposibles.
         */
        $this->aplicador->aplicarUno([
            'asignatura_grupo_id' => $datos['asignatura_grupo_id'],
            'dia' => $datos['dia_semana'],
            'hora_inicio' => $datos['hora_inicio'],
            'hora_fin' => $datos['hora_fin'],
            'aula_id' => $datos['aula_id'] ?? null,
            'modalidad' => $datos['modalidad'],
        ]);

        return back()->with('exito', 'Bloque agregado al horario.');
    }

    public function eliminarBloque(Request $request, HorarioAsignaturaGrupo $bloque): RedirectResponse
    {
        $bloque->delete();

        return back()->with('exito', 'Bloque eliminado.');
    }

    // ── Lo que la pantalla necesita ────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function materiasDelGrupo(int $grupoId): array
    {
        return AsignaturaGrupo::query()
            ->with(['planMateria.asignatura:id,nombre,clave,horas_teoria,horas_practica', 'docentes.persona:id,nombre,primer_apellido'])
            ->where('grupo_id', $grupoId)
            ->get()
            ->map(function (AsignaturaGrupo $ag) {
                $asignatura = $ag->planMateria?->asignatura;
                $horas = (int) (($asignatura?->horas_teoria ?? 0) + ($asignatura?->horas_practica ?? 0));

                // Cuántas de esas horas ya están en el calendario: es la
                // verificación que la escuela pidió, y sin ella un horario
                // incompleto se ve idéntico a uno terminado.
                $colocadas = HorarioAsignaturaGrupo::query()
                    ->where('asignatura_grupo_id', $ag->id)
                    ->get()
                    ->sum(fn (HorarioAsignaturaGrupo $h) => (
                        strtotime((string) $h->hora_fin) - strtotime((string) $h->hora_inicio)
                    ) / 3600);

                return [
                    'id' => $ag->id,
                    'nombre' => $asignatura?->nombre ?? 'Materia',
                    'clave' => $asignatura?->clave,
                    'horas_requeridas' => $horas,
                    'horas_colocadas' => round((float) $colocadas, 1),
                    'docente' => $ag->docentes->first()?->persona?->nombre,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function bloquesDelGrupo(int $grupoId): array
    {
        return HorarioAsignaturaGrupo::query()
            ->with(['asignaturaGrupo.planMateria.asignatura:id,nombre', 'aula:id,nombre'])
            ->whereHas('asignaturaGrupo', fn ($q) => $q->where('grupo_id', $grupoId))
            ->orderBy('dia_semana')->orderBy('hora_inicio')
            ->get()
            ->map(fn (HorarioAsignaturaGrupo $h) => [
                'id' => $h->id,
                'asignatura_grupo_id' => $h->asignatura_grupo_id,
                'materia' => $h->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                'dia' => (int) $h->dia_semana,
                'hora_inicio' => substr((string) $h->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $h->hora_fin, 0, 5),
                'aula' => $h->aula?->nombre,
                'aula_id' => $h->aula_id,
                'modalidad' => $h->modalidad ?? 'presencial',
            ])
            ->values()
            ->all();
    }
}
