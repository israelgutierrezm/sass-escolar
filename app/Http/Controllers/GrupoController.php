<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
// El nivel del grupo tiene que hablar el MISMO catálogo que `carreras.
// nivel_estudios_id`, que es el del tenant (los niveles que esta escuela
// oferta). El homónimo de Landlord son los niveles estandarizados de la SEP y
// usarlo aquí produce ids que no cruzan con ninguna carrera.
use App\Models\Academico\PlanMateria;
use App\Models\Academico\Turno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionCiclo;
use App\Models\ControlEscolar\SituacionGrupo;
use App\Models\ControlEscolar\SituacionInscripcion;
use App\Models\ControlEscolar\TipoEvaluacion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Grupos: el contenedor de materias dentro de un ciclo.
 *
 * El grupo no es "un grado escolar": es un conjunto de materias abiertas que
 * comparten ciclo, campus y —normalmente— turno. Un mismo grupo puede alojar
 * materias de planes distintos, que es lo que permite el tronco común.
 */
class GrupoController extends Controller
{
    public function index(Request $request): Response
    {
        // Antes devolvía TODOS los grupos sin filtro ni paginación. Con dos o
        // tres ciclos sembrados aún se leía; una escuela con años de historia
        // abre esta pantalla y recibe miles de filas donde busca una. El filtro
        // por ciclo es el que de verdad se usa a diario.
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'ciclo_id' => $request->query('ciclo_id'),
            'campus_id' => $request->query('campus_id'),
            'plan_id' => $request->query('plan_id'),
            'turno_id' => $request->query('turno_id'),
            'situacion_id' => $request->query('situacion_id'),
        ];

        // Alcance por campus del rol: un administrativo de un solo campus solo ve
        // los grupos de ese campus. Vacío = global.
        $campusVisibles = $request->user()->campusDelRolActivo();

