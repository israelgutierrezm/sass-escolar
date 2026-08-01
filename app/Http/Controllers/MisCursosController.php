<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal del ALUMNO: las materias que cursa.
 *
 * El alcance no lo da un permiso amplio sino la PERTENENCIA, igual que en el
 * portal del padre: solo se ven las inscripciones de las matrículas de la
 * persona que entró. Cambiar el id en la URL para espiar la materia de otro
 * choca contra esa comprobación y devuelve 403.
 *
 * Toda la información que muestra ya existía en la base —esquema de evaluación,
 * componentes capturados, asistencia, docentes—; lo que faltaba era la puerta.
 * Antes el alumno podía entrar al sistema y no tenía a dónde ir: su rol traía
 * dos permisos y ninguna pantalla propia.
 */
class MisCursosController extends Controller
{
    /** Las materias que cursa, agrupadas por ciclo (el vigente primero). */
    public function index(Request $request): Response
    {
        $inscripciones = $this->misInscripciones($request)
            ->with([
                'ciclo:id,clave,nombre,fecha_inicio,fecha_fin',
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
                'asignaturaGrupo.grupo:id,clave,campus_id',
                'asignaturaGrupo.grupo.campus:id,nombre',
                'asignaturaGrupo.docentes.persona',
                'situacion:id,clave,nombre',
                'tipoEvaluacion:id,nombre',
            ])
            ->get()
            ->reject(fn (Inscripcion $i) => $i->situacion?->clave === 'baja');

        $cursos = $inscripciones->map(function (Inscripcion $i) {
            $planMateria = $i->asignaturaGrupo?->planMateria;

            return [
                'id' => $i->asignatura_grupo_id,
                'inscripcion_id' => $i->id,
                'clave' => $planMateria?->clave_en_plan,
                'materia' => $planMateria?->asignatura?->nombre,
                'periodo' => $planMateria?->periodo,
                'grupo' => $i->asignaturaGrupo?->grupo?->clave,
                'campus' => $i->asignaturaGrupo?->grupo?->campus?->nombre,
                'ciclo' => $i->ciclo?->clave,
                'ciclo_id' => $i->ciclo_id,
                'ciclo_nombre' => $i->ciclo?->nombre,
                'situacion' => $i->situacion?->nombre,
                'tipo_evaluacion' => $i->tipoEvaluacion?->nombre,
                'docentes' => $this->docentesDe($i),
                // El avance se calcula aquí y no en la vista: es el dato que
                // ordena la lista y el que el alumno viene a ver primero.
                'avance' => $this->avanceDe($i),
            ];
        })->values();

        // Por ciclo, el más reciente arriba: lo que se cursa ahora es lo que se
        // consulta a diario; lo viejo se mira de vez en cuando.
        $porCiclo = $cursos
            ->groupBy('ciclo')
            ->map(fn (Collection $c, string $ciclo) => [
                'ciclo' => $ciclo,
                'nombre' => $c->first()['ciclo_nombre'],
                'cursos' => $c->sortBy('clave')->values(),
            ])
            ->sortByDesc('ciclo')
            ->values();

        return Inertia::render('MisCursos/Index', ['ciclos' => $porCiclo]);
    }

    /** Una materia: su evaluación, lo que lleva calificado, su asistencia. */
    public function show(Request $request, int $asignaturaGrupo): Response
    {
        $inscripcion = $this->misInscripciones($request)
            ->where('asignatura_grupo_id', $asignaturaGrupo)
            ->with([
                'ciclo:id,clave,nombre',
                'asignaturaGrupo.planMateria.asignatura:id,nombre,clave',
                'asignaturaGrupo.planMateria.plan:id,nombre,carrera_id',
                'asignaturaGrupo.planMateria.plan.carrera:id,nombre',
                'asignaturaGrupo.grupo:id,clave,campus_id,turno_id',
                'asignaturaGrupo.grupo.campus:id,nombre',
                'asignaturaGrupo.grupo.turno:id,nombre',
                'asignaturaGrupo.docentes.persona',
                'asignaturaGrupo.horarios',
                'situacion:id,clave,nombre',
                'tipoEvaluacion:id,nombre',
                'calificaciones',
            ])
            ->first();

        // No existe, o existe pero no es suya: la misma respuesta. Distinguirlas
        // le diría a quien prueba ids ajenos cuáles sí existen.
        abort_if($inscripcion === null, 403, 'Esa materia no está entre las que cursas.');

        $planMateria = $inscripcion->asignaturaGrupo?->planMateria;

        return Inertia::render('MisCursos/Materia', [
            'curso' => [
                'id' => $inscripcion->asignatura_grupo_id,
                'clave' => $planMateria?->clave_en_plan,
                'materia' => $planMateria?->asignatura?->nombre,
                'periodo' => $planMateria?->periodo,
                'creditos' => $planMateria?->creditos,
                'carrera' => $planMateria?->plan?->carrera?->nombre,
                'plan' => $planMateria?->plan?->nombre,
                'grupo' => $inscripcion->asignaturaGrupo?->grupo?->clave,
                'campus' => $inscripcion->asignaturaGrupo?->grupo?->campus?->nombre,
                'turno' => $inscripcion->asignaturaGrupo?->grupo?->turno?->nombre,
                'ciclo' => $inscripcion->ciclo?->clave,
                'ciclo_nombre' => $inscripcion->ciclo?->nombre,
                'situacion' => $inscripcion->situacion?->nombre,
                'tipo_evaluacion' => $inscripcion->tipoEvaluacion?->nombre,
                'avance' => $this->avanceDe($inscripcion),
            ],
            'docentes' => $this->docentesDe($inscripcion),
            'evaluacion' => $this->evaluacionDe($inscripcion),
            'actividades' => $this->actividadesDe($inscripcion),
            'asistencia' => $this->asistenciaDe($inscripcion),
            'horarios' => $inscripcion->asignaturaGrupo?->horarios
                ->map(fn ($h) => [
                    'dia' => $h->dia_semana ?? null,
                    'inicio' => $h->hora_inicio ?? null,
                    'fin' => $h->hora_fin ?? null,
                    'aula' => $h->aula ?? null,
                ])->values() ?? [],
        ]);
    }

