<?php

declare(strict_types=1);

namespace App\Http\Controllers\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\CorridaEvaluacion;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\NivelRiesgo;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\Permanencia\RiesgoMatricula;
use App\Permanencia\RegistroProveedores;
use App\Services\Permanencia\AbridorDeCaso;
use App\Services\Permanencia\CalculadoraDeRiesgo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La bandeja de alertas: lo que el motor levantó y espera que alguien mire.
 *
 * ── CUATRO capas de visibilidad, y ninguna sobra ───────────────────────────
 *  1. El MÓDULO (`permanencia`): apagado, 404 y sin menú.
 *  2. El PERMISO: `ver-alertas` para entrar, `validar-alertas` para tocar.
 *  3. El CAMPUS: sobre el campus de la OFERTA de la matrícula, aplicado en el
 *     listado, en el detalle y en CADA acción. El id viaja por la URL, así que
 *     filtrar la lista no es una defensa —lección que este proyecto ya pagó—.
 *  4. La CATEGORÍA: `categorias_senal.sensible` decide si el detalle viaja. Se
 *     resuelve en `Alerta::comoLaVe()`, en un solo sitio: escrito en cada
 *     pantalla, la que se olvide enseña el monto de una deuda a un docente.
 *
 * ── Lo ajeno responde 404, no 403 ──────────────────────────────────────────
 * Un 403 confirmaría que esa alerta existe, y con ids consecutivos eso deja
 * enumerar quién tiene señales en los demás planteles. Mismo criterio que la
 * rúbrica ajena y la credencial de otro.
 *
 * ── Y esta pantalla NO ejecuta el motor ────────────────────────────────────
 * Correr la evaluación desde una petición web recorrería la escuela entera
 * mientras alguien espera. Se dice cuándo fue la última corrida y cuánto tardó;
 * recalcular es un acto aparte, con su permiso, y llega en la fase que lo
 * necesite.
 */
class AlertaController extends Controller
{
    use AcotaPorCampus;

    /** Cuántas caben en una página. Pública para poder comprobar el tope. */
    public const POR_PAGINA = 30;

    public function index(Request $peticion): Response
    {
        $consulta = $this->base($peticion)
            ->with(['matricula:id,persona_id,matricula,oferta_id',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'matricula.oferta:id,campus_id,programa_academico_id',
                'matricula.oferta.campus:id,nombre',
                'matricula.oferta.programaAcademico:id,nombre',
                'categoria', 'regla:id,nombre', 'version',
                'asignaturaGrupo:id,plan_materia_id',
                'asignaturaGrupo.planMateria:id,asignatura_id',
                'asignaturaGrupo.planMateria.asignatura:id,nombre']);

        $this->aplicarFiltros($consulta, $peticion);

        $pagina = $consulta
            /*
             * Lo más GRAVE primero y, dentro, lo más VIEJO: una cola ordenada
             * por fecha deja las críticas debajo de veinte informativas, y una
             * ordenada sólo por gravedad hace que lo de hace tres semanas nunca
             * se toque.
             */
            ->orderByRaw("field(severidad, 'critico', 'alto', 'medio', 'bajo', 'informativo')")
            ->orderBy('primera_vez_en')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        $usuario = $peticion->user();

        return Inertia::render('Permanencia/Alertas/Index', [
            'alertas' => $pagina->through(fn (Alerta $a) => $this->paraLaLista($a, $usuario)),
            'resumen' => $this->resumen($peticion),
            'catalogos' => $this->catalogos($peticion),
            'filtros' => $peticion->only(['categoria_id', 'severidad', 'estado_triage',
                'estado_senal', 'campus_id', 'regla_id', 'busqueda']),
            'ultimaCorrida' => $this->ultimaCorrida(),
            'puedeValidar' => $usuario?->can('validar-alertas') === true,
        ]);
    }

