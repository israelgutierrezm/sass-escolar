<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\ActividadVista;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El AULA: la materia recorrida como un libro, una lección a la vez.
 *
 * ── Por qué una pantalla aparte de «Mi materia» ────────────────────────────
 * Son dos preguntas distintas y mezclarlas hacía que ninguna se contestara bien:
 *
 *   «¿Cómo voy?»      → calificaciones, asistencia, quién imparte  → Mi materia
 *   «¿Qué sigue?»     → el contenido, en orden, marcando lo hecho  → el Aula
 *
 * Antes todo vivía en una lista larga donde el material —cuando lo había— era un
 * párrafo de instrucciones apretado entre una píldora de estado y un botón. Con
 * lecciones de verdad (texto, video incrustado, un SCORM) eso ya no daba: leer
 * exige una columna ancha, silencio alrededor y un solo siguiente paso visible.
 *
 * ── Qué es «completada» ────────────────────────────────────────────────────
 * Lo que se entrega —actividad, foro, examen— se completa al entregarse: la
 * constancia ya existe en `entregas` y no hay nada que declarar. La lectura no
 * deja rastro, así que la declara el alumno con un botón, y eso se guarda en
 * `actividad_vistas.completada_en`.
 *
 * Marcar la lectura como hecha con solo abrirla habría sido más cómodo de
 * programar y habría llenado la barra de progreso de mentiras: 100 % de un curso
 * apenas hojeado. La barra sólo sirve si se le puede creer.
 */
class AulaController extends Controller
{
    use AlcanceDelAlumno;

    /**
     * Una lección, con el índice completo del curso al lado.
     *
     * Sin lección en la URL entra por donde se quedó: la primera sin completar.
     * Volver siempre a la lección uno obligaría a buscar cada vez dónde iba, que
     * es la queja de siempre de los cursos en línea mal hechos.
     */
    public function show(Request $request, int $asignaturaGrupo, ?int $actividad = null): Response
    {
        $inscripcion = $this->miInscripcionEn($request, $asignaturaGrupo, [
            'ciclo:id,clave,nombre',
            'asignaturaGrupo.planMateria.asignatura:id,nombre,clave',
            'asignaturaGrupo.grupo:id,clave',
            'asignaturaGrupo.docentes.persona',
        ]);

        $curso = Curso::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo)
            ->first();

        $lecciones = $curso === null ? collect() : $this->leccionesDe($curso, $inscripcion);

        // Se registra el paso por la lección aunque no se complete: es lo que
        // permite volver aquí la próxima vez sin buscar.
        $actual = $this->leccionActual($lecciones, $actividad);

        if ($actual !== null) {
            $this->registrarPaso((int) $actual['id'], $inscripcion->id);
        }

        $planMateria = $inscripcion->asignaturaGrupo?->planMateria;