    /**
     * Base de TODAS las consultas: las inscripciones de las matrículas de quien
     * entró. Un solo punto para la pertenencia, para que ninguna pantalla del
     * portal pueda olvidarse de aplicarla.
     */
    private function misInscripciones(Request $request)
    {
        $matriculas = $request->user()->persona?->matriculas()->pluck('matricula_oferta.id') ?? collect();

        return Inscripcion::query()->whereIn('matricula_oferta_id', $matriculas);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function docentesDe(Inscripcion $inscripcion): array
    {
        return ($inscripcion->asignaturaGrupo?->docentes ?? collect())
            ->map(fn ($d) => [
                'id' => $d->persona_id,
                'nombre' => $d->persona?->nombreCompleto(),
                'foto' => $d->persona?->urlFoto(),
                'tipo' => $d->pivot->tipo,
                'correo' => $d->persona?->correo_institucional ?? $d->persona?->email,
            ])
            ->values()
            ->all();
    }

    /**
     * La forma de evaluación de la materia con lo que el alumno lleva en cada
     * componente. Es el «cómo me van a calificar» y el «cómo voy», juntos:
     * separados obligan a cruzarlos de memoria.
     *
     * @return array<string, mixed>
     */
    private function evaluacionDe(Inscripcion $inscripcion): array
    {
        $planMateriaId = $inscripcion->asignaturaGrupo?->plan_materia_id;

        if ($planMateriaId === null) {
            return ['parciales' => [], 'sin_esquema' => true];
        }

        $esquema = EsquemaEvaluacion::query()
            ->where('plan_materia_id', $planMateriaId)
            ->orderBy('parcial')
            ->orderBy('orden')
            ->get();

        if ($esquema->isEmpty()) {
            return ['parciales' => [], 'sin_esquema' => true];
        }

        $capturadas = $inscripcion->calificaciones->keyBy('esquema_evaluacion_id');

        $parciales = $esquema
            ->groupBy('parcial')
            ->map(function (Collection $componentes, $parcial) use ($capturadas) {
                $filas = $componentes->map(function (EsquemaEvaluacion $e) use ($capturadas) {
                    $calificacion = $capturadas->get($e->id)?->calificacion;

                    return [
                        'componente' => $e->componente,
                        'porcentaje' => (float) $e->porcentaje,
                        'calificacion' => $calificacion === null ? null : (float) $calificacion,
                    ];
                })->values();

                // Lo que lleva ganado del parcial: suma de cada componente por su
                // peso, contando SOLO lo ya capturado. Deja ver «voy 6.4 de los
                // 8 puntos que ya se calificaron» sin prometer un final.
                $capturado = $filas->filter(fn ($f) => $f['calificacion'] !== null);
                $pesoCapturado = $capturado->sum('porcentaje');
                $ganado = $capturado->sum(fn ($f) => $f['calificacion'] * $f['porcentaje'] / 100);

                return [
                    'parcial' => (int) $parcial,
                    'componentes' => $filas,
                    'peso_total' => (float) $filas->sum('porcentaje'),
                    'peso_capturado' => (float) $pesoCapturado,
                    'ganado' => round((float) $ganado, 2),
                    'completo' => $capturado->count() === $filas->count(),
                ];
            })
            ->values();

        return [
            'parciales' => $parciales,
            'sin_esquema' => false,
        ];
    }

    /**
     * Las actividades de la materia con LO SUYO en cada una: si entregó, cuándo,
     * qué le calificaron y qué le dijeron.
     *
     * Solo las publicadas y ya abiertas: una actividad que el docente está
     * preparando no debe asomar, y una con fecha de apertura futura tampoco.
     *
     * @return array<int, array<string, mixed>>
     */
    private function actividadesDe(Inscripcion $inscripcion): array
    {
        $curso = Curso::query()
            ->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)
            ->first();

        if ($curso === null) {
            return [];
        }

        $actividades = Actividad::query()
            ->visibles()
            ->where('curso_id', $curso->id)
            ->with('componente:id,componente,parcial')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        $mias = Entrega::query()
            ->with('archivos')
            ->where('inscripcion_id', $inscripcion->id)
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->get()
            ->keyBy('actividad_id');

        return $actividades->map(function (Actividad $a) use ($mias) {
            $entrega = $mias->get($a->id);

            return [
                'id' => $a->id,
                'tipo' => $a->tipo->value,
                'tipo_etiqueta' => $a->tipo->etiqueta(),
                'se_entrega' => $a->tipo->seEntrega(),
                'titulo' => $a->titulo,
                'instrucciones' => $a->instrucciones,
                'puntos' => (float) $a->puntos,
                'abre_en' => $a->abre_en?->format('Y-m-d H:i'),
                'cierra_en' => $a->cierra_en?->format('Y-m-d H:i'),
                'permite_tarde' => $a->permite_tarde,
                'abierta' => $a->abierta(),
                // Qué pesa: el componente al que cuelga, o nada si es formativa.
                'componente' => $a->componente === null
                    ? null
                    : "Parcial {$a->componente->parcial} · {$a->componente->componente}",
                'entrega' => $entrega === null ? null : [
                    'id' => $entrega->id,
                    'estado' => $entrega->estado,
                    'contenido' => $entrega->contenido,
                    'entregada_en' => $entrega->entregada_en?->format('Y-m-d H:i'),
                    'tarde' => $entrega->tarde,
                    'calificacion' => $entrega->calificacion === null ? null : (float) $entrega->calificacion,
                    'retroalimentacion' => $entrega->retroalimentacion,
                    'archivos' => $entrega->archivos->map(fn ($f) => [
                        'id' => $f->id,
                        'nombre' => $f->nombre,
                    ])->values(),
                ],
            ];
        })->values()->all();
    }

