<?php

declare(strict_types=1);

namespace App\Http\Controllers\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoEquipo;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\Intervencion;
use App\Models\Permanencia\MotivoCierreCaso;
use App\Models\Permanencia\RiesgoMatricula;
use App\Models\Permanencia\TareaCaso;
use App\Models\Permanencia\TipoIntervencion;
use App\Services\Permanencia\AbridorDeCaso;
use App\Services\Permanencia\AlcanceDeCasos;
use App\Services\Permanencia\RegistroDeIntervenciones;
use App\Services\Permanencia\TransicionDeCaso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los casos: el seguimiento humano de una señal validada.
 *
 * ── Esta pantalla NO decide nada por su cuenta ─────────────────────────────
 * Abrir, mover, intervenir y cerrar viven en servicios —`AbridorDeCaso`,
 * `TransicionDeCaso`, `RegistroDeIntervenciones`— y el controlador sólo valida
 * la forma de lo que llega y traduce el resultado a pantalla. Con las reglas
 * aquí, la ruta que alguien agregue el mes que viene se saltará la bitácora o el
 * bloqueo de fila, y **no fallará**: dejará un caso movido sin rastro.
 *
 * ── Las CINCO capas de visibilidad ─────────────────────────────────────────
 *  1. El MÓDULO (`permanencia`): apagado, 404 y sin menú.
 *  2. El PERMISO de entrada (`ver-alertas`) y el de cada acto, que cada método
 *     vuelve a comprobar — el listado no es una defensa.
 *  3. El CAMPUS, por `AlcanceDeCasos`, en el listado, la ficha y CADA acción.
 *  4. La VISIBILIDAD de cada intervención, resuelta en el servidor: lo que no se
 *     alcanza no viaja.
 *  5. La CATEGORÍA de las señales que originaron el caso, por `Alerta::comoLaVe`
 *     — un caso abierto por adeudo no le enseña el monto a quien no lo alcanza.
 *
 * ── Y abrir la ficha DEJA CONSTANCIA ───────────────────────────────────────
 * `accesos_caso`, con cuántas intervenciones se enseñaron y cuántas quedaron
 * reservadas. Se registra la CONSULTA, no el contenido: una auditoría que copie
 * lo vigilado multiplica el problema que intenta resolver.
 */
class CasoController extends Controller
{
    use AcotaPorCampus;

    /** Cuántos caben en una página. Pública para poder comprobar el tope. */
    public const POR_PAGINA = 25;

    public function __construct(
        private readonly AlcanceDeCasos $alcance,
        private readonly AbridorDeCaso $abridor,
        private readonly TransicionDeCaso $transiciones,
        private readonly RegistroDeIntervenciones $intervenciones,
    ) {}

    public function index(Request $peticion): Response
    {
        $consulta = $this->base($peticion)->with([
            'matricula:id,persona_id,matricula,oferta_id',
            'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
            'matricula.oferta:id,programa_academico_id',
            'matricula.oferta.programaAcademico:id,nombre',
            'campus:id,nombre',
            'responsable:id,persona_id',
            'responsable.persona:id,nombre,primer_apellido,segundo_apellido',
            'nivelApertura:id,clave,nombre,color',
        ])->withCount(['intervenciones', 'tareas as tareas_pendientes' => fn ($q) => $q->pendientes()]);

        $this->aplicarFiltros($consulta, $peticion);

        $pagina = $consulta
            /*
             * Lo VENCIDO primero, después lo más prioritario y, dentro, lo más
             * viejo. Ordenada sólo por prioridad, la cola deja para siempre
             * abajo lo que lleva tres semanas sin tocarse; ordenada sólo por
             * fecha, un caso alto recién abierto se pierde detrás de veinte
             * bajos. Es el mismo criterio que la bandeja de alertas.
             */
            ->orderByRaw('case when sla_vence_en is not null and primer_contacto_en is null '
                .'and sla_vence_en < now() and estado <> ? then 0 else 1 end', [EstadoCaso::Cerrado->value])
            ->orderByRaw("field(prioridad, 'alta', 'media', 'baja')")
            ->orderBy('abierto_en')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return Inertia::render('Permanencia/Casos/Index', [
            'casos' => $pagina->through(fn (CasoPermanencia $c) => $this->paraLaLista($c)),
            'resumen' => $this->resumen($peticion),
            'catalogos' => $this->catalogos($peticion),
            'filtros' => $peticion->only(['estado', 'prioridad', 'campus_id', 'responsable_id',
                'sla', 'sin_asignar', 'busqueda']),
            'permisos' => $this->permisosDe($peticion->user()),
        ]);
    }

