<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionInscripcion;
use App\Models\ControlEscolar\TipoEvaluacion;
use App\Services\CiclosCongruentes;
use App\Services\ValidadorInscripcion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inscripción de alumnos a materias.
 *
 * `inscripcion` es el nivel único del sistema: una fila = un alumno (por su
 * matrícula en una oferta) en UNA materia-grupo. Inscribir "a todo el grupo"
 * son N filas; una materia suelta es una; un recursamiento es la misma tabla
 * con `tipo` distinto.
 *
 * Las reglas viven en ValidadorInscripcion y se aplican tanto aquí como en la
 * futura inscripción autogestiva del alumno.
 */
class InscripcionController extends Controller
{
    public function index(Request $request, ValidadorInscripcion $validador): Response
    {
        $matricula = $this->matriculaSeleccionada($request);
        $ciclo = $this->cicloSeleccionado($request);

        return Inertia::render('ControlEscolar/Inscripciones/Index', [
            'alumnos' => MatriculaOferta::query()
                ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.carrera:id,nombre'])
                ->where('estatus', 'activo')
                ->orderBy('matricula')
                ->get()
                ->map(fn (MatriculaOferta $m) => [
                    'id' => $m->id,
                    'etiqueta' => sprintf(
                        '%s · %s (%s)',
                        $m->matricula,
                        $m->persona?->nombreCompleto() ?? '',
                        $m->oferta?->carrera?->nombre ?? 'sin carrera',
                    ),
                ]),
            // Los ciclos se acotan al alumno elegido (su campus y nivel), igual
            // que en el kárdex. Sin alumno todavía, se muestran todos.
            'ciclos' => ($matricula === null
                ? Ciclo::query()->orderByDesc('fecha_inicio')->get(['id', 'clave', 'nombre'])
                : app(CiclosCongruentes::class)->paraAlumno($matricula))
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
            'seleccion' => [
                'matricula_oferta_id' => $matricula?->id,
                'ciclo_id' => $ciclo?->id,
            ],
            'alumno' => $matricula === null ? null : [
                'matricula' => $matricula->matricula,
                'nombre' => $matricula->persona?->nombreCompleto(),
                'carrera' => $matricula->oferta?->carrera?->nombre,
                'plan' => $matricula->oferta?->plan?->nombre,
            ],
            'inscritas' => $this->inscritas($matricula, $ciclo),
            'disponibles' => $this->disponibles($matricula, $ciclo, $validador),
            // Con qué tipo de evaluación se inscribe (ordinaria, extraordinaria,
            // a título, recursamiento, revalidación, regularización).
            'tiposEvaluacion' => TipoEvaluacion::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeInscribir' => $request->user()->can('inscribir-alumnos'),
        ]);
    }

    public function store(Request $request, ValidadorInscripcion $validador): RedirectResponse
    {
        $datos = $request->validate([
            'matricula_oferta_id' => ['required', 'integer', Rule::exists('matricula_oferta', 'id')->whereNull('deleted_at')],
            'asignatura_grupo_id' => ['required', 'integer', Rule::exists('asignatura_grupo', 'id')->whereNull('deleted_at')],
            'tipo_evaluacion_id' => ['required', 'integer', Rule::exists('tipos_evaluacion', 'id')],
        ], [], [
            'matricula_oferta_id' => 'alumno',
            'asignatura_grupo_id' => 'materia',
            'tipo_evaluacion_id' => 'tipo de evaluación',
        ]);

        $matricula = MatriculaOferta::findOrFail($datos['matricula_oferta_id']);
        $materiaGrupo = AsignaturaGrupo::with('grupo')->findOrFail($datos['asignatura_grupo_id']);

        // Se revalida en el servidor aunque la interfaz ya haya filtrado: el
        // estado pudo cambiar entre que se pintó la pantalla y se envió.
        $impedimentos = $validador->impedimentos($matricula, $materiaGrupo);

        if ($impedimentos !== []) {
            throw ValidationException::withMessages([
                'asignatura_grupo_id' => implode(' ', $impedimentos),
            ]);
        }

        // El `tipo` (ordinaria/recursamiento) —que consultan el validador y el
        // asentador— se deriva del tipo de evaluación elegido.
        $claveEval = TipoEvaluacion::query()->whereKey($datos['tipo_evaluacion_id'])->value('clave');
        $tipo = $claveEval === Inscripcion::TIPO_RECURSAMIENTO
            ? Inscripcion::TIPO_RECURSAMIENTO
            : Inscripcion::TIPO_ORDINARIA;

        $this->inscribir($matricula->id, $materiaGrupo, $materiaGrupo->grupo->ciclo_id, $tipo, $datos['tipo_evaluacion_id']);

        return back()->with('exito', 'Alumno inscrito.');
    }