    /**
     * Su asistencia en esta materia. Se devuelven los renglones y el resumen:
     * el número suelto («85 %») no dice qué día faltó, y la lista sola obliga a
     * contar a mano.
     *
     * @return array<string, mixed>
     */
    private function asistenciaDe(Inscripcion $inscripcion): array
    {
        $registros = AsistenciaClase::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->orderByDesc('fecha')
            ->get(['fecha', 'estatus', 'observacion']);

        $total = $registros->count();
        $presentes = $registros->whereIn('estatus', ['presente', 'retardo'])->count();

        return [
            'registros' => $registros->map(fn ($r) => [
                'fecha' => $r->fecha?->format('Y-m-d') ?? (string) $r->fecha,
                'estatus' => $r->estatus,
                'observacion' => $r->observacion,
            ])->values(),
            'total' => $total,
            'presentes' => $presentes,
            'faltas' => $registros->where('estatus', 'falta')->count(),
            'retardos' => $registros->where('estatus', 'retardo')->count(),
            'porcentaje' => $total === 0 ? null : round($presentes * 100 / $total),
        ];
    }

    /**
     * Avance de la materia: qué proporción del peso total ya está calificada.
     * No es la calificación —es cuánto del camino se ha recorrido—, que es lo
     * que un alumno pregunta a media materia.
     */
    private function avanceDe(Inscripcion $inscripcion): ?int
    {
        $planMateriaId = $inscripcion->asignaturaGrupo?->plan_materia_id;

        if ($planMateriaId === null) {
            return null;
        }

        $pesoTotal = (float) EsquemaEvaluacion::query()
            ->where('plan_materia_id', $planMateriaId)
            ->sum('porcentaje');

        if ($pesoTotal <= 0) {
            return null;
        }

        $ids = EsquemaEvaluacion::query()->where('plan_materia_id', $planMateriaId)->pluck('id');

        $pesoCapturado = (float) EsquemaEvaluacion::query()
            ->whereIn('id', $inscripcion->calificaciones()->whereIn('esquema_evaluacion_id', $ids)->pluck('esquema_evaluacion_id'))
            ->sum('porcentaje');

        return (int) round($pesoCapturado * 100 / $pesoTotal);
    }
}