    public function show(Request $peticion, int $caso): Response
    {
        $modelo = $this->alcanzable($peticion, $caso);

        $usuario = $peticion->user();

        $leidas = $this->intervenciones->paraLeer($modelo, $usuario);

        /*
         * La constancia se escribe al ENSEÑAR, no al pulsar un botón: quien abre
         * la ficha ya vio lo que hay. Y guarda cuántas quedaron ocultas, que es
         * lo que dice cuánto NO se le mostró.
         */
        $this->intervenciones->registrarConsulta(
            $modelo,
            $usuario,
            $peticion->ip(),
            $leidas['visibles']->count(),
            $leidas['ocultas'],
        );

        return Inertia::render('Permanencia/Casos/Detalle', [
            'caso' => $this->paraLaFicha($modelo),
            'intervenciones' => $leidas['visibles']->map(fn (Intervencion $i) => $this->intervencion($i)),
            /*
             * Cuántas hay que este rol no alcanza. Se DICE en vez de esconderse:
             * callarlas haría creer que el caso está vacío, y quien lo atiende
             * necesita saber que hay algo que no está viendo —aunque no pueda
             * leerlo—.
             */
            'reservadas_ocultas' => $leidas['ocultas'],
            'tareas' => $modelo->tareas()->with('responsable.persona')->orderByRaw('completada_en is not null')
                ->orderBy('vence_en')->get()->map(fn (TareaCaso $t) => [
                    'id' => $t->id,
                    'titulo' => $t->titulo,
                    'responsable' => $t->responsable?->persona?->nombreCompleto(),
                    'vence_en' => $t->vence_en?->toDateString(),
                    'completada_en' => $t->completada_en?->format('Y-m-d H:i'),
                    'resultado' => $t->resultado,
                    'vencida' => $t->completada_en === null && $t->vence_en !== null && $t->vence_en->isPast(),
                ]),
            'equipo' => $modelo->equipo()->with('persona')->orderBy('desde')->get()
                ->map(fn (CasoEquipo $e) => [
                    'id' => $e->id,
                    'persona' => $e->persona?->nombreCompleto(),
                    'papel' => $e->papel,
                    'desde' => $e->desde?->toDateString(),
                    'hasta' => $e->hasta?->toDateString(),
                    'vigente' => $e->hasta === null || ! $e->hasta->isPast(),
                ]),
            'senales' => $modelo->alertas()->with('categoria', 'regla:id,nombre', 'version')->get()
                ->map(fn (Alerta $a) => $a->comoLaVe($usuario)),
            'riesgo' => RiesgoMatricula::query()
                ->vigenteDe($modelo->matricula_oferta_id)
                ->with('nivel', 'nivelAnterior', 'nivelAjustado', 'ajustadoPor.persona')
                ->first()?->comoSeLee(),
            'historia' => $modelo->transiciones()->with('usuario.persona')->get()->map(fn ($t) => [
                'origen' => $t->estado_origen?->etiqueta(),
                'destino' => $t->estado_destino->etiqueta(),
                'motivo' => $t->motivo,
                'quien' => $t->usuario?->persona?->nombreCompleto(),
                'momento' => $t->momento?->format('Y-m-d H:i'),
            ]),
            /*
             * Quién ha abierto esta ficha. Se enseña a quien la mira —no se
             * esconde en una tabla que nadie consulta—: una bitácora que no se
             * ve no disuade de nada, que es la mitad de lo que hace por lo que
             * existe. Mismo criterio que las notas de tutoría.
             */
            'consultas' => $modelo->accesos()->with('persona')->latest('creado_en')->limit(20)->get()
                ->map(fn ($a) => [
                    'persona' => $a->persona?->nombreCompleto() ?? 'Sin identificar',
                    'cuando' => $a->creado_en?->format('Y-m-d H:i'),
                    'vistas' => $a->intervenciones_vistas,
                    'ocultas' => $a->reservadas_ocultas,
                ]),
            'destinos' => $modelo->estado->paraPantalla(),
            'catalogos' => [
                'tipos' => TipoIntervencion::query()->activos()->get(['id', 'nombre', 'descripcion',
                    'exige_evidencia', 'exige_acuerdos', 'exige_proxima_fecha', 'permite_reservada']),
                'motivos_cierre' => MotivoCierreCaso::query()->activos()
                    ->get(['id', 'nombre', 'descripcion', 'cuenta_como_exito']),
                'visibilidades' => Intervencion::VISIBILIDADES,
                'estados_intervencion' => Intervencion::ESTADOS,
                'prioridades' => CasoPermanencia::PRIORIDADES,
            ],
            'permisos' => $this->permisosDe($usuario),
        ]);
    }