    /**
     * Dar de baja NO borra la inscripción: cambia su situación. El registro de
     * que el alumno estuvo inscrito forma parte de su historia escolar.
     */
    public function baja(Inscripcion $inscripcion): RedirectResponse
    {
        $inscripcion->update([
            'situacion_id' => SituacionInscripcion::query()->where('clave', 'baja')->value('id'),
        ]);

        return back()->with('exito', 'Inscripción dada de baja.');
    }

    /**
     * Inscripción masiva a un grupo: se ven los grupos del ciclo y, por grupo,
     * se sugiere a los alumnos de su plan que van en el grado del grupo, están
     * activos y aún no tienen inscripción en ese ciclo. Un buscador permite
     * añadir a cualquier otro alumno activo del plan.
     */
    public function masiva(Request $request): Response
    {
        $ciclo = $this->cicloSeleccionado($request);
        // Alcance por campus del rol: solo grupos del/los campus del usuario.
        $campusVisibles = $request->user()->campusDelRolActivo();

        $grupo = $request->query('grupo_id') === null
            ? null
            : Grupo::query()
                ->with(['plan:id,nombre', 'ciclo:id,clave', 'campus:id,nombre', 'turno:id,nombre', 'asignaturas.planMateria.asignatura:id,nombre', 'asignaturas.planMateria.plan:id,nombre,carrera_id', 'asignaturas.planMateria.plan.carrera:id,nombre'])
                ->when($campusVisibles !== [], fn ($q) => $q->whereIn('campus_id', $campusVisibles))
                ->find($request->query('grupo_id'));

        return Inertia::render('ControlEscolar/Inscripciones/Masiva', [
            'ciclos' => Ciclo::query()->orderByDesc('fecha_inicio')->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
            'grupos' => $ciclo === null ? [] : Grupo::query()->with('plan:id,nombre')
                ->where('ciclo_id', $ciclo->id)
                ->when($campusVisibles !== [], fn ($q) => $q->whereIn('campus_id', $campusVisibles))
                ->orderBy('clave')->get()
                ->map(fn (Grupo $g) => ['id' => $g->id, 'etiqueta' => trim($g->clave.' · '.($g->plan?->nombre ?? 'sin plan'))]),
            'seleccion' => ['ciclo_id' => $ciclo?->id, 'grupo_id' => $grupo?->id],
            'grupo' => $grupo === null ? null : $this->datosGrupoMasiva($grupo),
            'candidatos' => $grupo === null ? [] : $this->candidatosMasiva($grupo),
            'inscritos' => $grupo === null ? [] : $this->inscritosDelGrupo($grupo),
            // Para inscribir a UNO en UNA materia (recursamiento, extraordinario):
            // el caso puntual que antes vivía en el detalle del grupo y que ahora
            // está donde están los alumnos.
            'tiposEvaluacion' => TipoEvaluacion::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeInscribir' => $request->user()->can('inscribir-alumnos'),
        ]);
    }