        $grupos = Grupo::query()
            ->with(['ciclo:id,clave,nombre', 'campus:id,nombre', 'plan:id,nombre', 'turno:id,nombre', 'situacion:id,nombre'])
            ->withCount('asignaturas')
            // Alumnos DISTINTOS en el grupo, no renglones de inscripción. El
            // criterio vive en el modelo porque lo comparten esta pantalla y la
            // tarjeta de ocupación del panel; ver `Grupo::scopeConAlumnos`.
            ->conAlumnos()
            ->when($campusVisibles !== [], fn ($q) => $q->whereIn('campus_id', $campusVisibles))
            ->when($filtros['busqueda'] !== '', function ($query) use ($filtros) {
                $termino = "%{$filtros['busqueda']}%";

                $query->where(fn ($q) => $q->where('clave', 'like', $termino)->orWhere('nombre', 'like', $termino));
            })
            ->when($filtros['ciclo_id'], fn ($q, $v) => $q->where('ciclo_id', $v))
            ->when($filtros['campus_id'], fn ($q, $v) => $q->where('campus_id', $v))
            ->when($filtros['plan_id'], fn ($q, $v) => $q->where('plan_id', $v))
            ->when($filtros['turno_id'], fn ($q, $v) => $q->where('turno_id', $v))
            ->when($filtros['situacion_id'], fn ($q, $v) => $q->where('situacion_id', $v))
            ->orderByDesc('ciclo_id')
            ->orderBy('clave')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Grupo $grupo) => [
                'id' => $grupo->id,
                'clave' => $grupo->clave,
                'nombre' => $grupo->nombre,
                'ciclo' => $grupo->ciclo?->clave,
                'campus' => $grupo->campus?->nombre,
                'plan' => $grupo->plan?->nombre,
                'turno' => $grupo->turno?->nombre,
                'situacion' => $grupo->situacion?->nombre,
                'ciclo_id' => $grupo->ciclo_id,
                'cupo' => $grupo->cupo,
                'materias_count' => $grupo->asignaturas_count,
                'alumnos_count' => (int) $grupo->alumnos_count,
            ]);

        return Inertia::render('ControlEscolar/Grupos/Index', [
            'grupos' => $grupos,
            'filtros' => $filtros,
            // El ciclo se reconoce por su clave, no por su nombre: en la tabla
            // se muestra la clave y así el filtro habla el mismo idioma.
            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $ciclo) => ['id' => $ciclo->id, 'nombre' => $ciclo->clave]),
            'campus' => Campus::query()
                ->when($campusVisibles !== [], fn ($q) => $q->whereIn('id', $campusVisibles))
                ->orderBy('nombre')->get(['id', 'nombre']),
            'planes' => PlanEstudio::query()->orderBy('nombre')->get(['id', 'nombre']),
            'turnos' => Turno::query()->orderBy('id')->get(['id', 'nombre']),
            'situaciones' => SituacionGrupo::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('abrir-grupos'),
            'puedeInscribir' => $request->user()->can('inscribir-alumnos'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ControlEscolar/Grupos/Formulario', [
            'grupo' => null,
            ...$this->catalogos(),
        ]);
    }

    /**
     * Los ciclos que se pueden elegir para un grupo.
     *
     * Los vigentes, más el que el grupo ya tiene aunque esté cerrado: si
     * desapareciera del desplegable, editar la clave de un grupo viejo lo dejaría
     * sin ciclo y guardar lo movería a otro. La regla vive en
     * `Ciclo::scopeVigentes`.
     */
    private function ciclosElegibles(?Grupo $grupo = null): Collection
    {
        return Ciclo::query()
            ->with(['campus:id', 'niveles:id'])
            ->vigentes($grupo?->ciclo_id)
            ->orderByDesc('fecha_inicio')
            ->get(['id', 'clave', 'nombre']);
    }

    public function store(Request $request): RedirectResponse
    {
        $grupo = Grupo::create($this->validar($request));

        return redirect()
            ->route('tenant.escolar.grupos.show', $grupo)
            ->with('exito', 'Grupo creado. Ahora abre sus materias.');
    }

    /**
     * Detalle del grupo: sus materias abiertas, con los docentes de cada una y
     * cuántos alumnos lleva inscritos.
     *
     * Los alumnos NO viajan aquí, solo su conteo. Antes se mandaba la lista
     * completa de inscritos de cada materia: un grupo corriente —seis materias,
     * treinta alumnos— serializaba ciento ochenta renglones con persona y
     * matrícula cargadas, para pintar una pantalla donde lo que se viene a ver
     * son seis materias. La gestión de alumnos vive en «Inscribir».
     */
    public function show(Request $request, Grupo $grupo): Response
    {
        $grupo->load(['ciclo:id,clave,nombre', 'campus:id,nombre', 'plan:id,nombre,tipo_periodo_id', 'plan.tipoPeriodo:id,nombre', 'situacion:id,nombre', 'turno:id,nombre']);

        $bajaId = SituacionInscripcion::query()->where('clave', 'baja')->value('id');

        $asignaturas = AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre', 'planMateria.plan:id,nombre,carrera_id', 'planMateria.plan.carrera:id,nombre',
                'situacion:id,nombre', 'docentes.persona',
            ])
            // Conteo en SQL en vez de traer las filas para contarlas en PHP.
            ->withCount(['inscripciones as inscritos_count' => fn ($q) => $bajaId === null
                ? $q
                : $q->where(fn ($s) => $s->whereNull('situacion_id')->orWhere('situacion_id', '!=', $bajaId))])
            ->where('grupo_id', $grupo->id)
            ->get();

        return Inertia::render('ControlEscolar/Grupos/Detalle', [
            'grupo' => [
                'id' => $grupo->id,
                'ciclo_id' => $grupo->ciclo_id,
                'clave' => $grupo->clave,
                'nombre' => $grupo->nombre,
                'ciclo' => $grupo->ciclo?->clave,
                'campus' => $grupo->campus?->nombre,
                'plan' => $grupo->plan?->nombre,
                'situacion' => $grupo->situacion?->nombre,
                'cupo' => $grupo->cupo,
                'turno' => $grupo->turno?->nombre,
                // Alumnos DISTINTOS en el grupo (no renglones de inscripción):
                // uno en seis materias sigue siendo un alumno, y es lo que se
                // compara contra el cupo.
                'alumnos_count' => Inscripcion::query()
                    ->whereHas('asignaturaGrupo', fn ($q) => $q->where('grupo_id', $grupo->id))
                    ->when($bajaId !== null, fn ($q) => $q->where(
                        fn ($s) => $s->whereNull('situacion_id')->orWhere('situacion_id', '!=', $bajaId),
                    ))
                    ->distinct()
                    ->count('matricula_oferta_id'),
                // El GRADO del grupo. Preselecciona las materias de ese periodo
                // al abrirlas, pero NO cambia por abrirle materias de otro: el
                // grado dice quiénes cursan el grupo, no qué se imparte.
                'semestre' => $grupo->semestre,
                'nivel' => $grupo->nivel_estudios_id !== null
                    ? NivelEstudio::find($grupo->nivel_estudios_id)?->nombre
                    : null,
                // Nombre real del periodo del plan del grupo (Semestre,
                // Cuatrimestre…). Null si el grupo no tiene plan fijo: ahí las
                // materias pueden venir de planes con periodicidades distintas y
                // se cae al genérico «Periodo».
                'unidad_periodo' => $grupo->plan?->unidadPeriodo(),
            ],
            'asignaturas' => $asignaturas->map(fn (AsignaturaGrupo $asignatura) => [
                'id' => $asignatura->id,
                'clave_en_plan' => $asignatura->planMateria?->clave_en_plan,
                'materia' => $asignatura->planMateria?->asignatura?->nombre,
                // Mismo criterio que en materias disponibles: carrera + plan.
                'plan' => $asignatura->planMateria?->plan === null
                    ? null
                    : trim(($asignatura->planMateria->plan->carrera?->nombre ?? '').' · '.$asignatura->planMateria->plan->nombre, ' ·'),
                'situacion' => $asignatura->situacion?->nombre,
                'inscritos_count' => (int) $asignatura->inscritos_count,
                // Los ids de quienes ya imparten esta materia, con nombre y
                // tipo: el buscador no vuelve a ofrecerlos, y cada uno puede
                // quitarse (por si se cargó al docente equivocado).
                'docentes_asignados' => $asignatura->docentes
                    ->map(fn ($d) => [
                        'id' => $d->persona_id,
                        'nombre' => $d->persona?->nombreCompleto(),
                        'tipo' => $d->pivot->tipo,
                    ])
                    ->values(),
            ]),
            // Materias del plan que aún no se abren en este grupo.
            'materiasDisponibles' => $this->materiasDisponibles($grupo, $asignaturas->pluck('plan_materia_id')->all()),
            'docentes' => Docente::query()
                ->with('persona:id,nombre,primer_apellido,segundo_apellido')
                ->get()
                ->map(fn (Docente $docente) => [
                    'id' => $docente->persona_id,
                    'nombre' => $docente->persona?->nombreCompleto(),
                ]),
            // Catálogo corto (ordinaria, extraordinaria, a título…) para
            // inscribir a un alumno suelto en una materia. Los ALUMNOS no
            // viajan aquí: se buscan por coincidencia contra el servidor.
            'tiposEvaluacion' => TipoEvaluacion::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('abrir-grupos'),
            'puedeInscribir' => $request->user()->can('inscribir-alumnos'),
        ]);
    }

    public function edit(Grupo $grupo): Response
    {
        return Inertia::render('ControlEscolar/Grupos/Formulario', [
            'grupo' => $grupo->only([
                'id', 'ciclo_id', 'campus_id', 'nivel_estudios_id', 'plan_id', 'semestre', 'clave', 'nombre', 'cupo', 'turno_id',
            ]),
            ...$this->catalogos($grupo),
        ]);
    }

    public function update(Request $request, Grupo $grupo): RedirectResponse
    {
        $grupo->update($this->validar($request, $grupo));

        return redirect()->route('tenant.escolar.grupos.index')->with('exito', 'Grupo actualizado.');
    }

    public function destroy(Grupo $grupo): RedirectResponse
    {
        if ($grupo->asignaturas()->exists()) {
            return back()->with('error', 'No se puede eliminar: el grupo tiene materias abiertas.');
        }

        $grupo->delete();

        return back()->with('exito', 'Grupo eliminado.');
    }

    /**
     * Da de baja a un alumno de TODO el grupo: sus inscripciones vigentes en
     * cualquier materia de este grupo pasan a situación «baja». No se borran —la
     * baja es historia escolar—; para quitar de una sola materia se usa la baja
     * por inscripción. Es el «me equivoqué de alumno» de un clic.
     */
    public function bajarAlumno(Grupo $grupo, MatriculaOferta $matricula): RedirectResponse
    {
        $bajaId = SituacionInscripcion::query()->where('clave', 'baja')->value('id');

        $afectadas = Inscripcion::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->whereHas('asignaturaGrupo', fn ($q) => $q->where('grupo_id', $grupo->id))
            ->where('situacion_id', '!=', $bajaId)
            ->update(['situacion_id' => $bajaId]);

        return back()->with(
            $afectadas > 0 ? 'exito' : 'error',
            $afectadas > 0
                ? "Alumno dado de baja del grupo ({$afectadas} materia(s))."
                : 'Ese alumno no tenía inscripciones vigentes en el grupo.',
        );
    }

    /**
     * Busca alumnos para inscribir en UNA materia del grupo, por coincidencia.
     *
     * No se mandan los alumnos junto con la pantalla ni se ofrecen en un
     * desplegable: una escuela con mil matriculados haría inútil una lista y
     * caro cada render. Aquí solo viajan las coincidencias de lo que se teclea.
     *
     * El filtro replica lo que el validador va a exigir después —activo, del
     * plan de la materia, no inscrito ya— para no ofrecer a nadie que vaya a
     * rebotar. El validador vuelve a comprobarlo todo al inscribir: esto es
     * comodidad, no la regla.
     */
    public function buscarCandidatos(Request $request, Grupo $grupo, AsignaturaGrupo $asignatura): JsonResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $planDeLaMateria = $asignatura->planMateria?->plan_id;
        $bajaId = SituacionInscripcion::query()->where('clave', 'baja')->value('id');

        // Quien ya está en ESTA materia (y no de baja) no es candidato.
        $yaEnLaMateria = Inscripcion::query()
            ->where('asignatura_grupo_id', $asignatura->id)
            ->when($bajaId !== null, fn ($c) => $c->where(
                fn ($s) => $s->whereNull('situacion_id')->orWhere('situacion_id', '!=', $bajaId),
            ))
            ->pluck('matricula_oferta_id');

        $alumnos = MatriculaOferta::query()
            ->where('estatus', 'activo')
            ->whereNotIn('id', $yaEnLaMateria)
            ->when($planDeLaMateria !== null, fn ($c) => $c->whereHas(
                'oferta',
                fn ($o) => $o->where('plan_id', $planDeLaMateria),
            ))
            ->where(function ($consulta) use ($q) {
                $consulta->where('matricula', 'like', "%{$q}%")
                    ->orWhereHas('persona', fn ($p) => $p
                        ->where('nombre', 'like', "%{$q}%")
                        ->orWhere('primer_apellido', 'like', "%{$q}%")
                        ->orWhere('segundo_apellido', 'like', "%{$q}%"));
            })
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.carrera:id,nombre', 'oferta.campus:id,nombre'])
            ->orderBy('matricula')
            ->limit(20)
            ->get()
            ->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre' => $m->persona?->nombreCompleto(),
                // El campus se muestra cuando NO es el del grupo: inscribir a
                // alguien de otro campus se puede, pero no por descuido.
                'carrera' => trim(($m->oferta?->carrera?->nombre ?? '').
                    ($m->oferta?->campus_id !== $grupo->campus_id ? ' · '.($m->oferta?->campus?->nombre ?? 'otro campus') : '')),
            ]);

        return response()->json($alumnos);
    }

    /**
     * Materias que se pueden abrir aquí: las del plan del grupo (o de cualquier
     * plan, si el grupo no está atado a uno) que todavía no estén abiertas.
     *
     * @param  array<int, int>  $yaAbiertas
     * @return array<int, array<string, mixed>>
     */
    private function materiasDisponibles(Grupo $grupo, array $yaAbiertas): array
    {
        return PlanMateria::query()
            ->with(['asignatura:id,nombre', 'plan:id,nombre,carrera_id', 'plan.carrera:id,nombre'])
            ->when($grupo->plan_id !== null, fn ($q) => $q->where('plan_id', $grupo->plan_id))
            ->whereNotIn('id', $yaAbiertas)
            ->orderBy('periodo')
            ->orderBy('clave_en_plan')
            ->get()
            ->map(fn (PlanMateria $materia) => [
                'id' => $materia->id,
                'clave_en_plan' => $materia->clave_en_plan,
                'materia' => $materia->asignatura?->nombre,
                // Carrera + plan, no solo el plan: los planes se llaman por su
                // año («Plan 2022») y hay uno por carrera, así que el nombre a
                // secas no distingue dos materias de clave y nombre iguales
                // —que es justo lo que hay que distinguir en un grupo sin plan
                // fijo, donde se ven todas las de todas las carreras.
                'plan' => $materia->plan === null
                    ? null
                    : trim(($materia->plan->carrera?->nombre ?? '').' · '.$materia->plan->nombre, ' ·'),
                // El periodo va suelto, no embebido en la etiqueta: la pantalla
                // filtra por él para proponer "las de tercer semestre" en vez de
                // obligar a leer una lista de cincuenta.
                'periodo' => $materia->periodo,
                'tipo' => $materia->tipo,
                'etiqueta' => sprintf(
                    '%s · %s',
                    $materia->clave_en_plan,
                    $materia->asignatura?->nombre ?? '',
                ),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Grupo $grupo = null): array
    {
        $id = $grupo?->id;

        $datos = $request->validate([
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')->whereNull('deleted_at')],
            'campus_id' => ['required', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            /*
             * El plan pasa a ser obligatorio.
             *
             * Existía el grupo "sin plan fijo", que tomaba materias de varios, y
             * se pagaba en todo lo que viene después: el grado quedaba numerado
             * con un rango genérico en vez del periodo real del plan, la
             * inscripción no podía sugerir las materias que tocan, y el nivel
             * había que capturarlo aparte porque no había de dónde deducirlo. Un
             * grupo que de verdad cruce planes se abre como dos.
             */
            'plan_id' => ['required', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            // Un grupo es "1° A de Secundaria" antes que cualquier otra cosa: el
            // nivel es suyo, no se deduce del plan (que puede no tener).
            'nivel_estudios_id' => ['required', 'integer'],
            // El grado dice QUIÉNES lo cursan, no qué se imparte: abrirle una
            // materia de otro grado no lo cambia.
            'semestre' => ['required', 'integer', 'min:1', 'max:20'],
            'clave' => [
                'required', 'string', 'max:70',
                Rule::unique('grupos', 'clave')
                    ->where('ciclo_id', $request->input('ciclo_id'))
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'nombre' => ['nullable', 'string', 'max:200'],
            'cupo' => ['required', 'integer', 'min:1'],
            'turno_id' => ['nullable', 'integer', Rule::exists('turnos', 'id')->whereNull('deleted_at')],
        ], [
            'clave.unique' => 'Ya hay un grupo con esa clave en ese ciclo.',
        ], [
            'ciclo_id' => 'ciclo',
            'campus_id' => 'campus',
            'plan_id' => 'plan de estudios',
            'nivel_estudios_id' => 'nivel de estudios',
            'semestre' => 'grado',
            'turno_id' => 'turno',
        ]);

        $this->exigirCicloVigente($datos, $grupo);
        $this->exigirRestriccionesDelCiclo($datos);
        $this->exigirNivelCoherente($datos);

        // La situación NO se captura: un grupo nace abierto. Cerrarlo o
        // cancelarlo es una decisión posterior, con su propia acción.
        if ($id === null) {
            $datos['situacion_id'] = SituacionGrupo::query()->where('clave', 'abierto')->value('id')
                ?? SituacionGrupo::query()->orderBy('id')->value('id');
        }

        return $datos;
    }

    /**
     * El nivel del grupo tiene que cuadrar con lo demás.
     *
     * Ahora que el nivel es un dato propio y el plan es opcional, quedan dos
     * formas de contradecirse: elegir un nivel que el ciclo no admite, o un plan
     * cuya carrera es de otro nivel. Ninguna se puede detectar sola en el
     * formulario, y las dos producen grupos que después nadie sabe interpretar.
     *
     * @param  array<string, mixed>  $datos
     */
    private function exigirNivelCoherente(array $datos): void
    {
        $nivel = (int) $datos['nivel_estudios_id'];

        $ciclo = Ciclo::query()->with('niveles:id')->find($datos['ciclo_id']);
        $nivelesDelCiclo = $ciclo?->niveles->pluck('id') ?? collect();

        if ($nivelesDelCiclo->isNotEmpty() && ! $nivelesDelCiclo->contains($nivel)) {
            throw ValidationException::withMessages([
                'nivel_estudios_id' => 'Ese nivel no está entre los del ciclo.',
            ]);
        }

        if (empty($datos['plan_id'])) {
            return;
        }

        $nivelDelPlan = PlanEstudio::query()
            ->join('carreras', 'carreras.id', '=', 'planes_estudio.carrera_id')
            ->where('planes_estudio.id', $datos['plan_id'])
            ->value('carreras.nivel_estudios_id');

        if ($nivelDelPlan !== null && (int) $nivelDelPlan !== $nivel) {
            throw ValidationException::withMessages([
                'plan_id' => 'Ese plan es de otro nivel de estudios que el del grupo.',
            ]);
        }
    }

    /**
     * El ciclo puede acotar sus grupos: a ciertos campus y/o a un nivel de
     * estudios. El formulario ya ofrece solo lo válido, pero un POST se arma a
     * mano, así que el servidor lo vuelve a exigir: una casilla que no existe no
     * es defensa.
     *
     * @param  array<string, mixed>  $datos
     */
    /**
     * No se abren grupos en un ciclo cerrado.
     *
     * Ese ciclo ya rindió sus actas; capturar dentro de él es capturar en un
     * periodo que la escuela dio por terminado. El desplegable ya no los ofrece,
     * pero la regla vive aquí: el formulario es una comodidad y esto es la
     * frontera.
     *
     * Se exceptúa el ciclo que el grupo YA tenía. Un grupo viejo se sigue
     * pudiendo editar —corregir su clave, su cupo— sin que eso obligue a
     * mudarlo a un ciclo vigente, que sería falsear su historia.
     *
     * @param  array<string, mixed>  $datos
     */
    private function exigirCicloVigente(array $datos, ?Grupo $grupo): void
    {
        $elegido = (int) $datos['ciclo_id'];

        if ($grupo !== null && $grupo->ciclo_id === $elegido) {
            return;
        }

        $cerrado = SituacionCiclo::query()->where('clave', 'cerrado')->value('id');

        if ($cerrado === null) {
            return;
        }

        if (Ciclo::query()->whereKey($elegido)->where('situacion_id', $cerrado)->exists()) {
            throw ValidationException::withMessages([
                'ciclo_id' => 'Ese ciclo está cerrado: no se le pueden abrir grupos.',
            ]);
        }
    }

    private function exigirRestriccionesDelCiclo(array $datos): void
    {
        $ciclo = Ciclo::query()->with(['campus:id', 'niveles:id'])->find($datos['ciclo_id']);

        if ($ciclo === null) {
            return;
        }

        $campusDelCiclo = $ciclo->campus->pluck('id');

        // Si el ciclo tiene campus asignados, el grupo debe ser de uno de ellos.
        // Sin campus, el ciclo es global y no restringe.
        if ($campusDelCiclo->isNotEmpty() && ! $campusDelCiclo->contains((int) $datos['campus_id'])) {
            throw ValidationException::withMessages([
                'campus_id' => 'Ese campus no está entre los del ciclo.',
            ]);
        }

        // Si el ciclo se acota a uno o varios niveles, el plan del grupo debe
        // ser de una carrera de alguno de esos niveles. Sin plan no hay nivel
        // que contradecir.
        $nivelesDelCiclo = $ciclo->niveles->pluck('id');

        if ($nivelesDelCiclo->isNotEmpty() && ! empty($datos['plan_id'])) {
            $nivelDelPlan = PlanEstudio::query()
                ->join('carreras', 'carreras.id', '=', 'planes_estudio.carrera_id')
                ->where('planes_estudio.id', $datos['plan_id'])
                ->value('carreras.nivel_estudios_id');

            if (! $nivelesDelCiclo->contains((int) $nivelDelPlan)) {
                throw ValidationException::withMessages([
                    'plan_id' => 'Ese plan no es de los niveles de estudio a los que está acotado el ciclo.',
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(?Grupo $grupo = null): array
    {
        return [
            // Cada ciclo viaja con lo que ACOTA: sus campus y su nivel. El
            // formulario lo usa para ofrecer solo campus y planes válidos según
            // el ciclo elegido. Los cerrados no se ofrecen: ver `ciclosElegibles`.
            'ciclos' => $this->ciclosElegibles($grupo)
                ->map(fn (Ciclo $ciclo) => [
                    'id' => $ciclo->id,
                    'nombre' => "{$ciclo->clave} — {$ciclo->nombre}",
                    'campus_ids' => $ciclo->campus->pluck('id')->all(),
                    'nivel_ids' => $ciclo->niveles->pluck('id')->all(),
                ]),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            // La carrera viaja con su nivel para poder filtrar por el del ciclo.
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre', 'nivel_estudios_id']),
            // Los planes viajan con su carrera para que el formulario los
            // filtre en cascada: una escuela con seis carreras y cuatro planes
            // cada una presenta 24 opciones en un solo desplegable, y elegir el
            // plan equivocado ata el grupo a una carrera que no era.
            // `total_periodos` alimenta el select de periodo (1..N), que respeta
            // si la carrera es semestral, cuatrimestral, etc.
            'planes' => PlanEstudio::query()
                ->with('tipoPeriodo:id,nombre')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'carrera_id', 'clave', 'total_periodos', 'tipo_periodo_id'])
                ->map(fn (PlanEstudio $plan) => [
                    'id' => $plan->id,
                    'nombre' => $plan->nombre,
                    'clave' => $plan->clave,
                    'carrera_id' => $plan->carrera_id,
                    'total_periodos' => $plan->total_periodos,
                    // Nombre real del periodo (Semestre, Cuatrimestre, Módulo…):
                    // el formulario nombra el campo con él en vez del genérico
                    // "Periodo".
                    'unidad_periodo' => $plan->unidadPeriodo(),
                ]),
            // La oferta manda: un grupo solo puede abrirse para una carrera+plan
            // que ya esté ofertada en ese campus. Se envían las combinaciones
            // abiertas para que el formulario filtre carrera y plan según el
            // campus elegido.
            'ofertas' => Oferta::query()
                ->where('estatus', 'abierta')
                ->get(['carrera_id', 'plan_id', 'campus_id'])
                ->map(fn (Oferta $oferta) => [
                    'carrera_id' => $oferta->carrera_id,
                    'plan_id' => $oferta->plan_id,
                    'campus_id' => $oferta->campus_id,
                ])
                ->unique(fn (array $o) => "{$o['carrera_id']}-{$o['plan_id']}-{$o['campus_id']}")
                ->values(),
            'turnos' => Turno::query()->orderBy('nombre')->get(['id', 'nombre']),
            // El nivel ahora es un dato propio del grupo, no un derivado del
            // plan: se ofrece como campo, y el ciclo lo acota.
            'niveles' => NivelEstudio::query()->activos()->orderBy('orden')->get(['id', 'nombre']),
        ];
    }
}