    /**
     * Abrir un caso desde una alerta VALIDADA.
     *
     * Entra por la alerta y no por la matrícula a propósito: un caso nace de una
     * señal que alguien revisó, y abrirlo «sobre un alumno» saltaría el triage
     * entero. Si ya hay uno abierto, el servicio le suma la señal en vez de crear
     * un segundo.
     */
    public function abrir(Request $peticion, int $alerta): RedirectResponse
    {
        $modelo = $this->alertaAlcanzable($peticion, $alerta);

        $datos = $peticion->validate([
            'prioridad' => ['nullable', Rule::in(CasoPermanencia::PRIORIDADES)],
            'sla_horas' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $caso = $this->abridor->abrir(
            $modelo,
            $peticion->user(),
            $datos['prioridad'] ?? null,
            isset($datos['sla_horas']) ? (int) $datos['sla_horas'] : null,
            $peticion->ip(),
        );

        return redirect()
            ->route('tenant.permanencia.casos.detalle', $caso->id)
            ->with('exito', 'Caso '.$caso->folio.' abierto. La señal quedó atada a él.');
    }

    /**
     * Mover el caso de estado.
     *
     * El destino que no cuelga del origen se rehúsa enumerando a dónde sí se
     * puede; nunca se «corrige» al más cercano. Cerrar exige además motivo del
     * catálogo, porque de ahí sale si la intervención sirvió.
     */
    public function mover(Request $peticion, int $caso): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        $datos = $peticion->validate([
            'estado' => ['required', Rule::in(EstadoCaso::claves())],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'motivo_cierre_id' => ['nullable', 'integer',
                Rule::exists('motivos_cierre_caso', 'id')->whereNull('deleted_at')],
            'resultado' => ['nullable', 'string', 'max:2000'],
        ]);

        $destino = EstadoCaso::from($datos['estado']);

        $ademas = [];

        if ($destino === EstadoCaso::Cerrado) {
            /*
             * El motivo del catálogo es obligatorio al cerrar, y no es lo mismo
             * que el texto: de la BANDERA del motivo sale si la intervención
             * sirvió. Con texto libre, «efectividad» habría que leerla a mano en
             * trescientas frases. Mismo argumento que el descarte de una señal.
             */
            AvisoParaElUsuario::si(
                ($datos['motivo_cierre_id'] ?? null) === null,
                422,
                'Cerrar exige elegir el motivo del catálogo: es de donde sale si el acompañamiento sirvió.',
            );

            $ademas = [
                'motivo_cierre_id' => $datos['motivo_cierre_id'],
                'resultado' => $datos['resultado'] ?? null,
                'cerrado_en' => now(),
            ];
        }

        $this->transiciones->mover(
            $modelo,
            $destino,
            $peticion->user(),
            $datos['motivo'] ?? null,
            $peticion->ip(),
            $ademas,
        );

        return back(303)->with('exito', 'El caso pasó a «'.$destino->etiqueta().'».');
    }

    /**
     * Asignar responsable y compromiso de primer contacto.
     *
     * Asignar es lo que ARRANCA el compromiso: sin responsable, un caso abierto
     * es una nota que nadie lee. Por eso mueve el estado a «asignado» por la
     * misma puerta —la transición deja su renglón— en vez de escribir la columna
     * a mano.
     */
    public function asignar(Request $peticion, int $caso): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('asignar-casos') === true,
            403,
            'Tu rol no puede asignar casos.',
        );