    public function show(Request $peticion, int $alerta): Response
    {
        $modelo = $this->alcanzable($peticion, $alerta);

        $usuario = $peticion->user();

        return Inertia::render('Permanencia/Alertas/Detalle', [
            'alerta' => array_merge(
                $this->paraLaLista($modelo, $usuario),
                $modelo->comoLaVe($usuario),
                [
                    'ventana' => [
                        'desde' => $modelo->ventana_desde?->toDateString(),
                        'hasta' => $modelo->ventana_hasta?->toDateString(),
                    ],
                    'ultima_evaluacion_en' => $modelo->ultima_evaluacion_en?->toDateTimeString(),
                    'cerrada_en' => $modelo->cerrada_en?->toDateTimeString(),
                    'nota_triage' => $modelo->nota_triage,
                    'motivo_descarte' => $modelo->motivoDescarte?->nombre,
                    'revisada_por' => $modelo->revisadaPor?->persona?->nombreCompleto(),
                    'revisada_en' => $modelo->revisada_en?->toDateTimeString(),
                    /*
                     * La CALIDAD de la fuente viaja con la alerta, y no es
                     * adorno: «se calcula sobre las sesiones registradas, no
                     * sobre el calendario» es lo que evita que alguien lea un
                     * 60 % como si fuera del semestre entero.
                     */
                    'calidad' => app(RegistroProveedores::class)
                        ->de($modelo->regla?->proveedor ?? '')?->calidad(),
                ],
            ),
            'motivos' => MotivoDescarte::query()->activos()->get(['id', 'nombre', 'descripcion']),
            /*
             * El riesgo COMPUESTO de esta persona, con su desglose.
             *
             * Va en la ficha de la señal y no sólo en un tablero, porque es
             * donde se decide: validar una alerta de asistencia sabiendo que
             * además arrastra dos frentes más no es la misma decisión que
             * validarla a secas.
             */
            'riesgo' => $this->riesgoDe($modelo),
            'niveles' => NivelRiesgo::query()->activos()->get(['id', 'clave', 'nombre', 'color', 'descripcion']),
            'puedeValidar' => $usuario?->can('validar-alertas') === true,
            /*
             * Las OTRAS señales abiertas del mismo alumno. Es lo que convierte
             * una alerta suelta en una conversación: quien la mira necesita
             * saber que además tiene dos materias reprobadas, y sin esto tendría
             * que volver a la bandeja y filtrar a mano.
             */
            'otras' => $this->otrasDelAlumno($peticion, $modelo, $usuario),
            /*
             * DOS datos y no uno: el caso de esta SEÑAL y el que la persona
             * tiene ABIERTO hoy. Pueden no ser el mismo —la señal se atendió,
             * el caso se cerró, y meses después se abrió otro—, y con un solo
             * dato la pantalla decía «se está atendiendo» sobre un caso cerrado
             * mientras escondía el que sí está vivo. Se vio MIRÁNDOLO.
             *
             * Hacen falta los dos porque abrir sobre quien ya tiene caso no crea
             * un segundo: le SUMA la señal. Prometer «se abrirá un caso» sería
             * mentir sobre lo que va a pasar.
             */
            'casoDeLaSenal' => $this->comoSeLee(
                CasoPermanencia::query()
                    ->whereHas('alertas', fn (Builder $a) => $a->whereKey($modelo->id))
                    ->first()
            ),
            'casoAbierto' => $this->comoSeLee(
                CasoPermanencia::query()->abiertos()
                    ->where('matricula_oferta_id', $modelo->matricula_oferta_id)
                    ->first()
            ),
            'puedeAbrirCaso' => $usuario?->can('abrir-casos') === true,
            'prioridadSugerida' => app(AbridorDeCaso::class)->prioridadPara($modelo),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function comoSeLee(?CasoPermanencia $caso): ?array
    {
        return $caso === null ? null : [
            'id' => $caso->id,
            'folio' => $caso->folio,
            'estado' => $caso->estado->etiqueta(),
            'abierto' => ! $caso->estado->esTerminal(),
        ];
    }

    /**
     * Validar: esta señal amerita seguimiento.
     *
     * NO abre el caso —eso llega en la fase 5 con su permiso—: aquí sólo se dice
     * que alguien la miró y la dio por buena. Separarlo permite que la cola se
     * limpie hoy aunque el módulo de casos no exista todavía.
     */
    public function validar(Request $peticion, int $alerta): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $alerta);

        $this->exigirQuePuedaValidar($peticion);
        $this->exigirQueSigaPorRevisar($modelo);

        $modelo->update([
            'estado_triage' => Alerta::VALIDADA,
            'nota_triage' => $peticion->input('nota'),
            'revisada_por' => $peticion->user()?->id,
            'revisada_en' => now(),
        ]);

        return back(303)->with('exito', 'Se validó. Queda como señal que amerita seguimiento.');
    }

    /**
     * Descartar, CON motivo.
     *
     * El motivo es obligatorio y sale del catálogo, no de un texto libre: es lo
     * que permite medir la tasa de falsos positivos POR REGLA, que es la única
     * señal temprana de que una regla está mal calibrada. Con texto libre habría
     * que leer trescientas frases para saberlo.
     */
    public function descartar(Request $peticion, int $alerta): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $alerta);

