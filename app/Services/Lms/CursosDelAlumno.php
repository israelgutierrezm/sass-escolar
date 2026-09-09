<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\ActividadVista;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\Grabacion;
use App\Models\Lms\Videoconferencia;
use Illuminate\Support\Collection;

/**
 * Lo que el alumno ve de sus materias: el listado y el detalle de cada una.
 *
 * ── Por qué es un servicio y salió del controlador ─────────────────────────
 * Vivía entero dentro de `MisCursosController`, y al llegar la app móvil hacían
 * falta los MISMOS datos por otra puerta. Copiarlo habría dado dos verdades: el
 * día que una cambie —qué cuenta como avance, qué actividad se muestra— la web
 * y la app dirían cosas distintas sobre la misma materia. La regla de este
 * proyecto es una sola verdad, no una segunda para la app, así que la lógica
 * vive aquí y la comparten el portal web (Inertia) y la API (JSON).
 *
 * El ALCANCE no vive aquí: qué inscripciones son del alumno lo resuelve
 * `AlcanceDelAlumno` en el controlador, contra la sesión o el token. Este
 * servicio recibe inscripciones YA acotadas y sólo arma la vista.
 */
class CursosDelAlumno
{
    /**
     * Las relaciones que el LISTADO necesita cargadas en cada inscripción.
     *
     * Se declaran aquí para que la web y la app carguen exactamente lo mismo:
     * una lista de relaciones copiada en dos sitios es como se llega a que una
     * pantalla pinte el campus y la otra no.
     *
     * @return array<int, string>
     */
    public static function relacionesListado(): array
    {
        return [
            'ciclo:id,clave,nombre,fecha_inicio,fecha_fin',
            'asignaturaGrupo.planMateria.asignatura:id,nombre',
            'asignaturaGrupo.grupo:id,clave,campus_id',
            'asignaturaGrupo.grupo.campus:id,nombre',
            'asignaturaGrupo.docentes.persona',
            'situacion:id,clave,nombre',
            'tipoEvaluacion:id,nombre',
        ];
    }

    /**
     * Las relaciones que el DETALLE de una materia necesita cargadas.
     *
     * @return array<int, string>
     */
    public static function relacionesDetalle(): array
    {
        return [
            'ciclo:id,clave,nombre',
            'asignaturaGrupo.planMateria.asignatura:id,nombre,clave',
            'asignaturaGrupo.planMateria.plan:id,nombre,programa_academico_id',
            'asignaturaGrupo.planMateria.plan.programaAcademico:id,nombre',
            'asignaturaGrupo.grupo:id,clave,campus_id,turno_id',
            'asignaturaGrupo.grupo.campus:id,nombre',
            'asignaturaGrupo.grupo.turno:id,nombre',
            'asignaturaGrupo.docentes.persona',
            'asignaturaGrupo.horarios',
            'situacion:id,clave,nombre',
            'tipoEvaluacion:id,nombre',
            'calificaciones',
        ];
    }