    /**
     * Inscribe a los alumnos seleccionados en TODAS las materias del grupo. Cada
     * materia se valida por separado: la que no pase (seriación, cupo, ya
     * inscrito) se omite, no truena la carga completa.
     */
    public function inscribirMasiva(Request $request, ValidadorInscripcion $validador): RedirectResponse
    {
        $datos = $request->validate([
            'grupo_id' => ['required', 'integer', Rule::exists('grupos', 'id')->whereNull('deleted_at')],
            'matricula_oferta_ids' => ['required', 'array', 'min:1'],
            'matricula_oferta_ids.*' => ['integer', Rule::exists('matricula_oferta', 'id')->whereNull('deleted_at')],
        ], [], ['matricula_oferta_ids' => 'alumnos']);

        $grupo = Grupo::with('asignaturas')->findOrFail($datos['grupo_id']);

        // El alcance por campus se valida en el servidor, no solo escondiendo
        // grupos del desplegable: el POST llega igual con cualquier grupo_id.
        $campusVisibles = $request->user()->campusDelRolActivo();

        if ($campusVisibles !== [] && ! in_array($grupo->campus_id, $campusVisibles, true)) {
            abort(403, 'Ese grupo no pertenece a un campus que administres.');
        }

        if ($grupo->asignaturas->isEmpty()) {
            return back()->with('error', 'El grupo no tiene materias abiertas.');
        }


        $ordinaria = TipoEvaluacion::query()->where('clave', Inscripcion::TIPO_ORDINARIA)->value('id');
        $inscritoId = SituacionInscripcion::query()->where('clave', 'inscrito')->value('id');

        $renglones = 0;
        // Alumnos que quedaron con al menos una materia sin abrir: son los que
        // hay que reportar por nombre, porque cada uno es un pendiente concreto.
        $incompletos = [];

        DB::transaction(function () use ($datos, $grupo, $validador, $ordinaria, $inscritoId, &$renglones, &$incompletos) {
            foreach ($datos['matricula_oferta_ids'] as $matriculaId) {
                $matricula = MatriculaOferta::with('persona')->find($matriculaId);

                if ($matricula === null) {
                    continue;
                }

                $rebotadas = 0;

                foreach ($grupo->asignaturas as $materiaGrupo) {
                    if ($validador->impedimentos($matricula, $materiaGrupo) !== []) {
                        $rebotadas++;

                        continue;
                    }

                    $this->inscribir($matricula->id, $materiaGrupo, $grupo->ciclo_id, Inscripcion::TIPO_ORDINARIA, $ordinaria);
                    $renglones++;
                }

                if ($rebotadas > 0) {
                    $incompletos[] = $matricula->persona?->nombreCompleto() ?? $matricula->matricula;
                }
            }
        });

        // El aviso se da en alumnos y materias, no en «renglones»: quien opera
        // esta pantalla piensa en personas, y «12 renglones» no dice si alguien
        // se quedó fuera.
        $alumnos = count($datos['matricula_oferta_ids']);
        $mensaje = "{$alumnos} alumno(s) inscritos en {$renglones} materia(s).";

        if ($incompletos !== []) {
            $lista = implode(', ', array_slice($incompletos, 0, 3));
            $resto = count($incompletos) - 3;
            $mensaje .= " Quedaron incompletos {$lista}".($resto > 0 ? " y {$resto} más" : '').
                ': las materias que faltan son de otro plan, tienen seriación pendiente o el grupo llegó a su cupo.';
        }

        return back()->with($renglones > 0 ? 'exito' : 'error', $renglones > 0
            ? $mensaje
            : 'No se inscribió ninguna materia: todas rebotaron por validación (otro plan de estudios, seriación, cupo o ya inscrito).');
    }

