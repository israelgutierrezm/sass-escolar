<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Services\CalendarioCaptura;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Portal del docente: lo suyo y nada más.
 *
 * Un docente NO es personal administrativo. Antes conservaba el permiso
 * `ver-grupos` y con él le aparecía Control escolar completo —ciclos y grupos
 * de toda la escuela, pantallas pensadas para otro oficio—. Aquí ve
 * únicamente las materias que imparte, sus alumnos y su propio expediente.
 *
 * El alcance no lo da el permiso sino la asignación en
 * `docente_asignatura_grupo`: cada pantalla arranca de ahí, así que no hay
 * forma de llegar a la materia de otro cambiando un id en la URL.
 */
class DocenciaController extends Controller
{
    public function __construct(private readonly CalendarioCaptura $calendario) {}

    /** Mis materias, agrupadas por ciclo. */
    public function index(Request $request): Response
    {
        $personaId = $this->personaId($request);
        $ciclo = $this->cicloSeleccionado($request);

        $materias = AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'planMateria.plan:id,nombre',
                'grupo:id,clave,ciclo_id,campus_id',
                'grupo.ciclo:id,clave,nombre',
                'grupo.campus:id,nombre',
                'horarios.aula:id,nombre',
                'actas',
            ])
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->when($ciclo !== null, fn ($q) => $q->whereHas('grupo', fn ($g) => $g->where('ciclo_id', $ciclo->id)))
            ->get()
            ->map(fn (AsignaturaGrupo $ag) => [
                'id' => $ag->id,
                'clave_en_plan' => $ag->planMateria?->clave_en_plan,
                'materia' => $ag->planMateria?->asignatura?->nombre,
                'plan' => $ag->planMateria?->plan?->nombre,
                'grupo' => $ag->grupo?->clave,
                'campus' => $ag->grupo?->campus?->nombre,
                'ciclo' => $ag->grupo?->ciclo?->clave,
                // Su papel en ESTA materia: el adjunto captura pero no firma.
                'soy' => $ag->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
                'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $ag->id)->count(),
                'horarios' => $ag->horarios->map(fn ($h) => [
                    'dia' => $h->dia_semana,
                    'inicio' => substr((string) $h->hora_inicio, 0, 5),
                    'fin' => substr((string) $h->hora_fin, 0, 5),
                    'aula' => $h->aula?->nombre,
                ])->values(),
                'acta_cerrada' => $ag->actas->contains(fn ($a) => $a->situacion === 'cerrada'),
                // Cortes que puede capturar hoy: es lo primero que quiere saber
                // al entrar, y evita que descubra la ventana cerrada al final.
                'cortes_abiertos' => collect($this->calendario->estadoPorParcial($ag, $personaId))
                    ->filter(fn (array $e) => $e['abierto'])
                    ->count(),
                'cortes_totales' => count($this->calendario->estadoPorParcial($ag, $personaId)),
            ])
            ->sortBy(['ciclo', 'grupo', 'clave_en_plan'])
            ->values()
            ->all();

        return Inertia::render('Docencia/Index', [
            'materias' => $materias,
            'ciclos' => Ciclo::query()
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
            'cicloId' => $ciclo?->id,
            'puedeCapturar' => $request->user()->can('capturar-calificaciones'),
        ]);
    }

    /** Detalle de UNA materia mía: quiénes son mis alumnos. */
    public function materia(Request $request, AsignaturaGrupo $asignaturaGrupo): Response
    {
        $personaId = $this->autorizarMateria($request, $asignaturaGrupo);

        $asignaturaGrupo->load([
            'planMateria.asignatura',
            'planMateria.plan:id,nombre',
            'grupo.ciclo',
            'grupo.campus:id,nombre',
            'horarios.aula:id,nombre',
            'docentes.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);

        $inscripciones = Inscripcion::query()
            ->with([
                'matriculaOferta:id,persona_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido,email,celular',
                'situacion:id,clave,nombre',
            ])
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->get()
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->values();

        return Inertia::render('Docencia/Materia', [
            'materia' => [
                'id' => $asignaturaGrupo->id,
                'clave_en_plan' => $asignaturaGrupo->planMateria?->clave_en_plan,
                'nombre' => $asignaturaGrupo->planMateria?->asignatura?->nombre,
                'plan' => $asignaturaGrupo->planMateria?->plan?->nombre,
                'grupo' => $asignaturaGrupo->grupo?->clave,
                'campus' => $asignaturaGrupo->grupo?->campus?->nombre,
                'ciclo' => $asignaturaGrupo->grupo?->ciclo?->clave,
                'soy' => $asignaturaGrupo->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
            ],
            'horarios' => $asignaturaGrupo->horarios->map(fn ($h) => [
                'dia' => $h->dia_semana,
                'inicio' => substr((string) $h->hora_inicio, 0, 5),
                'fin' => substr((string) $h->hora_fin, 0, 5),
                'aula' => $h->aula?->nombre,
            ])->values(),
            'companeros' => $asignaturaGrupo->docentes
                ->reject(fn ($d) => $d->persona_id === $personaId)
                ->map(fn ($d) => ['nombre' => $d->persona?->nombreCompleto(), 'tipo' => $d->pivot->tipo])
                ->values(),
            'alumnos' => $inscripciones->map(fn (Inscripcion $i) => [
                'matricula' => $i->matriculaOferta?->matricula,
                'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                'email' => $i->matriculaOferta?->persona?->email,
                'celular' => $i->matriculaOferta?->persona?->celular,
                'tipo' => $i->tipo,
                'situacion' => $i->situacion?->nombre,
                'de_baja' => $i->situacion?->clave === 'baja',
                'calificacion_final' => $i->calificacion_final,
            ]),
            'calendario' => $this->calendario->estadoPorParcial($asignaturaGrupo, $personaId),
            'puedeCapturar' => $request->user()->can('capturar-calificaciones'),
            'puedePasarLista' => $request->user()->can('pasar-lista'),
            ...$this->datosLms($asignaturaGrupo, $inscripciones),
            ...$this->datosAsistencia($request, $asignaturaGrupo, $inscripciones),
        ]);
    }

    /**
     * El pase de lista: lo ya registrado de la fecha que se está viendo y el
     * resumen de cada alumno.
     *
     * La fecha llega por la URL para que recargar no pierda el día que se
     * estaba pasando, y por omisión es hoy —que es cuando se pasa lista—.
     *
     * @param  \Illuminate\Support\Collection<int, Inscripcion>  $inscripciones
     * @return array<string, mixed>
     */
    private function datosAsistencia(Request $request, AsignaturaGrupo $asignaturaGrupo, $inscripciones): array
    {
        $fecha = $request->query('fecha') ?: now()->format('Y-m-d');
        $modalidad = $request->query('modalidad') ?: ($asignaturaGrupo->doble_pase_lista ? 'teorica' : 'unica');

        $ids = $inscripciones->pluck('id');

        $delDia = AsistenciaClase::query()
            ->whereIn('inscripcion_id', $ids)
            ->whereDate('fecha', $fecha)
            ->where('modalidad', $modalidad)
            ->get()
            ->keyBy('inscripcion_id');

        // Resumen por alumno de TODA la materia: es lo que dice si alguien está
        // en riesgo por inasistencias, que es para lo que se pasa lista.
        $historial = AsistenciaClase::query()
            ->whereIn('inscripcion_id', $ids)
            ->selectRaw('inscripcion_id, estatus, COUNT(*) c')
            ->groupBy('inscripcion_id', 'estatus')
            ->get()
            ->groupBy('inscripcion_id');

        return [
            'asistencia' => [
                'fecha' => $fecha,
                'modalidad' => $modalidad,
                'doble' => (bool) $asignaturaGrupo->doble_pase_lista,
                // Cuántas sesiones se han pasado, para no repetir una por error.
                'sesiones' => AsistenciaClase::query()
                    ->whereIn('inscripcion_id', $ids)
                    ->selectRaw('DATE(fecha) f, modalidad')
                    ->distinct()
                    ->orderByDesc('f')
                    ->limit(30)
                    ->get()
                    ->map(fn ($s) => ['fecha' => $s->f, 'modalidad' => $s->modalidad])
                    ->values(),
                'lista' => $inscripciones
                    ->reject(fn (Inscripcion $i) => $i->situacion?->clave === 'baja')
                    ->map(function (Inscripcion $i) use ($delDia, $historial) {
                        $conteo = ($historial->get($i->id) ?? collect())->pluck('c', 'estatus');
                        $total = $conteo->sum();
                        $presentes = (int) $conteo->get('presente', 0) + (int) $conteo->get('retardo', 0);

                        return [
                            'inscripcion_id' => $i->id,
                            'matricula' => $i->matriculaOferta?->matricula,
                            'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                            // Lo ya marcado hoy; null = todavía sin marcar.
                            'estatus' => $delDia->get($i->id)?->estatus,
                            'observacion' => $delDia->get($i->id)?->observacion,
                            'faltas' => (int) $conteo->get('falta', 0),
                            'retardos' => (int) $conteo->get('retardo', 0),
                            'porcentaje' => $total === 0 ? null : (int) round($presentes * 100 / $total),
                        ];
                    })->values(),
            ],
        ];
    }

    /**
     * El LMS de esta materia: sus actividades y quién entregó qué.
     *
     * Se manda la MATRIZ alumnos × actividades ya armada —una fila por alumno,
     * una casilla por actividad— porque es como el docente la mira: recorre a
     * sus alumnos, no sus actividades. Cruzarla en el navegador obligaría a
     * mandar las entregas sueltas y rearmarlas ahí.
     *
     * @param  \Illuminate\Support\Collection<int, Inscripcion>  $inscripciones
     * @return array<string, mixed>
     */
    private function datosLms(AsignaturaGrupo $asignaturaGrupo, $inscripciones): array
    {
        $curso = Curso::query()->where('asignatura_grupo_id', $asignaturaGrupo->id)->first();

        $actividades = $curso === null
            ? collect()
            : Actividad::query()
                ->where('curso_id', $curso->id)
                ->with('componente:id,componente,parcial')
                ->orderBy('orden')->orderBy('id')
                ->get();

        $entregas = $actividades->isEmpty()
            ? collect()
            : Entrega::query()
                ->whereIn('actividad_id', $actividades->pluck('id'))
                ->get()
                ->groupBy('inscripcion_id');

        // Los componentes ponderados a los que el docente puede amarrar una
        // actividad. Son del PLAN, así que existen aunque nadie los use.
        $componentes = EsquemaEvaluacion::query()
            ->where('plan_materia_id', $asignaturaGrupo->plan_materia_id)
            ->orderBy('parcial')->orderBy('orden')
            ->get(['id', 'componente', 'parcial', 'porcentaje']);

        return [
            'curso' => $curso === null ? null : [
                'id' => $curso->id,
                'puede_agregar' => $curso->docente_puede_agregar,
                'puede_ponderar' => $curso->docente_puede_ponderar,
                'de_plantilla' => $curso->plantilla_origen_id !== null,
            ],
            'actividades' => $actividades->map(fn (Actividad $a) => [
                'id' => $a->id,
                'tipo' => $a->tipo->value,
                'tipo_etiqueta' => $a->tipo->etiqueta(),
                'se_entrega' => $a->tipo->seEntrega(),
                'titulo' => $a->titulo,
                'instrucciones' => $a->instrucciones,
                'puntos' => (float) $a->puntos,
                'esquema_evaluacion_id' => $a->esquema_evaluacion_id,
                'componente' => $a->componente === null
                    ? null
                    : "P{$a->componente->parcial} · {$a->componente->componente}",
                'abre_en' => $a->abre_en?->format('Y-m-d\TH:i'),
                'cierra_en' => $a->cierra_en?->format('Y-m-d\TH:i'),
                'permite_tarde' => $a->permite_tarde,
                'publicada' => $a->publicada,
                'entregadas' => ($entregas->flatten()->where('actividad_id', $a->id)
                    ->whereNotNull('entregada_en')->count()),
            ])->values(),
            // Una fila por alumno con su casilla en cada actividad.
            'matriz' => $inscripciones->map(function (Inscripcion $i) use ($actividades, $entregas) {
                $suyas = ($entregas->get($i->id) ?? collect())->keyBy('actividad_id');

                return [
                    'inscripcion_id' => $i->id,
                    'matricula' => $i->matriculaOferta?->matricula,
                    'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                    // Viaja en la propia fila para que el libro de calificaciones
                    // no tenga que cruzarla contra otra lista por matrícula.
                    'situacion' => $i->situacion?->nombre,
                    'de_baja' => $i->situacion?->clave === 'baja',
                    'casillas' => $actividades->map(function (Actividad $a) use ($suyas) {
                        $e = $suyas->get($a->id);

                        return [
                            'actividad_id' => $a->id,
                            'entrega_id' => $e?->id,
                            'estado' => $e?->estado ?? 'sin_entregar',
                            'tarde' => (bool) ($e?->tarde ?? false),
                            'calificacion' => $e?->calificacion === null ? null : (float) $e->calificacion,
                            'retroalimentacion' => $e?->retroalimentacion,
                            'contenido' => $e?->contenido,
                            'entregada_en' => $e?->entregada_en?->format('Y-m-d H:i'),
                        ];
                    })->values(),
                ];
            })->values(),
            'componentes' => $componentes->map(fn (EsquemaEvaluacion $e) => [
                'id' => $e->id,
                'etiqueta' => "Parcial {$e->parcial} · {$e->componente} ({$e->porcentaje}%)",
            ])->values(),
            'tiposActividad' => TipoActividad::paraSelect(),
        ];
    }

    /**
     * Persona del usuario. Sin ella no hay a qué acotar el alcance, así que se
     * cierra en vez de mostrar todo.
     */
    private function personaId(Request $request): int
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        return $usuario->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');
    }

    /**
     * Solo se entra a una materia propia. Se comprueba contra la asignación,
     * no contra el permiso: el permiso dice que puede dar clase, la asignación
     * dice en qué materia.
     */
    private function autorizarMateria(Request $request, AsignaturaGrupo $asignaturaGrupo): int
    {
        $personaId = $this->personaId($request);

        // La relación cuelga de la tabla `docentes` (PK persona_id), no de
        // `personas`: es la columna que hay que calificar.
        $esSuya = $asignaturaGrupo->docentes()
            ->where('docentes.persona_id', $personaId)
            ->exists();

        if (! $esSuya) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }

        return $personaId;
    }

    /**
     * El ciclo que se está viendo.
     *
     * Sin elección, el VIGENTE: «Mis materias» tiene que abrir en lo que el
     * docente da hoy, no en el histórico completo. Con diez ciclos a cuestas,
     * mostrarlos todos obliga a buscar entre materias que terminaron hace años
     * para llegar a la de esta mañana. `?ciclo_id=` sigue sirviendo para
     * consultar uno pasado, y `?ciclo_id=todos` para verlos todos.
     */
    private function cicloSeleccionado(Request $request): ?Ciclo
    {
        $id = $request->query('ciclo_id');

        if ($id === 'todos') {
            return null;
        }

        if ($id !== null) {
            return Ciclo::find($id);
        }

        $hoy = now()->toDateString();

        return Ciclo::query()
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->orderByDesc('fecha_inicio')
            ->first();
    }

    /** El registro de docente del usuario, si lo tiene. */
    public static function docenteDe(?int $personaId): ?Docente
    {
        return $personaId === null ? null : Docente::query()->find($personaId);
    }
}
