<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Http\Controllers\Concerns\EligeRubrica;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Conversacion;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Services\CalendarioCaptura;
use App\Services\Lms\SalaDeMateria;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    use EligeRubrica;

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
            ->get();

        /*
         * Lo que reclama trabajo en cada materia, resuelto en bloque.
         *
         * Sin esto, el listado dice qué materias tiene —cosa que el docente ya
         * sabe— pero no a cuál conviene entrar hoy. Estas tres cifras son las
         * que contestan eso: qué falta calificar, quién escribió y si ya se
         * pasó lista.
         */
        $pendientes = $this->pendientesPorMateria($materias->pluck('id'), $personaId);

        $materias = $materias
            ->map(fn (AsignaturaGrupo $ag) => [
                ...($pendientes[$ag->id] ?? ['por_calificar' => 0, 'sin_leer' => 0, 'lista_hoy' => false]),
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
            // Lo que tiene trabajo esperando, primero: con ocho materias, la que
            // reclama atención no puede quedar en el sexto lugar por orden
            // alfabético.
            ->sortByDesc(fn (array $m) => $m['por_calificar'] + $m['sin_leer'])
            ->values()
            ->all();

        return Inertia::render('Docencia/Index', [
            'materias' => $materias,
            // Sólo los vigentes: un docente no captura ni consulta lo de 2016
            // desde aquí, y el histórico completo entierra lo de este semestre.
            'ciclos' => Ciclo::query()
                ->vigentes($ciclo?->id)
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
            // Las columnas de la escala van explícitas: sin ellas el plan llega
            // a medias y `datosLms` cae a los valores por defecto sin avisar,
            // que es justo el 0–10 que este cambio vino a quitar.
            'planMateria.plan:id,nombre,calificacion_minima,calificacion_maxima,calificacion_minima_aprobatoria,decimales_calificacion,redondeo_calificacion',
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
            ...$this->datosLms($asignaturaGrupo, $inscripciones, $personaId),
            ...$this->datosMensajes($asignaturaGrupo, $personaId),
            ...$this->datosAsistencia($request, $asignaturaGrupo, $inscripciones),
        ]);
    }

    /**
     * Lo ya registrado, en rejilla: una columna por sesión del mes.
     *
     * El pase de lista responde «¿quién vino hoy?»; esto responde «¿cómo ha ido
     * el mes?», que es otra pregunta y hasta ahora obligaba a abrir el día por
     * día para reconstruirla.
     *
     * ── Por qué se recorta por mes ─────────────────────────────────────────
     * Un semestre son sesenta o setenta sesiones: en una sola rejilla no cabe a
     * lo ancho ni se lee. El mes es la unidad natural —es como se reportan las
     * faltas— y además deja navegar un curso que cruza el año, de noviembre a
     * febrero, sin tratar cada año como un mundo aparte.
     *
     * @param  Collection<int, int>  $ids
     * @return array<string, mixed>
     */
    private function rejillaDeAsistencia(Request $request, $ids): array
    {
        // Los meses que de verdad tienen algo registrado. Se ofrecen esos y no
        // los doce del año: un selector con meses vacíos invita a buscar donde
        // no hay nada.
        $meses = AsistenciaClase::query()
            ->whereIn('inscripcion_id', $ids)
            ->selectRaw("DATE_FORMAT(fecha, '%Y-%m') AS mes")
            ->distinct()
            ->orderByDesc('mes')
            ->pluck('mes')
            ->values();

        if ($meses->isEmpty()) {
            return ['meses' => [], 'mes' => null, 'sesionesDelMes' => [], 'rejilla' => []];
        }

        // Sin elección, el mes más reciente con registros: es el que se está
        // llevando.
        $mes = $request->query('mes');
        $mes = $meses->contains($mes) ? $mes : $meses->first();

        $registros = AsistenciaClase::query()
            ->whereIn('inscripcion_id', $ids)
            ->whereRaw("DATE_FORMAT(fecha, '%Y-%m') = ?", [$mes])
            ->orderBy('fecha')
            ->get(['inscripcion_id', 'fecha', 'modalidad', 'estatus', 'observacion']);

        // Una columna por sesión: día y modalidad, porque una teórico-práctica
        // pasa lista dos veces el mismo día y son dos columnas distintas.
        $sesiones = $registros
            ->map(fn ($r) => ['fecha' => $r->fecha->format('Y-m-d'), 'modalidad' => $r->modalidad])
            ->unique(fn (array $s) => $s['fecha'].$s['modalidad'])
            ->sortBy(fn (array $s) => $s['fecha'].$s['modalidad'])
            ->values();

        $porAlumno = $registros->groupBy('inscripcion_id');

        return [
            'meses' => $meses->all(),
            'mes' => $mes,
            'sesionesDelMes' => $sesiones->map(fn (array $s) => [
                'clave' => $s['fecha'].'|'.$s['modalidad'],
                'fecha' => $s['fecha'],
                'dia' => (int) substr($s['fecha'], 8, 2),
                'modalidad' => $s['modalidad'],
            ])->all(),
            // inscripcion_id => { clave de sesión => estatus }
            'rejilla' => $porAlumno->map(fn ($suyos) => $suyos->mapWithKeys(fn ($r) => [
                $r->fecha->format('Y-m-d').'|'.$r->modalidad => [
                    'estatus' => $r->estatus,
                    'observacion' => $r->observacion,
                ],
            ])->all())->all(),
        ];
    }

    /**
     * El chat directo con cada alumno, para abrirlo desde la rejilla.
     *
     * Con qué conversación y cuántos mensajes sin leer, por persona. Se resuelve
     * en UNA consulta y no una por alumno: con cuarenta inscritos, contar de a
     * uno son cuarenta viajes a la base cada vez que se pinta el libro.
     *
     * Lo que no existe todavía no se crea aquí: abrir la pantalla de un grupo no
     * debería dejar cuarenta conversaciones vacías. La fila se crea la primera
     * vez que alguien escribe.
     *
     * @return array<string, mixed>
     */
    private function datosMensajes(AsignaturaGrupo $asignaturaGrupo, int $personaId): array
    {
        $directas = Conversacion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->where('tipo', Conversacion::DIRECTA)
            ->where(fn ($q) => $q->where('persona_a_id', $personaId)->orWhere('persona_b_id', $personaId))
            ->get();

        $sinLeer = app(SalaDeMateria::class)->sinLeer($directas, $personaId);

        return [
            'mensajes' => $directas->mapWithKeys(fn (Conversacion $c) => [
                (int) $c->contraparte($personaId) => [
                    'conversacion_id' => $c->id,
                    'sin_leer' => $sinLeer[$c->id] ?? 0,
                ],
            ])->all(),
        ];
    }

    /**
     * El pase de lista: lo ya registrado de la fecha que se está viendo y el
     * resumen de cada alumno.
     *
     * La fecha llega por la URL para que recargar no pierda el día que se
     * estaba pasando, y por omisión es hoy —que es cuando se pasa lista—.
     *
     * @param  Collection<int, Inscripcion>  $inscripciones
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
                ...$this->rejillaDeAsistencia($request, $ids),
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
     * @param  Collection<int, Inscripcion>  $inscripciones
     * @return array<string, mixed>
     */
    private function datosLms(AsignaturaGrupo $asignaturaGrupo, $inscripciones, int $personaId): array
    {
        $curso = Curso::query()->where('asignatura_grupo_id', $asignaturaGrupo->id)->first();

        $actividades = $curso === null
            ? collect()
            : Actividad::query()
                ->where('curso_id', $curso->id)
                ->with(['componente:id,componente,parcial', 'rubrica.criterios.niveles'])
                ->orderBy('orden')->orderBy('id')
                ->get();

        $entregas = $actividades->isEmpty()
            ? collect()
            : Entrega::query()
                // Los adjuntos viajan con la entrega: el docente califica lo que
                // el alumno subió, y sin ellos el panel mostraba un cuadro vacío
                // en toda entrega que fuera sólo un archivo.
                // Y el desglose de la rúbrica, por lo mismo: el panel lo pinta
                // al abrir la entrega y pedirlo aparte sería una consulta por
                // alumno.
                ->with(['archivos', 'porRubrica'])
                ->whereIn('actividad_id', $actividades->pluck('id'))
                ->get()
                ->groupBy('inscripcion_id');

        // Los componentes ponderados a los que el docente puede amarrar una
        // actividad. Son del PLAN, así que existen aunque nadie los use.
        $componentes = EsquemaEvaluacion::query()
            ->where('plan_materia_id', $asignaturaGrupo->plan_materia_id)
            ->orderBy('parcial')->orderBy('orden')
            ->get(['id', 'componente', 'parcial', 'porcentaje']);

        // Con qué escala califica esta materia. Viaja al frontend porque los
        // colores de la rejilla la necesitan: con umbrales fijos en 8 y 6, una
        // escuela que califica sobre 100 veía todo en rojo.
        $plan = $asignaturaGrupo->planMateria?->plan;

        return [
            'escala' => [
                'minima' => (float) ($plan?->calificacion_minima ?? 0),
                'maxima' => (float) ($plan?->calificacion_maxima ?? 10),
                'aprobatoria' => (float) ($plan?->calificacion_minima_aprobatoria ?? 6),
            ],
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
                'contenido' => $a->contenido,
                'tiene_contenido' => $a->tieneContenido(),
                'puntos' => (float) $a->puntos,
                'esquema_evaluacion_id' => $a->esquema_evaluacion_id,
                'rubrica_id' => $a->rubrica_id,
                // La rúbrica ENTERA y no sólo su nombre: el panel de
                // calificación la necesita para pintar los niveles, y pedirla
                // aparte al abrir cada entrega sería una petición por alumno.
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
                // Legible, igual que en el aula del alumno: `examen_p1` es la
                // clave con la que control escolar armó el esquema, no un
                // nombre que leerle a nadie.
                'componente' => $a->componente === null
                    ? null
                    : "P{$a->componente->parcial} · ".$a->componente->nombreLegible(),
                'abre_en' => $a->abre_en?->format('Y-m-d\TH:i'),
                'cierra_en' => $a->cierra_en?->format('Y-m-d\TH:i'),
                'permite_tarde' => $a->permite_tarde,
                'permite_reentrega' => $a->permite_reentrega,
                'publicada' => $a->publicada,
                'entregadas' => ($entregas->flatten()->where('actividad_id', $a->id)
                    ->whereNotNull('entregada_en')->count()),
            ])->values(),
            // Una fila por alumno con su casilla en cada actividad.
            'matriz' => $inscripciones->map(function (Inscripcion $i) use ($actividades, $entregas) {
                $suyas = ($entregas->get($i->id) ?? collect())->keyBy('actividad_id');

                return [
                    'inscripcion_id' => $i->id,
                    // Con quién se abre el chat directo desde la propia rejilla.
                    'persona_id' => $i->matriculaOferta?->persona_id,
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
                            /*
                             * La puso la máquina, no una persona.
                             *
                             * Un examen de opción múltiple se califica solo, y
                             * el docente tiene que poder distinguirlo de lo que
                             * él revisó: son dos cosas distintas cuando un
                             * alumno viene a reclamar una nota.
                             */
                            'automatica' => $e?->calificacion !== null && $e?->calificada_por === null,
                            /*
                             * Lo evaluado por criterio, cuando la actividad va
                             * con rúbrica. Es lo que hace que reabrir una
                             * entrega ya calificada muestre los niveles
                             * elegidos en vez de la rúbrica en blanco —y que
                             * corregir un solo criterio no obligue a volver a
                             * elegirlos todos—.
                             */
                            'por_rubrica' => ($e?->porRubrica ?? collect())->map(fn ($r) => [
                                'criterio_id' => (int) $r->criterio_id,
                                'nivel_id' => $r->nivel_id === null ? null : (int) $r->nivel_id,
                                'puntos' => (float) $r->puntos,
                                'comentario' => $r->comentario,
                            ])->values(),
                            'archivos' => ($e?->archivos ?? collect())->map(fn ($f) => [
                                'id' => $f->id,
                                'nombre' => $f->nombre,
                                'bytes' => (int) $f->bytes,
                            ])->values(),
                        ];
                    })->values(),
                ];
            })->values(),
            'componentes' => $componentes->map(fn (EsquemaEvaluacion $e) => [
                'id' => $e->id,
                'etiqueta' => "Parcial {$e->parcial} · {$e->componente} ({$e->porcentaje}%)",
            ])->values(),
            // Las de la escuela y las suyas: aquí sí, porque es su materia y su
            // grupo. En la plantilla del plan sólo caben las de la escuela.
            'rubricas' => $this->rubricasDisponibles($personaId, soloDeLaEscuela: false),
            'tiposActividad' => TipoActividad::paraSelect(),
            // Las clases en línea las arma su propio controlador: mismo dato en
            // dos sitios acaba divergiendo, y aquí lo que se le enseña al
            // docente incluye un enlace que al alumno no se le puede dar.
            'clasesEnLinea' => app(ClaseEnVivoController::class)->datosPara($asignaturaGrupo),
        ];
    }

    /**
     * Lo que espera trabajo en cada materia: por calificar, sin leer, y si ya
     * se pasó lista hoy.
     *
     * Tres consultas agrupadas para TODAS las materias, no tres por materia:
     * un docente con ocho grupos haría veinticuatro viajes a la base cada vez
     * que abre su listado.
     *
     * @param  Collection<int, int>  $materiaIds
     * @return array<int, array<string, mixed>>
     */
    private function pendientesPorMateria($materiaIds, int $personaId): array
    {
        if ($materiaIds->isEmpty()) {
            return [];
        }

        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $materiaIds)
            ->pluck('asignatura_grupo_id', 'id');

        // Entregas que llegaron y nadie ha revisado.
        $porCalificar = $cursos->isEmpty() ? collect() : Entrega::query()
            ->selectRaw('actividades.curso_id, count(*) as total')
            ->join('actividades', 'actividades.id', '=', 'entregas.actividad_id')
            ->whereIn('actividades.curso_id', $cursos->keys())
            ->whereNull('entregas.deleted_at')
            ->whereNull('actividades.deleted_at')
            ->whereNotNull('entregas.entregada_en')
            ->whereNull('entregas.calificacion')
            ->groupBy('actividades.curso_id')
            ->pluck('total', 'curso_id')
            ->mapWithKeys(fn ($total, $cursoId) => [(int) $cursos->get($cursoId) => (int) $total]);

        // Si ya se pasó lista HOY: es la pregunta de todas las mañanas.
        $listaHoy = AsistenciaClase::query()
            ->join('inscripcion', 'inscripcion.id', '=', 'asistencia_clase.inscripcion_id')
            ->whereIn('inscripcion.asignatura_grupo_id', $materiaIds)
            ->whereDate('asistencia_clase.fecha', now()->toDateString())
            ->distinct()
            ->pluck('inscripcion.asignatura_grupo_id')
            ->flip();

        $sinLeer = $this->sinLeerPorMateria($materiaIds, $personaId);

        $resultado = [];

        foreach ($materiaIds as $id) {
            $resultado[$id] = [
                'por_calificar' => (int) ($porCalificar[$id] ?? 0),
                'sin_leer' => (int) ($sinLeer[$id] ?? 0),
                'lista_hoy' => $listaHoy->has($id),
            ];
        }

        return $resultado;
    }

    /**
     * Mensajes que le escribieron y no ha leído, por materia.
     *
     * @param  Collection<int, int>  $materiaIds
     * @return array<int, int>
     */
    private function sinLeerPorMateria($materiaIds, int $personaId): array
    {
        $conversaciones = Conversacion::query()
            ->whereIn('asignatura_grupo_id', $materiaIds)
            ->where(fn ($q) => $q->where('tipo', Conversacion::GRUPO)
                ->orWhere('persona_a_id', $personaId)
                ->orWhere('persona_b_id', $personaId))
            ->get();

        if ($conversaciones->isEmpty()) {
            return [];
        }

        $sinLeer = app(SalaDeMateria::class)->sinLeer($conversaciones, $personaId);

        $porMateria = [];

        foreach ($conversaciones as $conversacion) {
            $materia = (int) $conversacion->asignatura_grupo_id;
            $porMateria[$materia] = ($porMateria[$materia] ?? 0) + ($sinLeer[$conversacion->id] ?? 0);
        }

        return $porMateria;
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

        return Ciclo::enCurso();
    }

    /** El registro de docente del usuario, si lo tiene. */
    public static function docenteDe(?int $personaId): ?Docente
    {
        return $personaId === null ? null : Docente::query()->find($personaId);
    }
}