    /**
     * Inscribe a un alumno en una materia-grupo, REACTIVANDO su renglón si ya
     * existía dado de baja.
     *
     * `inscripcion` tiene un UNIQUE sobre (matricula_oferta, asignatura_grupo):
     * un alumno ocupa un lugar en una materia, y la baja no borra el renglón,
     * le cambia la situación. Insertar uno nuevo reventaba con una violación de
     * llave —un 500 crudo en la cara del usuario— así que quien vuelve a la
     * materia recupera SU renglón. La historia de la baja no se pierde: vive en
     * la auditoría, no en una fila muerta que además bloquea el regreso.
     */
    private function inscribir(int $matriculaId, AsignaturaGrupo $materiaGrupo, int $cicloId, string $tipo, ?int $tipoEvaluacionId): void
    {
        Inscripcion::updateOrCreate(
            [
                'matricula_oferta_id' => $matriculaId,
                'asignatura_grupo_id' => $materiaGrupo->id,
            ],
            [
                'ciclo_id' => $cicloId,
                'tipo' => $tipo,
                'tipo_evaluacion_id' => $tipoEvaluacionId,
                'forma_inscripcion' => Inscripcion::FORMA_ADMINISTRATIVA,
                'situacion_id' => SituacionInscripcion::query()->where('clave', 'inscrito')->value('id'),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function datosGrupoMasiva(Grupo $grupo): array
    {
        return [
            'id' => $grupo->id,
            'clave' => $grupo->clave,
            'plan' => $grupo->plan?->nombre,
            'ciclo' => $grupo->ciclo?->clave,
            'ciclo_id' => $grupo->ciclo_id,
            'campus' => $grupo->campus?->nombre,
            'turno' => $grupo->turno?->nombre,
            'cupo' => $grupo->cupo,
            // El grado lo declara el grupo, no se deduce de sus materias.
            'periodo_objetivo' => $grupo->semestre,
            // De qué planes tienen que ser los alumnos para que sus materias no
            // reboten. Con plan fijo es uno; sin plan fijo, los de las materias
            // abiertas. Se muestra para que la lista vacía se explique sola.
            //
            // Lleva la CARRERA por delante porque el nombre del plan solo dice
            // el año («Plan 2022») y hay uno por carrera: sin la carrera, decir
            // «ningún alumno de Plan 2022» nombra a diez planes distintos.
            'planes_admitidos' => $grupo->asignaturas
                ->map(fn (AsignaturaGrupo $ag) => $ag->planMateria?->plan === null
                    ? null
                    : trim(($ag->planMateria->plan->carrera?->nombre ?? '').' · '.$ag->planMateria->plan->nombre, ' ·'))
                ->filter()->unique()->values()->all(),
            // El id es el de `asignatura_grupo`, no el de la materia del plan:
            // es a lo que se inscribe alguien puntualmente.
            'materias' => $grupo->asignaturas->map(fn (AsignaturaGrupo $ag) => [
                'id' => $ag->id,
                'clave_en_plan' => $ag->planMateria?->clave_en_plan,
                'nombre' => $ag->planMateria?->asignatura?->nombre,
                'periodo' => $ag->planMateria?->periodo,
            ])->values()->all(),
        ];
    }

    /**
     * Quién YA está en el grupo, con en cuántas de sus materias.
     *
     * Es la mitad que faltaba de esta pantalla: al inscribir masivamente, el
     * alumno desaparecía de la lista de candidatos y no aparecía en ningún
     * lado, así que no había forma de confirmar desde aquí que la carga hubiera
     * surtido efecto.
     *
     * La distinción entre completo y parcial importa: la inscripción masiva mete
     * al alumno en TODAS las materias, pero una materia puede rebotar por
     * seriación o cupo. Un alumno en 4 de 6 materias no es un caso raro, es un
     * pendiente, y se resuelve volviéndolo a seleccionar (las que ya tiene se
     * omiten solas).
     *
     * @return array<int, array<string, mixed>>
     */
    private function inscritosDelGrupo(Grupo $grupo): array
    {
        $materiaIds = $grupo->asignaturas->pluck('id');

        if ($materiaIds->isEmpty()) {
            return [];
        }

        // Las bajas no cuentan: `bajarAlumno` no borra la inscripción, le pone
        // situación «baja». Sin esta exclusión el alumno seguiría apareciendo
        // en el grupo después de darlo de baja.
        $vigentes = Inscripcion::query()
            ->with('tipoEvaluacion:id,nombre')
            ->whereIn('asignatura_grupo_id', $materiaIds)
            ->whereNot(fn ($q) => $q->whereHas('situacion', fn ($s) => $s->where('clave', 'baja')))
            ->get(['id', 'matricula_oferta_id', 'asignatura_grupo_id', 'tipo_evaluacion_id'])
            ->groupBy('matricula_oferta_id');

        if ($vigentes->isEmpty()) {
            return [];
        }

        $total = $materiaIds->count();

        // Nombre corto de cada materia del grupo, para el desglose por alumno.
        $nombreMateria = $grupo->asignaturas->mapWithKeys(fn (AsignaturaGrupo $ag) => [
            $ag->id => trim(($ag->planMateria?->clave_en_plan ?? '').' · '.($ag->planMateria?->asignatura?->nombre ?? '')),
        ]);

        return MatriculaOferta::query()
            ->with(['persona', 'oferta.carrera:id,nombre'])
            ->whereIn('id', $vigentes->keys())
            ->orderBy('matricula')
            ->get()
            ->map(function (MatriculaOferta $m) use ($vigentes, $total, $nombreMateria) {
                $suyas = $vigentes[$m->id];

                return [
                    'id' => $m->id,
                    'matricula' => $m->matricula,
                    'nombre' => $m->persona?->nombreCompleto(),
                    'carrera' => $m->oferta?->carrera?->nombre,
                    'foto' => $m->persona?->urlFoto(),
                    'materias' => $suyas->count(),
                    'total_materias' => $total,
                    'completo' => $suyas->count() === $total,
                    // Desglose: en qué materias está y con qué tipo de
                    // evaluación. Es lo que permite darlo de baja de UNA sin
                    // sacarlo del grupo entero.
                    'detalle' => $suyas->map(fn (Inscripcion $i) => [
                        'inscripcion_id' => $i->id,
                        'asignatura_grupo_id' => $i->asignatura_grupo_id,
                        'materia' => $nombreMateria[$i->asignatura_grupo_id] ?? null,
                        'tipo_evaluacion' => $i->tipoEvaluacion?->nombre,
                    ])->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * Alumnos activos que podrían entrar al grupo.
     *
     * Con plan fijo son los de ese plan. Sin plan fijo son los de los planes a
     * los que pertenecen las materias YA ABIERTAS del grupo, que es lo único
     * que se les puede dar: el validador rechaza «la materia pertenece a otro
     * plan de estudios», así que ofrecer a alguien fuera de esos planes es
     * ofrecer una carga que va a rebotar entera. Antes se filtraba por
     * `plan_id` del grupo a secas y un grupo sin plan no ofrecía a nadie.
     *
     * Si el grupo todavía no tiene materias no hay plan del cual deducir, y se
     * cae al NIVEL: es una lista orientativa, pero tampoco hay dónde inscribir.
     *
     * El campus NO filtra aquí, marca: cada candidato viaja con `mismo_campus`
     * y la pantalla muestra por omisión solo los del campus del grupo. Un grupo
     * está físicamente en un campus, así que ese es el caso normal; pero el
     * alumno que cursa en otro campus existe (movilidad, materias compartidas) y
     * esconderlo sin decirlo dejaría un caso legítimo sin salida en la UI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidatosMasiva(Grupo $grupo): array
    {
        $objetivo = $grupo->semestre;

        $planesQueSirven = $grupo->plan_id !== null
            ? [$grupo->plan_id]
            : $grupo->asignaturas->map(fn (AsignaturaGrupo $ag) => $ag->planMateria?->plan_id)
                ->filter()->unique()->values()->all();

        // Quien ya tiene grupo en este ciclo no es candidato: se inscribe una vez.
        // Los de ESTE grupo salen aparte, en `inscritosDelGrupo()`.
        //
        // Las bajas se ignoran a propósito: dar de baja a alguien de un grupo es
        // justo lo que se hace antes de cambiarlo a otro, así que si contaran
        // quedaría fuera de la lista para siempre y el cambio de grupo sería
        // imposible desde esta pantalla.
        $yaEnCiclo = Inscripcion::query()
            ->where('ciclo_id', $grupo->ciclo_id)
            ->whereNot(fn ($q) => $q->whereHas('situacion', fn ($s) => $s->where('clave', 'baja')))
            ->distinct()
            ->pluck('matricula_oferta_id');

        return MatriculaOferta::query()
            ->with(['persona', 'oferta.carrera:id,nombre', 'oferta.campus:id,nombre'])
            ->where('estatus', 'activo')
            ->whereHas('oferta', fn ($q) => $planesQueSirven !== []
                ? $q->whereIn('plan_id', $planesQueSirven)
                : $q->whereHas('carrera', fn ($c) => $c->where('nivel_estudios_id', $grupo->nivel_estudios_id)))
            ->whereNotIn('id', $yaEnCiclo)
            ->orderBy('matricula')
            ->get()
            ->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre' => $m->persona?->nombreCompleto(),
                'carrera' => $m->oferta?->carrera?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
                'mismo_campus' => $m->oferta?->campus_id === $grupo->campus_id,
                'periodo_actual' => $m->periodo_actual,
                'foto' => $m->persona?->urlFoto(),
                'sugerido' => $objetivo !== null && $m->periodo_actual === $objetivo,
            ])
            ->all();
    }

    private function matriculaSeleccionada(Request $request): ?MatriculaOferta
    {
        $id = $request->query('matricula_oferta_id');

        return $id === null
            ? null
            : MatriculaOferta::with(['persona', 'oferta.carrera', 'oferta.plan'])->find($id);
    }

    private function cicloSeleccionado(Request $request): ?Ciclo
    {
        $id = $request->query('ciclo_id');

        return $id === null ? null : Ciclo::find($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inscritas(?MatriculaOferta $matricula, ?Ciclo $ciclo): array
    {
        if ($matricula === null || $ciclo === null) {
            return [];
        }

        return Inscripcion::query()
            ->with(['asignaturaGrupo.planMateria.asignatura:id,nombre', 'asignaturaGrupo.grupo:id,clave', 'situacion:id,nombre', 'tipoEvaluacion:id,nombre'])
            ->where('matricula_oferta_id', $matricula->id)
            ->where('ciclo_id', $ciclo->id)
            ->get()
            ->map(fn (Inscripcion $inscripcion) => [
                'id' => $inscripcion->id,
                'materia' => $inscripcion->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                'clave_en_plan' => $inscripcion->asignaturaGrupo?->planMateria?->clave_en_plan,
                'grupo' => $inscripcion->asignaturaGrupo?->grupo?->clave,
                'tipo' => $inscripcion->tipo,
                'tipo_evaluacion' => $inscripcion->tipoEvaluacion?->nombre,
                'situacion' => $inscripcion->situacion?->nombre,
                'calificacion_final' => $inscripcion->calificacion_final,
            ])
            ->all();
    }

    /**
     * Materias abiertas del ciclo con el veredicto de cada una: o se puede
     * inscribir, o se explica exactamente por qué no.
     *
     * @return array<int, array<string, mixed>>
     */
    private function disponibles(?MatriculaOferta $matricula, ?Ciclo $ciclo, ValidadorInscripcion $validador): array
    {
        if ($matricula === null || $ciclo === null) {
            return [];
        }

        return AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'grupo:id,clave,ciclo_id,cupo',
                'grupo.ciclo',
                'horarios',
                'docentes.persona',
            ])
            ->whereHas('grupo', fn ($q) => $q->where('ciclo_id', $ciclo->id))
            ->get()
            ->map(function (AsignaturaGrupo $materiaGrupo) use ($matricula, $validador) {
                $impedimentos = $validador->impedimentos($matricula, $materiaGrupo);
                $titular = $materiaGrupo->docentes->firstWhere('pivot.tipo', 'titular');

                return [
                    'id' => $materiaGrupo->id,
                    'materia' => $materiaGrupo->planMateria?->asignatura?->nombre,
                    'clave_en_plan' => $materiaGrupo->planMateria?->clave_en_plan,
                    'periodo' => $materiaGrupo->planMateria?->periodo,
                    'grupo' => $materiaGrupo->grupo?->clave,
                    'titular' => $titular?->persona?->nombreCompleto(),
                    'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $materiaGrupo->id)->count(),
                    'cupo' => $materiaGrupo->grupo?->cupo,
                    'impedimentos' => $impedimentos,
                    'inscribible' => $impedimentos === [],
                ];
            })
            ->sortBy('periodo')
            ->values()
            ->all();
    }
}
