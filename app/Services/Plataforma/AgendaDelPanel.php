<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Plataforma\EventoCalendario;
use Illuminate\Support\Collection;

/**
 * Lo que viene: la agenda que se pinta en el panel de cualquiera.
 *
 * ── Una sola línea de tiempo ───────────────────────────────────────────────
 * Un alumno no piensa «mis entregas» por un lado y «los avisos de la escuela»
 * por otro: piensa «qué me toca esta semana». Si el examen del martes y el
 * puente del miércoles viven en dos tarjetas distintas, la única forma de
 * planear es cruzarlas de memoria —y ahí es donde uno se entera tarde de que el
 * examen cayó justo después del día festivo—.
 *
 * Por eso aquí se juntan las dos fuentes y se ordenan por fecha:
 *
 *   - lo del CALENDARIO de la escuela, ya filtrado por a quién le toca
 *     ({@see AgendaDeUsuario});
 *   - lo que VENCE de sus materias: entregas y exámenes que el alumno debe, o
 *     que al docente le van a caer para calificar.
 *
 * ── Cada quien ve lo suyo ──────────────────────────────────────────────────
 * El alumno ve lo que le falta ENTREGAR; el docente, lo que cierra en sus
 * materias —porque es lo que va a tener que revisar—. Es la misma actividad
 * contada desde los dos lados del escritorio.
 */
class AgendaDelPanel
{
    public function __construct(private readonly AgendaDeUsuario $calendario) {}

    /**
     * Lo que viene en los próximos días, mezclado y ordenado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function proximos(Usuario $usuario, int $dias = 21, int $tope = 8): array
    {
        $desde = now()->startOfDay();
        $hasta = now()->addDays($dias)->endOfDay();

        $puntos = collect()
            ->concat($this->delCalendario($usuario, $desde->toDateString(), $hasta->toDateString()))
            ->concat($this->queVence($usuario, $hasta));

        return $puntos
            ->sortBy('fecha')
            ->take($tope)
            ->values()
            ->all();
    }

    /**
     * Los días con algo, para pintar los puntos del mini calendario.
     *
     * Se devuelve un mapa `AAAA-MM-DD => color` en vez de la lista entera: la
     * cuadrícula sólo necesita saber qué días llevan marca, y mandar el detalle
     * de cada evento para pintar un punto de tres píxeles sería mandar de más.
     *
     * @return array<string, string>
     */
    public function diasMarcados(Usuario $usuario, string $mes): array
    {
        $desde = "{$mes}-01";
        $hasta = date('Y-m-t', strtotime($desde));

        $marcas = [];

        foreach ($this->calendario->entre($usuario, $desde, $hasta) as $evento) {
            // Un receso abarca varios días y todos llevan marca: si sólo se
            // marcara el primero, el calendario diría que el 20 de diciembre
            // pasa algo y que el 21 es un día normal.
            $dia = $evento->inicia_en->copy()->startOfDay();
            $fin = $evento->finReal()->startOfDay();

            while ($dia->lte($fin)) {
                $clave = $dia->toDateString();

                // Gana el color del primero: dos eventos el mismo día son un
                // punto, no dos, y el tipo más «duro» suele ser el que se creó
                // antes (el feriado antes que el aviso).
                $marcas[$clave] ??= $evento->tipo->color();
                $dia->addDay();
            }
        }

        foreach ($this->queVence($usuario, now()->endOfMonth()->max($hasta)) as $punto) {
            $dia = substr((string) $punto['fecha'], 0, 10);

            if ($dia >= $desde && $dia <= $hasta) {
                $marcas[$dia] ??= $punto['color'];
            }
        }

        return $marcas;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function delCalendario(Usuario $usuario, string $desde, string $hasta): Collection
    {
        return $this->calendario->entre($usuario, $desde, $hasta)
            ->map(fn (EventoCalendario $e) => [
                'tipo' => 'evento',
                'clase' => $e->tipo->value,
                'etiqueta' => $e->tipo->etiqueta(),
                'color' => $e->tipo->color(),
                'titulo' => $e->titulo,
                'detalle' => $e->descripcion,
                'fecha' => $e->inicia_en->toDateTimeString(),
                'dia' => $e->inicia_en->toDateString(),
                'hora' => $e->todo_el_dia ? null : $e->inicia_en->format('H:i'),
                'termina' => $e->termina_en?->toDateString(),
                'no_laborable' => (bool) $e->no_laborable,
                'enlace' => null,
            ]);
    }

    /**
     * Lo que cierra en sus materias.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function queVence(Usuario $usuario, $hasta): Collection
    {
        $personaId = $usuario->persona_id;

        if ($personaId === null) {
            return collect();
        }

        $inscripciones = $this->misInscripciones($personaId);

        return $inscripciones->isEmpty()
            ? $this->deSusMaterias($personaId, $hasta)
            : $this->loQueDebe($inscripciones, $hasta);
    }

    /**
     * Del alumno: lo que le falta entregar y todavía puede.
     *
     * @param  Collection<int, Inscripcion>  $inscripciones
     * @return Collection<int, array<string, mixed>>
     */
    private function loQueDebe(Collection $inscripciones, $hasta): Collection
    {
        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $inscripciones->pluck('asignatura_grupo_id'))
            ->pluck('asignatura_grupo_id', 'id');

        if ($cursos->isEmpty()) {
            return collect();
        }

        $actividades = Actividad::query()
            ->visibles()
            ->whereIn('curso_id', $cursos->keys())
            ->whereNotNull('cierra_en')
            ->whereBetween('cierra_en', [now()->startOfDay(), $hasta])
            ->with('curso')
            ->get()
            ->filter(fn (Actividad $a) => $a->tipo->seEntrega());

        if ($actividades->isEmpty()) {
            return collect();
        }

        // Lo ya entregado no es pendiente: recordárselo sería ruido justo a
        // quien va al día.
        $entregadas = Entrega::query()
            ->whereIn('inscripcion_id', $inscripciones->pluck('id'))
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('entregada_en')
            ->get()
            ->map(fn (Entrega $e) => "{$e->actividad_id}-{$e->inscripcion_id}")
            ->flip();

        $porMateria = $inscripciones->keyBy('asignatura_grupo_id');

        return $actividades->map(function (Actividad $a) use ($cursos, $porMateria, $entregadas) {
            $inscripcion = $porMateria->get($cursos->get($a->curso_id));

            if ($inscripcion === null || $entregadas->has("{$a->id}-{$inscripcion->id}")) {
                return null;
            }

            $esExamen = $a->tipo->value === 'examen';

            return [
                'tipo' => 'entrega',
                'clase' => $a->tipo->value,
                'etiqueta' => $esExamen ? 'Examen' : 'Entrega',
                'color' => $esExamen ? '#db2777' : '#d97706',
                'titulo' => $a->titulo,
                'detalle' => $inscripcion->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                'fecha' => $a->cierra_en->toDateTimeString(),
                'dia' => $a->cierra_en->toDateString(),
                'hora' => $a->cierra_en->format('H:i'),
                'termina' => null,
                'no_laborable' => false,
                'enlace' => $esExamen
                    ? "/mis-cursos/examenes/{$a->id}"
                    : "/mis-cursos/{$inscripcion->asignatura_grupo_id}/aula/{$a->id}",
            ];
        })->filter()->values();
    }