    /**
     * El listado de materias, agrupadas por ciclo (el vigente arriba), con lo
     * que le falta entregar de todas.
     *
     * @param  Collection<int, Inscripcion>  $inscripciones  ya acotadas y sin bajas
     * @return array<string, mixed>
     */
    public function listado(Collection $inscripciones): array
    {
        $aulas = $this->avanceDelAula($inscripciones);

        $cursos = $inscripciones->map(function (Inscripcion $i) use ($aulas) {
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
                // Lo recorrido del contenido, que es otra cosa que lo calificado:
                // se puede llevar el 80 % del curso leído y 0 % de calificación
                // porque el docente no ha revisado nada.
                'aula' => $aulas[$i->asignatura_grupo_id] ?? null,
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

        return [
            'ciclos' => $porCiclo,
            'pendientes' => $this->pendientes($inscripciones),
        ];
    }

    /**
     * El detalle de una materia: su evaluación, lo que lleva calificado, su
     * asistencia, sus docentes, horarios y clases en línea.
     *
     * @param  Inscripcion  $inscripcion  ya cargada con {@see relacionesDetalle}
     * @return array<string, mixed>
     */
    public function detalle(Inscripcion $inscripcion): array
    {
        $planMateria = $inscripcion->asignaturaGrupo?->planMateria;

        return [
            'curso' => [
                'id' => $inscripcion->asignatura_grupo_id,
                'clave' => $planMateria?->clave_en_plan,
                'materia' => $planMateria?->asignatura?->nombre,
                'periodo' => $planMateria?->periodo,
                'creditos' => $planMateria?->creditos,
                'programa_academico' => $planMateria?->plan?->programaAcademico?->nombre,
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
            'clasesEnLinea' => $this->clasesEnLineaDe($inscripcion),
        ];
    }

    /**
     * Lo que le falta entregar, de TODAS sus materias, con lo más urgente arriba.
     *
     * Solo cuenta lo que se entrega y él no ha entregado. Una tarea ya entregada
     * y sin calificar no es asunto suyo: es del docente.
     *
     * @param  Collection<int, Inscripcion>  $inscripciones
     * @return array<int, array<string, mixed>>
     */
    private function pendientes(Collection $inscripciones): array
    {
        $porMateria = $inscripciones->keyBy('asignatura_grupo_id');

        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $porMateria->keys())
            ->pluck('asignatura_grupo_id', 'id');

        if ($cursos->isEmpty()) {
            return [];
        }

        $actividades = Actividad::query()
            ->visibles()
            ->whereIn('curso_id', $cursos->keys())
            ->get()
            ->filter(fn (Actividad $a) => $a->tipo->seEntrega());

        $entregadas = Entrega::query()
            ->whereIn('inscripcion_id', $inscripciones->pluck('id'))
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('entregada_en')
            ->get()
            ->map(fn (Entrega $e) => "{$e->actividad_id}-{$e->inscripcion_id}")
            ->all();

        $ahora = now();

        return $actividades
            ->map(function (Actividad $a) use ($cursos, $porMateria, $entregadas, $ahora) {
                $inscripcion = $porMateria->get($cursos->get($a->curso_id));

                if ($inscripcion === null || in_array("{$a->id}-{$inscripcion->id}", $entregadas, true)) {
                    return null;
                }

                // Lo cerrado y ya vencido sin remedio no se lista: recordarle a
                // diario lo que ya no puede entregar no le sirve de nada.
                $vencidaSinRemedio = $a->cierra_en !== null
                    && $ahora->gt($a->cierra_en)
                    && ! $a->permite_tarde;

                if ($vencidaSinRemedio) {
                    return null;
                }

                return [
                    'id' => $a->id,
                    'materia_id' => $inscripcion->asignatura_grupo_id,
                    'materia' => $inscripcion->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                    'tipo' => $a->tipo->value,
                    'tipo_etiqueta' => $a->tipo->etiqueta(),
                    'titulo' => $a->titulo,
                    'puntos' => (float) $a->puntos,
                    'cierra_en' => $a->cierra_en?->format('Y-m-d H:i'),
                    /*
                     * Los días los cuenta el SERVIDOR. Calcularlos en el
                     * navegador ataría «vence hoy» al reloj de la computadora
                     * del alumno, que puede estar en otra zona o mal puesto.
                     * Null = sin fecha; negativo = ya venció pero admite tarde.
                     */
                    'dias' => $a->cierra_en === null
                        ? null
                        : (int) $ahora->copy()->startOfDay()->diffInDays($a->cierra_en->copy()->startOfDay(), false),
                    'permite_tarde' => (bool) $a->permite_tarde,
                ];
            })
            ->filter()
            // Lo que vence antes, arriba. Lo que no tiene fecha, al final: no
            // corre prisa y ocuparía el lugar de lo que sí.
            ->sortBy(fn (array $p) => $p['dias'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * Cuánto lleva recorrido del CONTENIDO de cada materia, para la tarjeta del
     * listado.
     *
     * No es el avance de la evaluación: una materia puede tener el 90 % del
     * contenido hecho y 0 % calificado porque el docente todavía no revisa. Son
     * dos preguntas distintas y el listado muestra las dos.
     *
     * Todo se resuelve con cuatro consultas para TODAS las materias juntas: una
     * por materia dejaría el listado de un alumno con ocho inscripciones
     * haciendo treinta y dos viajes a la base.
     *
     * @param  Collection<int, Inscripcion>  $inscripciones
     * @return array<int, array<string, mixed>>
     */
    private function avanceDelAula(Collection $inscripciones): array
    {
        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $inscripciones->pluck('asignatura_grupo_id'))
            ->pluck('asignatura_grupo_id', 'id');

        if ($cursos->isEmpty()) {
            return [];
        }

        $actividades = Actividad::query()
            ->visibles()
            ->whereIn('curso_id', $cursos->keys())
            ->get(['id', 'curso_id', 'tipo']);

        if ($actividades->isEmpty()) {
            return [];
        }

        $entregadas = Entrega::query()
            ->whereIn('inscripcion_id', $inscripciones->pluck('id'))
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('entregada_en')
            ->get(['actividad_id', 'inscripcion_id'])
            ->map(fn (Entrega $e) => "{$e->actividad_id}-{$e->inscripcion_id}")
            ->flip();

        $completadas = ActividadVista::query()
            ->whereIn('inscripcion_id', $inscripciones->pluck('id'))
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('completada_en')
            ->get(['actividad_id', 'inscripcion_id'])
            ->map(fn (ActividadVista $v) => "{$v->actividad_id}-{$v->inscripcion_id}")
            ->flip();

        $porCurso = $actividades->groupBy('curso_id');
        $resultado = [];

        foreach ($inscripciones as $inscripcion) {
            $cursoId = $cursos->search($inscripcion->asignatura_grupo_id);

            if ($cursoId === false) {
                continue;
            }

            $delCurso = $porCurso->get($cursoId) ?? collect();

            $hechas = $delCurso->filter(function (Actividad $a) use ($inscripcion, $entregadas, $completadas) {
                $llave = "{$a->id}-{$inscripcion->id}";

                return $a->tipo->seEntrega()
                    ? $entregadas->has($llave)
                    : $completadas->has($llave);
            })->count();

            $resultado[$inscripcion->asignatura_grupo_id] = [
                'total' => $delCurso->count(),
                'completadas' => $hechas,
                'porcentaje' => $delCurso->isEmpty() ? 0 : (int) round($hechas * 100 / $delCurso->count()),
            ];
        }

        return $resultado;
    }

    /**
     * Las clases en línea de esta materia, tal como se le pueden enseñar.
     *
     * El enlace sale por `paraElAlumno`, no a mano: es lo que garantiza que
     * nunca viaje `url_anfitrion` —el `start_url` de Zoom entra como dueño de la
     * sala— y que el `url_join` sólo aparezca mientras la clase está abierta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function clasesEnLineaDe(Inscripcion $inscripcion): array
    {
        $antelacion = app(Ajustes::class)->entero(CatalogoAjustes::VIDEO_ANTELACION);

        return Videoconferencia::query()
            ->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)
            ->with('grabaciones')
            ->vigentes()
            ->limit(10)
            ->get()
            ->map(fn (Videoconferencia $v) => $v->paraElAlumno($antelacion))
            ->concat($this->grabadasDe($inscripcion, $antelacion))
            ->values()
            ->all();
    }

    /**
     * Las clases ya terminadas que dejaron grabación visible.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function grabadasDe(Inscripcion $inscripcion, int $antelacion)
    {
        return Videoconferencia::query()
            ->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)
            ->where('fin', '<', now())
            ->whereHas('grabaciones', fn ($q) => $q
                ->where('estado', Grabacion::ARCHIVADA)
                ->where('visible_alumnos', true))
            ->with('grabaciones')
            ->orderByDesc('inicio')
            ->limit(10)
            ->get()
            ->map(fn (Videoconferencia $v) => $v->paraElAlumno($antelacion));
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
     * componente. Es el «cómo me van a calificar» y el «cómo voy», juntos.
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
                        // La clave del esquema viene como la escribió control
                        // escolar: `examen_p1`, `asistencia_p2`. Eso es un
                        // identificador, no un nombre, y al alumno hay que
                        // decirle «Examen» y no leerle una variable.
                        'componente' => $e->nombreLegible(),
                        'porcentaje' => (float) $e->porcentaje,
                        'calificacion' => $calificacion === null ? null : (float) $calificacion,
                    ];
                })->values();

                // Lo que lleva ganado del parcial: suma de cada componente por su
                // peso, contando SOLO lo ya capturado.
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

        // Lo que no se entrega lo declara terminado el alumno desde el aula.
        $completadas = ActividadVista::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('completada_en')
            ->pluck('actividad_id')
            ->flip();

        return $actividades->map(function (Actividad $a) use ($mias, $completadas) {
            $entrega = $mias->get($a->id);

            return [
                'completada' => $a->tipo->seEntrega()
                    ? $entrega?->entregada_en !== null
                    : $completadas->has($a->id),
                'id' => $a->id,
                'tipo' => $a->tipo->value,
                'tipo_etiqueta' => $a->tipo->etiqueta(),
                'se_entrega' => $a->tipo->seEntrega(),
                'titulo' => $a->titulo,
                'instrucciones' => $a->instrucciones,
                'puntos' => (float) $a->puntos,
                'abre_en' => $a->abre_en?->format('Y-m-d H:i'),
                'cierra_en' => $a->cierra_en?->format('Y-m-d H:i'),
                // Los cuenta el servidor: en el navegador, «vence hoy» quedaría
                // atado al reloj de la computadora del alumno.
                'dias' => $a->cierra_en === null
                    ? null
                    : (int) now()->startOfDay()->diffInDays($a->cierra_en->copy()->startOfDay(), false),
                'permite_tarde' => $a->permite_tarde,
                'abierta' => $a->abierta(),
                // Qué pesa: el componente al que cuelga, o nada si es formativa.
                'componente' => $a->componente?->etiquetaCompleta(),
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

        // Solo cuentan las que tienen un NÚMERO: la pantalla de captura crea una
        // fila por componente en cuanto se abre, aunque el docente no escriba
        // nada. Contar filas diría «100 % calificado» de una materia sin una
        // sola nota.
        $conNota = $inscripcion->calificaciones()
            ->whereIn('esquema_evaluacion_id', $ids)
            ->whereNotNull('calificacion')
            ->pluck('esquema_evaluacion_id');

        $pesoCapturado = (float) EsquemaEvaluacion::query()
            ->whereIn('id', $conNota)
            ->sum('porcentaje');

        return (int) round($pesoCapturado * 100 / $pesoTotal);
    }
}