        $datos = $peticion->validate([
            'responsable_id' => ['required', 'integer', Rule::exists('usuarios', 'id')],
            'sla_horas' => ['nullable', 'integer', 'min:1', 'max:720'],
            'prioridad' => ['nullable', Rule::in(CasoPermanencia::PRIORIDADES)],
        ], [], ['responsable_id' => 'responsable']);

        $ademas = ['responsable_id' => $datos['responsable_id']];

        if (isset($datos['prioridad'])) {
            $ademas['prioridad'] = $datos['prioridad'];
        }

        /*
         * El compromiso se pone al ASIGNAR y sólo si todavía no lo había:
         * reescribirlo al reasignar movería la meta de sitio y un caso vencido
         * dejaría de estarlo con sólo cambiarle el responsable.
         */
        if (isset($datos['sla_horas']) && $modelo->sla_vence_en === null) {
            $ademas['sla_vence_en'] = now()->addHours((int) $datos['sla_horas']);
        }

        if ($modelo->estado === EstadoCaso::Abierto) {
            $this->transiciones->mover($modelo, EstadoCaso::Asignado, $peticion->user(),
                null, $peticion->ip(), $ademas);
        } else {
            // Reasignar sobre un caso que ya avanzó no lo devuelve a «asignado»:
            // eso borraría del expediente que ya se estaba interviniendo.
            $modelo->forceFill($ademas)->save();
        }