        return Inertia::render('MisCursos/Aula', [
            'curso' => [
                'id' => $asignaturaGrupo,
                'materia' => $planMateria?->asignatura?->nombre,
                'clave' => $planMateria?->clave_en_plan,
                'grupo' => $inscripcion->asignaturaGrupo?->grupo?->clave,
                'ciclo' => $inscripcion->ciclo?->clave,
                'presentacion' => $curso?->presentacion,
                'docente' => $inscripcion->asignaturaGrupo?->docentes->first()?->persona?->nombreCompleto(),
            ],
            'unidades' => $this->porUnidad($lecciones),
            'leccion' => $actual,
            'vecinas' => $this->vecinasDe($lecciones, $actual),
            'progreso' => $this->progresoDe($lecciones),
        ]);
    }

    /**
     * «Ya la terminé», dicho por el alumno sobre una lectura.
     *
     * Sólo aplica a lo que no se entrega. Dejar que alguien marcara como hecha
     * una tarea sin entregarla convertiría el progreso en un botón de mentir a
     * sí mismo, que es lo contrario de para lo que sirve.
     */
    public function completar(Request $request, int $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $inscripcion = $this->miInscripcionEn($request, $asignaturaGrupo);

        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        abort_if($actividad->tipo->seEntrega(), 422, 'Esta actividad se completa entregándola.');

        ActividadVista::actualizarOReviver(
            ['actividad_id' => $actividad->id, 'inscripcion_id' => $inscripcion->id],
            ['vista_en' => now(), 'completada_en' => now()],
        );

        return back();
    }

    /** Deshacer el «ya la terminé»: se marca de más y hay que poder corregirlo. */
    public function descompletar(Request $request, int $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $inscripcion = $this->miInscripcionEn($request, $asignaturaGrupo);

        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        ActividadVista::query()
            ->where('actividad_id', $actividad->id)
            ->where('inscripcion_id', $inscripcion->id)
            ->update(['completada_en' => null]);

        return back();
    }

    /**
     * Las lecciones del curso con lo de ESTE alumno en cada una.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function leccionesDe(Curso $curso, Inscripcion $inscripcion): Collection
    {
        $actividades = Actividad::query()
            ->visibles()
            ->where('curso_id', $curso->id)
            ->with(['componente:id,componente,parcial', 'rubrica.criterios.niveles'])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        if ($actividades->isEmpty()) {
            return collect();
        }

        $entregas = Entrega::query()
            // Con el desglose: es lo que convierte «8.33» en «te faltó
            // ortografía», que es a lo que sirve una rúbrica.
            ->with(['archivos', 'porRubrica', 'evidencias.archivos'])
            ->where('inscripcion_id', $inscripcion->id)
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->get()
            ->keyBy('actividad_id');

        $vistas = ActividadVista::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->get()
            ->keyBy('actividad_id');

        return $actividades->values()->map(function (Actividad $a, int $i) use ($entregas, $vistas) {
            $entrega = $entregas->get($a->id);
            $vista = $vistas->get($a->id);

            /*
             * Completada según lo que la actividad DEJA como rastro: la entrega
             * si se entrega, el botón del alumno si no. Un solo criterio por
             * tipo, para que la barra de progreso y el palomeado del índice no
             * puedan contradecirse.
             */
            $completada = $a->tipo->seEntrega()
                ? $entrega?->entregada_en !== null
                : $vista?->completada_en !== null;

            return [
                'id' => $a->id,
                'numero' => $i + 1,
                'tipo' => $a->tipo->value,
                'tipo_etiqueta' => $a->tipo->etiqueta(),
                'se_entrega' => $a->tipo->seEntrega(),
                'titulo' => $a->titulo,
                'instrucciones' => $a->instrucciones,
                'contenido' => $a->contenido,
                'tiene_contenido' => $a->tieneContenido(),
                'puntos' => (float) $a->puntos,
                'abre_en' => $a->abre_en?->format('Y-m-d H:i'),
                'cierra_en' => $a->cierra_en?->format('Y-m-d H:i'),
                // Los días los cuenta el servidor: en el navegador, «vence hoy»
                // quedaría atado al reloj de la computadora del alumno.
                'dias' => $a->cierra_en === null
                    ? null
                    : (int) now()->startOfDay()->diffInDays($a->cierra_en->copy()->startOfDay(), false),
                'permite_tarde' => (bool) $a->permite_tarde,
                // Se le dice ANTES de entregar, no cuando ya no puede corregir.
                'permite_reentrega' => (bool) $a->permite_reentrega,
                'abierta' => $a->abierta(),
                'parcial' => $a->componente?->parcial,
                'componente' => $a->componente?->etiquetaCompleta(),
                /*
                 * La rúbrica ENTERA, esté o no calificado.
                 *
                 * Antes de entregar es lo que de verdad sirve: leer qué se va a
                 * mirar y qué hay que hacer para el nivel de arriba. Enseñarla
                 * sólo con la nota la volvería una explicación a toro pasado, y
                 * la escuela ya habría escrito los descriptores para nada.
                 */
                'rubrica' => $a->rubrica === null ? null : [
                    'id' => $a->rubrica->id,
                    'nombre' => $a->rubrica->nombre,
                    'total' => $a->rubrica->total(),
                    'criterios' => $a->rubrica->criterios->map(fn ($c) => [
                        'id' => $c->id,
                        'titulo' => $c->titulo,
                        'descripcion' => $c->descripcion,
                        'maximo' => $c->maximo(),
                        'niveles' => $c->niveles->map(fn ($n) => [
                            'id' => $n->id,
                            'titulo' => $n->titulo,
                            'descripcion' => $n->descripcion,
                            'puntos' => (float) $n->puntos,
                        ])->values(),
                    ])->values(),
                ],
                'completada' => $completada,
                'visitada' => $vista !== null,
                'entrega' => $entrega === null ? null : [
                    'id' => $entrega->id,
                    'estado' => $entrega->estado,
                    'contenido' => $entrega->contenido,
                    'entregada_en' => $entrega->entregada_en?->format('Y-m-d H:i'),
                    'tarde' => (bool) $entrega->tarde,
                    'calificacion' => $entrega->calificacion === null ? null : (float) $entrega->calificacion,
                    'retroalimentacion' => $entrega->retroalimentacion,
                    // En qué nivel quedó cada criterio, y la nota que el
                    // docente le dejó en ese renglón.
                    'por_rubrica' => $entrega->porRubrica->map(fn ($r) => [
                        'criterio_id' => (int) $r->criterio_id,
                        'nivel_id' => $r->nivel_id === null ? null : (int) $r->nivel_id,
                        'puntos' => (float) $r->puntos,
                        'comentario' => $r->comentario,
                    ])->values(),
                    'archivos' => $entrega->archivos->map(fn ($f) => [
                        'id' => $f->id,
                        'nombre' => $f->nombre,
                    ])->values(),
                    /*
                     * Las piezas del portafolio, si lo es. Van DENTRO de la
                     * entrega porque son suyas: la entrega es el trabajo y
                     * éstas son sus partes. Sacarlas a un prop aparte obligaría
                     * a la pantalla a cruzarlas.
                     */
                    'evidencias' => $entrega->evidencias->map(fn ($e) => [
                        'id' => $e->id,
                        'titulo' => $e->titulo,
                        'descripcion' => $e->descripcion,
                        'fecha' => $e->fecha_evidencia?->format('Y-m-d'),
                        'archivos' => $e->archivos->map(fn ($f) => [
                            'id' => $f->id,
                            'nombre' => $f->nombre,
                            'peso' => $f->pesoLegible(),
                        ])->values(),
                    ])->values(),
                ],
            ];
        });
    }

    /**
     * La lección pedida; si no se pidió ninguna, por donde iba.
     *
     * @param  Collection<int, array<string, mixed>>  $lecciones
     * @return array<string, mixed>|null
     */
    private function leccionActual(Collection $lecciones, ?int $actividad): ?array
    {
        if ($lecciones->isEmpty()) {
            return null;
        }

        if ($actividad !== null) {
            $pedida = $lecciones->firstWhere('id', $actividad);

            // Pedir una lección que no está en el curso es un id inventado o de
            // otra materia: 404, igual que cualquier ruta inexistente.
            abort_if($pedida === null, 404);

            return $pedida;
        }

        return $lecciones->first(fn (array $l) => ! $l['completada']) ?? $lecciones->first();
    }

    /**
     * Anterior y siguiente, para recorrer el curso sin volver al índice.
     *
     * @param  Collection<int, array<string, mixed>>  $lecciones
     * @return array<string, mixed>
     */
    private function vecinasDe(Collection $lecciones, ?array $actual): array
    {
        if ($actual === null) {
            return ['anterior' => null, 'siguiente' => null];
        }

        $enOrden = $lecciones->values();
        $i = $enOrden->search(fn (array $l) => $l['id'] === $actual['id']);

        $resumen = fn (?array $l) => $l === null ? null : [
            'id' => $l['id'],
            'titulo' => $l['titulo'],
            'tipo' => $l['tipo'],
        ];

        return [
            'anterior' => $resumen($i > 0 ? $enOrden[$i - 1] : null),
            'siguiente' => $resumen($i < $enOrden->count() - 1 ? $enOrden[$i + 1] : null),
        ];
    }

    /**
     * El índice, agrupado por parcial.
     *
     * El parcial es la única división que el sistema ya conoce y que además
     * significa algo para el alumno: «Parcial 2» le dice cuándo entra eso en su
     * calificación.
     *
     * ── El problema de las lecturas ────────────────────────────────────────
     * Sólo lo que pondera tiene parcial. Agrupar tal cual mandaba TODAS las
     * lecturas a un cajón al final, así que el curso se leía «Parcial 1: el
     * ejercicio / Parcial 2: la práctica / Material: las tres lecciones» —el
     * ejercicio antes de la lección que hay que leer para hacerlo—.
     *
     * Por eso cada lección sin parcial hereda el de la siguiente que sí lo
     * tenga: el material que ANTECEDE a un ejercicio pertenece al mismo bloque
     * que el ejercicio. Es lo que el docente ya dijo con el orden en que las
     * puso, sin pedirle que lo repita en un campo nuevo.
     *
     * @param  Collection<int, array<string, mixed>>  $lecciones
     * @return array<int, array<string, mixed>>
     */
    private function porUnidad(Collection $lecciones): array
    {
        $enOrden = $lecciones->values();
        $parciales = $this->parcialEfectivo($enOrden);

        // Un curso donde nada pondera es un curso sin parciales: un solo bloque
        // corrido dice la verdad mejor que inventar «Parcial 1».
        $sinParciales = collect($parciales)->filter()->isEmpty();

        return $enOrden
            ->groupBy(fn (array $l, int $i) => $parciales[$i] ?? 0)
            ->map(fn (Collection $ls, $parcial) => [
                'clave' => (int) $parcial,
                'nombre' => $sinParciales || (int) $parcial === 0
                    ? 'Contenido del curso'
                    : "Parcial {$parcial}",
                'lecciones' => $ls->values()->all(),
                'completadas' => $ls->where('completada', true)->count(),
                'total' => $ls->count(),
            ])
            ->sortBy('clave')
            ->values()
            ->all();
    }

    /**
     * A qué parcial pertenece cada lección, incluidas las que no ponderan.
     *
     * @param  Collection<int, array<string, mixed>>  $enOrden
     * @return array<int, int>
     */
    private function parcialEfectivo(Collection $enOrden): array
    {
        $parciales = [];
        $pendientes = [];
        // Lo que quede sin parcial al final del curso se queda con el último
        // visto: si no, una lección de cierre caería en un bloque suelto.
        $ultimo = 0;

        foreach ($enOrden as $i => $leccion) {
            $suyo = $leccion['parcial'];

            if ($suyo === null) {
                $pendientes[] = $i;

                continue;
            }

            foreach ($pendientes as $j) {
                $parciales[$j] = (int) $suyo;
            }

            $pendientes = [];
            $parciales[$i] = (int) $suyo;
            $ultimo = (int) $suyo;
        }

        foreach ($pendientes as $j) {
            $parciales[$j] = $ultimo;
        }

        ksort($parciales);

        return $parciales;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lecciones
     * @return array<string, mixed>
     */
    private function progresoDe(Collection $lecciones): array
    {
        $total = $lecciones->count();
        $completadas = $lecciones->where('completada', true)->count();

        return [
            'total' => $total,
            'completadas' => $completadas,
            'porcentaje' => $total === 0 ? 0 : (int) round($completadas * 100 / $total),
            // Lo que le falta y todavía puede hacer: el número que mueve.
            'pendientes' => $lecciones
                ->filter(fn (array $l) => ! $l['completada'] && ($l['abierta'] || ! $l['se_entrega']))
                ->count(),
        ];
    }

    /** Deja constancia de que pasó por aquí, sin declararla terminada. */
    private function registrarPaso(int $actividadId, int $inscripcionId): void
    {
        $vista = ActividadVista::query()
            ->where('actividad_id', $actividadId)
            ->where('inscripcion_id', $inscripcionId)
            ->first();

        if ($vista !== null) {
            return;
        }

        // `primeraOReviver` y no `create`: la fila pudo quedar borrada
        // lógicamente y el UNIQUE de la tabla sigue viéndola.
        ActividadVista::primeraOReviver(
            ['actividad_id' => $actividadId, 'inscripcion_id' => $inscripcionId],
            ['vista_en' => now()],
        );
    }

    private function exigirDeLaMateria(Actividad $actividad, int $asignaturaGrupo): void
    {
        abort_unless($actividad->curso?->asignatura_grupo_id === $asignaturaGrupo, 404);
    }
}