        $this->exigirQuePuedaValidar($peticion);
        $this->exigirQueSigaPorRevisar($modelo);

        $datos = $peticion->validate([
            'motivo_descarte_id' => ['required', 'integer',
                Rule::exists('motivos_descarte', 'id')->whereNull('deleted_at')],
            'nota' => ['nullable', 'string', 'max:1000'],
        ], [], ['motivo_descarte_id' => 'motivo']);

        $modelo->update([
            'estado_triage' => Alerta::DESCARTADA,
            'motivo_descarte_id' => $datos['motivo_descarte_id'],
            'nota_triage' => $datos['nota'] ?? null,
            'revisada_por' => $peticion->user()?->id,
            'revisada_en' => now(),
        ]);

        return back(303)->with('exito',
            'Se descartó. No volverá a levantarse mientras dure el enfriamiento de su regla.');
    }

    /**
     * Descartar VARIAS de una vez.
     *
     * Hace falta de verdad: una regla mal calibrada levanta cuarenta alertas la
     * misma madrugada, y descartarlas de una en una es lo que hace que nadie las
     * descarte y la cola se vuelva ruido.
     *
     * ── Y el alcance se comprueba UNA POR UNA ──────────────────────────────
     * La lista de ids llega del navegador y no es fuente de verdad. Se filtra
     * por la MISMA consulta base —campus incluido— y se dice cuántas quedaron
     * fuera: en silencio, quien pulsa creería que descartó las cuarenta.
     */
    public function descartarVarias(Request $peticion): RedirectResponse
    {
        $this->exigirQuePuedaValidar($peticion);

        $datos = $peticion->validate([
            'alertas' => ['required', 'array', 'min:1', 'max:200'],
            'alertas.*' => ['integer'],
            'motivo_descarte_id' => ['required', 'integer',
                Rule::exists('motivos_descarte', 'id')->whereNull('deleted_at')],
            'nota' => ['nullable', 'string', 'max:1000'],
        ], [], ['motivo_descarte_id' => 'motivo']);

        $alcanzables = $this->base($peticion)
            ->whereIn('id', $datos['alertas'])
            ->where('estado_triage', Alerta::NUEVA)
            ->abiertas()
            ->pluck('id');

        $descartadas = Alerta::query()->whereIn('id', $alcanzables)->update([
            'estado_triage' => Alerta::DESCARTADA,
            'motivo_descarte_id' => $datos['motivo_descarte_id'],
            'nota_triage' => $datos['nota'] ?? null,
            'revisada_por' => $peticion->user()?->id,
            'revisada_en' => now(),
        ]);

        $fuera = count($datos['alertas']) - $descartadas;

        return back(303)->with('exito', $fuera === 0
            ? 'Se descartaron '.$descartadas.'.'
            : 'Se descartaron '.$descartadas.'. Las otras '.$fuera.' no se pudieron: '
                .'o ya las había revisado alguien, o son de un campus que no alcanzas.');
    }

    /**
     * Ajustar el nivel de riesgo a mano, con justificación.
     *
     * ── Sin permiso propio, y con su razón ─────────────────────────────────
     * Va con `validar-alertas` porque es el mismo oficio: decidir sobre las
     * señales de un alumno. Un permiso más sin un acto que proteger es una llave
     * que la escuela tiene que repartir sin saber para qué —este proyecto ya
     * retiró dos así— y quien puede descartar todas las señales de alguien ya
     * puede, de hecho, bajarle el riesgo.
     *
     * El alcance por campus se comprueba abriendo la ALERTA, que es de donde se
     * llega: sin eso, el id de una matrícula de otro plantel entraría por aquí.
     */
    public function ajustarRiesgo(Request $peticion, int $alerta): RedirectResponse
    {
        $modelo = $this->alcanzable($peticion, $alerta);

        $datos = $peticion->validate([
            'nivel_id' => ['required', 'integer',
                Rule::exists('niveles_riesgo', 'id')->whereNull('deleted_at')],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motivo.required' => 'Ajustar el nivel exige decir por qué.',
            'motivo.min' => 'El motivo tiene que explicar algo: dentro de un año nadie recordará el contexto.',
        ]);

        app(CalculadoraDeRiesgo::class)->ajustar(
            $modelo->matricula,
            NivelRiesgo::findOrFail($datos['nivel_id']),
            $datos['motivo'],
            $peticion->user(),
        );

        return back(303)->with('exito',
            'Se ajustó el nivel. El calculado se conserva al lado, con tu motivo.');
    }

    /**
     * El riesgo vigente de la persona de esta alerta.
     *
     * @return array<string, mixed>|null
     */
    private function riesgoDe(Alerta $alerta): ?array
    {
        $riesgo = RiesgoMatricula::query()
            ->vigenteDe($alerta->matricula_oferta_id)
            ->with('nivel', 'nivelAnterior', 'nivelAjustado', 'ajustadoPor.persona')
            ->first();

        return $riesgo?->comoSeLee();
    }

    /**
     * La consulta base: lo que este usuario puede ver.
     *
     * Aquí y en un solo sitio, porque la usan el listado, el detalle, las
     * acciones y el resumen. Repartida, la que se olvide del campus enseña las
     * alertas de otro plantel.
     */
    private function base(Request $peticion): Builder
    {
        return $this->acotarMatriculas(Alerta::query(), $peticion, 'matricula');
    }

    /** La alerta, o 404. Nunca 403: revelaría que existe. */
    private function alcanzable(Request $peticion, int $id): Alerta
    {
        return $this->base($peticion)
            ->with(['matricula.persona', 'matricula.oferta.campus',
                'matricula.oferta.programaAcademico', 'categoria', 'regla', 'version',
                'motivoDescarte', 'revisadaPor.persona',
                'asignaturaGrupo.planMateria.asignatura'])
            ->findOrFail($id);
    }

    private function exigirQuePuedaValidar(Request $peticion): void
    {
        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('validar-alertas') === true,
            403,
            'Tu rol puede ver la bandeja pero no decidir sobre las señales.',
        );
    }

    /**
     * Sólo se revisa lo que sigue por revisar.
     *
     * Dos personas con la pantalla abierta pulsan las dos; sin esto, la segunda
     * borraría del acta a quien decidió primero. Es el mismo criterio que la
     * firma de las becas y la doble revisión de una jornada.
     */
    private function exigirQueSigaPorRevisar(Alerta $alerta): void
    {
        AvisoParaElUsuario::aMenosQue(
            $alerta->estado_triage === Alerta::NUEVA,
            422,
            $alerta->estado_triage === Alerta::VALIDADA
                ? 'Alguien ya la validó.'
                : 'Alguien ya la descartó.',
        );
    }

    private function aplicarFiltros(Builder $consulta, Request $peticion): void
    {
        $consulta
            ->when($peticion->filled('categoria_id'),
                fn ($q) => $q->where('categoria_id', (int) $peticion->input('categoria_id')))
            ->when($peticion->filled('severidad'),
                fn ($q) => $q->where('severidad', $peticion->input('severidad')))
            ->when($peticion->filled('regla_id'),
                fn ($q) => $q->where('regla_id', (int) $peticion->input('regla_id')))
            ->when($peticion->filled('campus_id'),
                fn ($q) => $q->whereHas('matricula.oferta',
                    fn ($o) => $o->where('campus_id', (int) $peticion->input('campus_id'))))
            ->when($peticion->filled('busqueda'), function ($q) use ($peticion) {
                $texto = trim((string) $peticion->input('busqueda'));

                $q->whereHas('matricula', fn ($m) => $m
                    ->where('matricula', 'like', '%'.$texto.'%')
                    ->orWhereHas('persona', fn ($p) => $p
                        ->whereRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) like ?",
                            ['%'.$texto.'%'])));
            });

        /*
         * Por omisión: lo ABIERTO y POR REVISAR. Es una cola de trabajo, y
         * abrirla con todo el histórico dentro haría que lo de hoy se perdiera
         * entre lo de hace tres meses. Los dos filtros se pueden soltar.
         */
        $peticion->filled('estado_senal')
            ? $consulta->where('estado_senal', $peticion->input('estado_senal'))
            : $consulta->abiertas();

        $peticion->filled('estado_triage')
            && $consulta->where('estado_triage', $peticion->input('estado_triage'));

        $peticion->filled('estado_senal') || $peticion->filled('estado_triage')
            || $consulta->where('estado_triage', Alerta::NUEVA);
    }

    /**
     * Las cifras de arriba, YA acotadas por campus.
     *
     * Un resumen sin recortar filtraría el número de la escuela entera encima de
     * una lista acotada a un plantel, que es el defecto que el motor de reportes
     * documentó con los totales.
     *
     * @return array<string, mixed>
     */
    private function resumen(Request $peticion): array
    {
        $abiertas = $this->base($peticion)->abiertas();

        return [
            'por_revisar' => (clone $abiertas)->where('estado_triage', Alerta::NUEVA)->count(),
            'validadas' => (clone $abiertas)->where('estado_triage', Alerta::VALIDADA)->count(),
            'por_severidad' => (clone $abiertas)
                ->where('estado_triage', Alerta::NUEVA)
                ->selectRaw('severidad, count(*) as c')
                ->groupBy('severidad')
                ->pluck('c', 'severidad'),
            'por_categoria' => (clone $abiertas)
                ->where('estado_triage', Alerta::NUEVA)
                ->selectRaw('categoria_id, count(*) as c')
                ->groupBy('categoria_id')
                ->pluck('c', 'categoria_id'),
            /*
             * Descartadas en 30 días: es la señal de calibración. Una cola que
             * se descarta entera no es una cola: es ruido, y el número tiene que
             * estar a la vista de quien la mira todos los días.
             */
            'descartadas_30_dias' => $this->base($peticion)
                ->where('estado_triage', Alerta::DESCARTADA)
                ->where('revisada_en', '>=', now()->subDays(30))
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogos(Request $peticion): array
    {
        $campus = $this->alcanceCampus($peticion);

        return [
            'categorias' => CategoriaSenal::query()->activas()->get(['id', 'clave', 'nombre', 'color', 'sensible']),
            'severidades' => ReglaAlertaVersion::SEVERIDADES,
            'campus' => Campus::query()
                ->when($campus !== null, fn ($q) => $q->whereIn('id', $campus))
                ->orderBy('nombre')->get(['id', 'nombre']),
            /*
             * Sólo las reglas que HAN levantado algo que este usuario alcanza.
             * Ofrecer las 40 del catálogo llenaría el desplegable de reglas que
             * no filtran nada, y el filtro dejaría de servir.
             */
            'reglas' => ReglaAlerta::query()
                ->whereIn('id', $this->base($peticion)->select('regla_id'))
                ->orderBy('nombre')->get(['id', 'nombre']),
            'motivos' => MotivoDescarte::query()->activos()->get(['id', 'nombre', 'descripcion']),
        ];
    }

    /**
     * Cuándo corrió el motor por última vez.
     *
     * Va arriba de la bandeja, y no es un adorno: una cola vacía significa cosas
     * distintas si el motor corrió esta madrugada o si lleva nueve días sin
     * correr. Sin este dato, «no hay alertas» se lee como «no hay riesgo».
     *
     * @return array<string, mixed>|null
     */
    private function ultimaCorrida(): ?array
    {
        $corrida = CorridaEvaluacion::query()->latest('iniciada_en')->first();

        if ($corrida === null) {
            return null;
        }

        return [
            'cuando' => $corrida->iniciada_en?->toDateTimeString(),
            'hace_dias' => (int) now()->startOfDay()->diffInDays($corrida->iniciada_en?->startOfDay(), absolute: true),
            'alumnos' => $corrida->matriculas_evaluadas,
            'reglas' => $corrida->reglas_evaluadas,
            'sin_datos' => $corrida->sin_datos,
            'con_errores' => $corrida->huboErrores(),
        ];
    }

    /**
     * Las otras señales abiertas de la misma PERSONA.
     *
     * ── De la persona y no de la matrícula, y eso importa ───────────────────
     * Quien estudia dos programas tiene dos trayectorias y puede tener señales
     * en las dos; para decidir con el panorama completo hacen falta las dos. La
     * primera versión miraba sólo la matrícula, que es lo que el encabezado NO
     * promete.
     *
     * ── Y por eso el recorte por campus deja de ser redundante ──────────────
     * Con una sola matrícula el campus era siempre el mismo y el filtro no hacía
     * nada —el barrido de mutaciones lo enseñó, sobreviviendo—. Mirando a la
     * persona, sus dos programas pueden estar en planteles distintos, y sin el
     * recorte esta ficha sería una puerta lateral a las señales del otro.
     *
     * @return array<int, array<string, mixed>>
     */
    private function otrasDelAlumno(Request $peticion, Alerta $alerta, $usuario): array
    {
        $persona = $alerta->matricula?->persona_id;

        if ($persona === null) {
            return [];
        }

        return $this->base($peticion)
            ->abiertas()
            ->whereHas('matricula', fn (Builder $m) => $m->where('persona_id', $persona))
            ->whereKeyNot($alerta->id)
            ->with('categoria', 'regla:id,nombre', 'version', 'matricula:id,matricula')
            ->get()
            ->map(fn (Alerta $a) => $a->comoLaVe($usuario)
                + ['matricula' => $a->matricula?->matricula])
            ->all();
    }

    /** @return array<string, mixed> */
    private function paraLaLista(Alerta $alerta, $usuario): array
    {
        $materia = $alerta->asignaturaGrupo?->planMateria?->asignatura?->nombre;

        return $alerta->comoLaVe($usuario) + [
            'alumno' => $alerta->matricula?->persona?->nombreCompleto(),
            'matricula' => $alerta->matricula?->matricula,
            'matricula_id' => $alerta->matricula_oferta_id,
            'campus' => $alerta->matricula?->oferta?->campus?->nombre,
            'programa' => $alerta->matricula?->oferta?->programaAcademico?->nombre,
            'materia' => $materia,
            'ultima_evaluacion_en' => $alerta->ultima_evaluacion_en?->toDateTimeString(),
        ];
    }
}