    /**
     * Del docente: lo que cierra en las materias que imparte.
     *
     * No es lo que él debe entregar sino lo que le va a CAER: cuando esa
     * actividad cierre, tendrá cuarenta trabajos esperando revisión, y eso se
     * planea antes, no el día que llegan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function deSusMaterias(int $personaId, $hasta): Collection
    {
        $materias = \App\Models\ControlEscolar\AsignaturaGrupo::query()
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->with('planMateria.asignatura:id,nombre')
            ->get(['id', 'plan_materia_id']);

        if ($materias->isEmpty()) {
            return collect();
        }

        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $materias->pluck('id'))
            ->pluck('asignatura_grupo_id', 'id');

        if ($cursos->isEmpty()) {
            return collect();
        }

        $porMateria = $materias->keyBy('id');

        return Actividad::query()
            ->visibles()
            ->whereIn('curso_id', $cursos->keys())
            ->whereNotNull('cierra_en')
            ->whereBetween('cierra_en', [now()->startOfDay(), $hasta])
            ->get()
            ->map(function (Actividad $a) use ($cursos, $porMateria) {
                $materiaId = $cursos->get($a->curso_id);
                $materia = $porMateria->get($materiaId);

                return [
                    'tipo' => 'cierre',
                    'clase' => $a->tipo->value,
                    'etiqueta' => 'Cierra',
                    'color' => '#0891b2',
                    'titulo' => $a->titulo,
                    'detalle' => $materia?->planMateria?->asignatura?->nombre,
                    'fecha' => $a->cierra_en->toDateTimeString(),
                    'dia' => $a->cierra_en->toDateString(),
                    'hora' => $a->cierra_en->format('H:i'),
                    'termina' => null,
                    'no_laborable' => false,
                    'enlace' => "/docencia/materias/{$materiaId}",
                ];
            });
    }

    /**
     * @return Collection<int, Inscripcion>
     */
    private function misInscripciones(int $personaId): Collection
    {
        $matriculas = \App\Models\Admisiones\MatriculaOferta::query()
            ->where('persona_id', $personaId)
            ->pluck('id');

        if ($matriculas->isEmpty()) {
            return collect();
        }

        return Inscripcion::query()
            ->whereIn('matricula_oferta_id', $matriculas)
            ->with('asignaturaGrupo.planMateria.asignatura:id,nombre')
            ->get();
    }
}