        return back(303)->with('exito', 'Asignado. El compromiso de primer contacto ya corre.');
    }

    /** Sumar a alguien al equipo, o darlo de baja. */
    public function equipo(Request $peticion, int $caso): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('asignar-casos') === true,
            403,
            'Tu rol no puede cambiar el equipo de un caso.',
        );

        $datos = $peticion->validate([
            'persona_id' => ['required', 'integer', Rule::exists('personas', 'id')->whereNull('deleted_at')],
            'papel' => ['nullable', 'string', 'max:100'],
        ], [], ['persona_id' => 'persona']);

        /*
         * Sumar a quien YA está vigente no crea una segunda fila: dejaría al
         * mismo nombre dos veces en la lista y, peor, quitarlo una vez no lo
         * quitaría de verdad.
         */
        $yaEsta = $modelo->equipo()->vigentes()->where('persona_id', $datos['persona_id'])->exists();

        AvisoParaElUsuario::si($yaEsta, 422, 'Esa persona ya está en el equipo del caso.');

        CasoEquipo::create([
            'caso_id' => $modelo->id,
            'persona_id' => $datos['persona_id'],
            'papel' => $datos['papel'] ?? null,
            'desde' => now()->toDateString(),
        ]);

        return back(303)->with('exito', 'Se sumó al equipo.');
    }

    /**
     * Sacar a alguien del equipo: se le pone fecha de SALIDA, no se borra.
     *
     * Sus notas de equipo siguen explicándose por su participación, y borrándola
     * el expediente diría que nunca estuvo.
     */
    public function sacarDelEquipo(Request $peticion, int $caso, int $miembro): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('asignar-casos') === true,
            403,
            'Tu rol no puede cambiar el equipo de un caso.',
        );

        $fila = $modelo->equipo()->whereKey($miembro)->firstOrFail();

        $fila->update(['hasta' => now()->toDateString()]);

        return back(303)->with('exito', 'Se retiró del equipo. Su participación queda registrada.');
    }

    /** Registrar una intervención. */
    public function intervenir(Request $peticion, int $caso): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        $datos = $peticion->validate([
            'tipo_intervencion_id' => ['required', 'integer',
                Rule::exists('tipos_intervencion', 'id')->whereNull('deleted_at')],
            /*
             * Sin `before_or_equal:today`: si la fecha cuadra depende de si la
             * intervención se hizo o se agendó, y eso lo decide el SERVICIO. La
             * regla aquí impedía agendar nada, que es lo único para lo que el
             * estado `programada` sirve.
             */
            'fecha' => ['required', 'date'],
            'objetivo' => ['nullable', 'string', 'max:2000'],
            'canal' => ['nullable', 'string', 'max:40'],
            'participantes' => ['nullable', 'array', 'max:20'],
            'participantes.*' => ['string', 'max:120'],
            'acuerdos' => ['nullable', 'string', 'max:4000'],
            'proxima_fecha' => ['nullable', 'date'],
            'resultado' => ['nullable', 'string', 'max:4000'],
            'estado' => ['nullable', Rule::in(Intervencion::ESTADOS)],
            'visibilidad' => ['nullable', Rule::in(Intervencion::VISIBILIDADES)],
        ], [], ['tipo_intervencion_id' => 'tipo']);

        $this->intervenciones->registrar($modelo, $datos, $peticion->user());

        return back(303)->with('exito', 'Se registró.');
    }

    /** Anotar una tarea del caso. */
    public function tarea(Request $peticion, int $caso): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        $this->exigirQuePuedaIntervenir($peticion);

        $datos = $peticion->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'responsable_id' => ['nullable', 'integer', Rule::exists('usuarios', 'id')],
            'vence_en' => ['nullable', 'date'],
        ], [], ['responsable_id' => 'responsable']);

        TareaCaso::create([
            'caso_id' => $modelo->id,
            'titulo' => $datos['titulo'],
            'responsable_id' => $datos['responsable_id'] ?? $peticion->user()?->id,
            'vence_en' => $datos['vence_en'] ?? null,
        ]);

        return back(303)->with('exito', 'Tarea anotada.');
    }

    /**
     * Dar una tarea por hecha.
     *
     * No se puede completar dos veces: la segunda reescribiría la fecha en que
     * de verdad se hizo.
     */
    public function completarTarea(Request $peticion, int $caso, int $tarea): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        $this->exigirQuePuedaIntervenir($peticion);

        $fila = $modelo->tareas()->whereKey($tarea)->firstOrFail();

        AvisoParaElUsuario::si($fila->completada_en !== null, 422, 'Esa tarea ya estaba dada por hecha.');

        $fila->update([
            'completada_en' => now(),
            'resultado' => $peticion->input('resultado'),
        ]);

        return back(303)->with('exito', 'Tarea completada.');
    }

    /**
     * El plan de intervención: qué se va a hacer.
     *
     * Es texto libre y no una lista de tareas porque las dos cosas hacen falta:
     * el plan explica el criterio —«bajó por la carga de trabajo, se le reacomoda
     * el horario»— y las tareas son los pasos. Sólo con tareas, dentro de un año
     * nadie sabría por qué se hizo lo que se hizo.
     */
    public function plan(Request $peticion, int $caso): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        $this->exigirQuePuedaIntervenir($peticion);

        AvisoParaElUsuario::si(
            $modelo->estado->esTerminal(),
            422,
            'El caso está cerrado: su plan es parte de lo que quedó registrado y ya no se reescribe.',
        );

        $datos = $peticion->validate(['plan_intervencion' => ['nullable', 'string', 'max:6000']]);

        $modelo->update(['plan_intervencion' => $datos['plan_intervencion'] ?? null]);

        return back(303)->with('exito', 'Se guardó el plan.');
    }

    /**
     * Reabrir: un caso NUEVO que apunta al cerrado.
     *
     * El cerrado se conserva entero. Reescribirlo borraría la medición de
     * recurrencia, que es de lo poco que dice si el acompañamiento funcionó.
     */
    public function reabrir(Request $peticion, int $caso): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $caso);

        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'motivo.required' => 'Reabrir exige decir por qué: es lo que explica que la situación volvió.',
            'motivo.min' => 'El motivo tiene que explicar algo: dentro de un año nadie recordará el contexto.',
        ]);

        $nuevo = $this->abridor->reabrir($modelo, $datos['motivo'], $peticion->user(), $peticion->ip());

        return redirect()
            ->route('tenant.permanencia.casos.detalle', $nuevo->id)
            ->with('exito', 'Se abrió el caso '.$nuevo->folio.'. El anterior queda como estaba.');
    }

    /**
     * Quién puede llevar un caso o entrar a su equipo.
     *
     * ── Endpoint propio, y con su razón ────────────────────────────────────
     * `/buscar/alumnos` y `/buscar/matriculas` contestan por ALUMNOS; aquí hace
     * falta PERSONAL. Y uno solo para las dos preguntas —responsable y equipo—
     * porque el conjunto es el mismo: cada renglón trae su `id` de usuario y su
     * `persona_id`, que es lo que cada lado necesita.
     *
     * ── Sólo quien tiene CUENTA ────────────────────────────────────────────
     * Un caso se lleva entrando al sistema. Ofrecer a alguien sin cuenta sería
     * dejar asignarlo a quien nunca va a poder abrirlo, y la lista diría que el
     * caso tiene dueño.
     */
    public function buscarPersonal(Request $peticion)
    {
        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('asignar-casos') === true,
            403,
            'Tu rol no puede asignar casos.',
        );

        $texto = trim((string) $peticion->query('q', ''));

        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Usuario::query()
                ->whereHas('persona', fn ($p) => $p
                    ->whereRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) like ?",
                        ['%'.$texto.'%']))
                ->with('persona:id,nombre,primer_apellido,segundo_apellido,email')
                ->limit(15)
                ->get()
                ->map(fn (Usuario $u) => [
                    'id' => $u->id,
                    'persona_id' => $u->persona_id,
                    'nombre' => $u->persona?->nombreCompleto(),
                    'correo' => $u->persona?->email,
                ])
                ->values(),
        );
    }

    /**
     * La consulta base: los casos que este usuario alcanza.
     *
     * En UN solo sitio, porque la usan el listado, la ficha, las acciones y el
     * resumen. Repartida, la que se olvide del campus enseña los casos de otro
     * plantel — y con un caso eso es la situación personal de alguien.
     */
    private function base(Request $peticion): Builder
    {
        return $this->alcance->acotar(CasoPermanencia::query(), $peticion->user());
    }

    /** El caso, o 404. Nunca 403: revelaría que existe. */
    private function alcanzable(Request $peticion, int $id): CasoPermanencia
    {
        return $this->base($peticion)
            ->with(['matricula.persona', 'matricula.oferta.campus',
                'matricula.oferta.programaAcademico', 'campus', 'ciclo',
                'responsable.persona', 'abiertoPor.persona', 'nivelApertura',
                'motivoCierre', 'origen:id,folio'])
            ->findOrFail($id);
    }

    /**
     * La alerta desde la que se abre, acotada por el campus de SU matrícula.
     *
     * Se comprueba aquí y no en el abridor porque el recorte de las alertas va
     * por la oferta de la matrícula y el de los casos por su columna copiada: son
     * dos preguntas parecidas y distintas, y confundirlas dejaría abrir un caso
     * desde la señal de otro plantel.
     */
    private function alertaAlcanzable(Request $peticion, int $id): Alerta
    {
        return $this->acotarMatriculas(Alerta::query(), $peticion, 'matricula')
            ->with('matricula.oferta', 'regla')
            ->findOrFail($id);
    }

    private function exigirQuePuedaIntervenir(Request $peticion): void
    {
        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('registrar-intervenciones') === true,
            403,
            'Tu rol puede ver el caso pero no registrar lo que se hace en él.',
        );
    }

    private function aplicarFiltros(Builder $consulta, Request $peticion): void
    {
        $consulta
            ->when($peticion->filled('prioridad'),
                fn ($q) => $q->where('prioridad', $peticion->input('prioridad')))
            ->when($peticion->filled('campus_id'),
                fn ($q) => $q->where('campus_id', (int) $peticion->input('campus_id')))
            ->when($peticion->filled('responsable_id'),
                fn ($q) => $q->where('responsable_id', (int) $peticion->input('responsable_id')))
            ->when($peticion->boolean('sla'), fn ($q) => $q->slaVencido())
            ->when($peticion->boolean('sin_asignar'), fn ($q) => $q->sinAsignar())
            ->when($peticion->filled('busqueda'), function ($q) use ($peticion) {
                $texto = trim((string) $peticion->input('busqueda'));

                $q->where(fn (Builder $w) => $w
                    ->where('folio', 'like', '%'.$texto.'%')
                    ->orWhereHas('matricula', fn (Builder $m) => $m
                        ->where('matricula', 'like', '%'.$texto.'%')
                        ->orWhereHas('persona', fn ($p) => $p
                            ->whereRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) like ?",
                                ['%'.$texto.'%']))));
            });

        /*
         * Por omisión, lo ABIERTO. Es una cola de trabajo, y abrirla con el
         * histórico dentro haría que lo de hoy se perdiera entre lo cerrado hace
         * tres meses. El filtro se puede soltar.
         */
        $peticion->filled('estado')
            ? $consulta->where('estado', $peticion->input('estado'))
            : $consulta->abiertos();
    }

    /**
     * Las cifras de arriba, YA acotadas por campus.
     *
     * Un resumen sin recortar pondría el número de la escuela entera encima de
     * una lista acotada a un plantel — el defecto que el motor de reportes ya
     * documentó con los totales.
     *
     * @return array<string, mixed>
     */
    private function resumen(Request $peticion): array
    {
        $abiertos = $this->base($peticion)->abiertos();

        return [
            'abiertos' => (clone $abiertos)->count(),
            'sin_asignar' => $this->base($peticion)->sinAsignar()->count(),
            'sla_vencido' => $this->base($peticion)->slaVencido()->count(),
            'por_estado' => (clone $abiertos)->selectRaw('estado, count(*) as c')
                ->groupBy('estado')->pluck('c', 'estado'),
            /*
             * Cerrados en 30 días: es lo que dice si la cola se mueve. Sin él,
             * «hay 40 abiertos» no distingue una escuela que atiende 20 al mes
             * de una que no cierra ninguno.
             */
            'cerrados_30_dias' => $this->base($peticion)
                ->where('estado', EstadoCaso::Cerrado->value)
                ->where('cerrado_en', '>=', now()->subDays(30))
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogos(Request $peticion): array
    {
        $campus = $this->alcanceCampus($peticion);

        return [
            'estados' => array_map(fn (EstadoCaso $e) => [
                'valor' => $e->value,
                'etiqueta' => $e->etiqueta(),
                'color' => $e->color(),
            ], EstadoCaso::cases()),
            'prioridades' => CasoPermanencia::PRIORIDADES,
            'campus' => Campus::query()
                ->when($campus !== null, fn ($q) => $q->whereIn('id', $campus))
                ->orderBy('nombre')->get(['id', 'nombre']),
            /*
             * Sólo quien YA lleva algún caso que este usuario alcanza. Ofrecer
             * las trescientas cuentas de la escuela llenaría el desplegable de
             * gente que no filtra nada.
             */
            'responsables' => Usuario::query()
                ->whereIn('id', $this->base($peticion)->whereNotNull('responsable_id')->select('responsable_id'))
                ->with('persona:id,nombre,primer_apellido,segundo_apellido')
                ->get()
                ->map(fn (Usuario $u) => ['id' => $u->id, 'nombre' => $u->persona?->nombreCompleto()])
                ->sortBy('nombre')->values(),
        ];
    }

    /**
     * Qué puede hacer esta persona. Resuelto en el SERVIDOR y una sola vez.
     *
     * La pantalla dibuja botones con esto; que además cada acción lo vuelva a
     * comprobar es lo que hace que esconder el botón no sea la defensa.
     *
     * @return array<string, bool>
     */
    private function permisosDe(?Usuario $usuario): array
    {
        return [
            'abrir' => $usuario?->can('abrir-casos') === true,
            'asignar' => $usuario?->can('asignar-casos') === true,
            'intervenir' => $usuario?->can('registrar-intervenciones') === true,
            'reservadas' => $usuario?->can('ver-notas-reservadas') === true,
            'escalar' => $usuario?->can('escalar-casos') === true,
            'cerrar' => $usuario?->can('cerrar-casos') === true,
        ];
    }

    /** @return array<string, mixed> */
    private function paraLaLista(CasoPermanencia $caso): array
    {
        return [
            'id' => $caso->id,
            'folio' => $caso->folio,
            'alumno' => $caso->matricula?->persona?->nombreCompleto(),
            'matricula' => $caso->matricula?->matricula,
            'programa' => $caso->matricula?->oferta?->programaAcademico?->nombre,
            'campus' => $caso->campus?->nombre,
            'estado' => $caso->estado->value,
            'estado_etiqueta' => $caso->estado->etiqueta(),
            'estado_color' => $caso->estado->color(),
            'prioridad' => $caso->prioridad,
            'responsable' => $caso->responsable?->persona?->nombreCompleto(),
            'abierto_en' => $caso->abierto_en?->toDateString(),
            'dias_abierto' => (int) $caso->abierto_en?->startOfDay()
                ->diffInDays(now()->startOfDay(), absolute: true),
            'sla_vence_en' => $caso->sla_vence_en?->format('Y-m-d H:i'),
            'sla_vencido' => $caso->slaVencido(),
            'primer_contacto_en' => $caso->primer_contacto_en?->format('Y-m-d H:i'),
            'horas_primer_contacto' => $caso->horasHastaElPrimerContacto(),
            /*
             * Y la MISMA cifra en palabras. «a las 192 h» no lo lee nadie: el
             * indicador existe para verse de un vistazo, y pasadas dos jornadas
             * lo que se piensa son días. Se arma en el servidor porque lo leen
             * la lista y la ficha, y escrito dos veces una diría otra cosa.
             */
            'tardanza_primer_contacto' => $this->enPalabras($caso->horasHastaElPrimerContacto()),
            'nivel_apertura' => $caso->nivelApertura?->nombre,
            'nivel_color' => $caso->nivelApertura?->color,
            'intervenciones' => $caso->intervenciones_count ?? null,
            'tareas_pendientes' => $caso->tareas_pendientes ?? null,
        ];
    }

    /**
     * Una intervención, ya filtrada, lista para la pantalla.
     *
     * Viaja `visibilidad` porque la ficha la SEÑALA: una nota de equipo o
     * reservada tiene que verse distinta de las demás, o quien la escribe no
     * sabrá si de verdad quedó restringida — y eso es lo que hace que la marque
     * mal la próxima vez.
     *
     * @return array<string, mixed>
     */
    private function intervencion(Intervencion $i): array
    {
        return [
            'id' => $i->id,
            'tipo' => $i->tipo?->nombre,
            'objetivo' => $i->objetivo,
            'responsable' => $i->responsable?->persona?->nombreCompleto(),
            'fecha' => $i->fecha?->toDateString(),
            'canal' => $i->canal,
            'participantes' => $i->participantes,
            'acuerdos' => $i->acuerdos,
            'proxima_fecha' => $i->proxima_fecha?->toDateString(),
            'resultado' => $i->resultado,
            'estado' => $i->estado,
            'visibilidad' => $i->visibilidad,
            'evidencia' => $i->evidencia_nombre,
        ];
    }

    /**
     * Un número de horas, leíble.
     *
     * Menos de un día en horas, y de ahí en adelante en días: «a las 192 h»
     * obliga a dividir de cabeza, y quien mira la cola no lo hace — lee el
     * número, no lo entiende, y deja de mirarlo.
     */
    private function enPalabras(?int $horas): ?string
    {
        if ($horas === null) {
            return null;
        }

        if ($horas < 24) {
            return $horas.' h';
        }

        $dias = intdiv($horas, 24);

        return $dias.' día'.($dias === 1 ? '' : 's');
    }

    /** @return array<string, mixed> */
    private function paraLaFicha(CasoPermanencia $caso): array
    {
        return array_merge($this->paraLaLista($caso), [
            'ciclo' => $caso->ciclo?->nombre,
            'plan_intervencion' => $caso->plan_intervencion,
            'puntaje_apertura' => $caso->puntaje_apertura,
            'abierto_por' => $caso->abiertoPor?->persona?->nombreCompleto(),
            'cerrado_en' => $caso->cerrado_en?->format('Y-m-d H:i'),
            'motivo_cierre' => $caso->motivoCierre?->nombre,
            'cuenta_como_exito' => $caso->motivoCierre?->cuenta_como_exito,
            'resultado' => $caso->resultado,
            'terminal' => $caso->estado->esTerminal(),
            'origen' => $caso->origen === null ? null
                : ['id' => $caso->origen->id, 'folio' => $caso->origen->folio],
        ]);
    }
}
