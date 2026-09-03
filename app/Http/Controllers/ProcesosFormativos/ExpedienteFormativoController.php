<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExcepcionExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\ModalidadProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\PlazaProceso;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Services\ProcesosFormativos\AlcanceDeExpedientes;
use App\Services\ProcesosFormativos\AsignadorDePlaza;
use App\Services\ProcesosFormativos\TransicionDeExpediente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La bandeja de solicitudes y el expediente de cada alumno.
 *
 * ── Todo movimiento pasa por el SERVICIO ──────────────────────────────────
 * Aquí no hay un solo `update(['estado' => …])`. El controlador valida la
 * FORMA de lo que llega y {@see TransicionDeExpediente} decide si el
 * movimiento se puede hacer, con qué permiso y sobre qué alcance. Repartido, el
 * método que se olvide de una de las cinco cosas no falla: sólo deja un
 * expediente movido sin rastro.
 *
 * ── El alcance se comprueba DOS veces, y no sobra ─────────────────────────
 * Al listar y en cada acto. El id del expediente viaja por la URL, así que
 * acotar la lista nunca ha sido una defensa — es lo que `AcotaPorCampus` dice
 * en su propio docblock.
 */
class ExpedienteFormativoController extends Controller
{
    private const POR_PAGINA = 25;

    public function __construct(
        private readonly TransicionDeExpediente $transiciones,
        private readonly AsignadorDePlaza $asignador,
        private readonly AlcanceDeExpedientes $alcance,
    ) {}

    public function index(Request $peticion): Response
    {
        /** @var Usuario|null $quien */
        $quien = $peticion->user();

        $consulta = ExpedienteProceso::query()
            ->with([
                'matricula:id,persona_id,matricula,oferta_id',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'matricula.oferta:id,programa_academico_id,campus_id',
                'matricula.oferta.programaAcademico:id,nombre',
                'matricula.oferta.campus:id,nombre',
                'tipoProceso:id,nombre',
                'organizacion:id,razon_social,nombre_comercial',
            ]);

        $consulta = $this->alcance->acotar($consulta, $quien);

        /*
         * Por omisión se enseña la BANDEJA —lo que espera que alguien haga
         * algo—, no todo. Un listado que arranca con los seiscientos
         * expedientes históricos entierra los ocho que hay que atender hoy.
         */
        $estado = $peticion->string('estado')->toString();

        $estado === ''
            ? $consulta->enBandeja()
            : $consulta->where('estado', $estado);

        $consulta
            ->when($peticion->integer('tipo'), fn ($q, $t) => $q->where('tipo_proceso_id', $t))
            ->when($peticion->integer('campus'), fn ($q, $c) => $q->whereHas(
                'matricula.oferta',
                fn ($o) => $o->where('campus_id', $c),
            ))
            ->when($peticion->string('buscar')->toString(), fn ($q, $b) => $q->whereHas(
                'matricula',
                fn ($m) => $m->where('matricula', 'like', "%{$b}%")
                    ->orWhereHas('persona', fn ($p) => $p->where('nombre', 'like', "%{$b}%")
                        ->orWhere('primer_apellido', 'like', "%{$b}%")
                        ->orWhere('segundo_apellido', 'like', "%{$b}%")),
            ));

        $expedientes = $consulta
            ->orderByRaw("FIELD(estado, 'solicitado', 'en_revision', 'aprobado') DESC")
            ->orderBy('fecha_solicitud')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (ExpedienteProceso $e) => $this->paraLista($e));

        return Inertia::render('Procesos/Expedientes/Index', [
            'expedientes' => $expedientes,
            'filtros' => $peticion->only('estado', 'tipo', 'campus', 'buscar'),
            'estados' => EstadoExpediente::paraPantalla(),
            'catalogos' => [
                'tiposProceso' => TipoProcesoFormativo::query()->activos()->get(['id', 'nombre']),
                'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            ],
            'puedeRevisar' => $quien?->can('revisar-solicitudes-formativas') ?? false,
        ]);
    }

    public function show(Request $peticion, ExpedienteProceso $expediente): Response
    {
        /** @var Usuario|null $quien */
        $quien = $peticion->user();

        $this->alcance->exigirQueAlcance($expediente, $quien);

        $expediente->load([
            'matricula.persona',
            'matricula.oferta.programaAcademico:id,nombre',
            'matricula.oferta.plan:id,nombre',
            'matricula.oferta.campus:id,nombre',
            'tipoProceso',
            'reglaVersion.regla',
            'organizacion:id,razon_social,nombre_comercial',
            'plaza:id,nombre,cupo,cupo_ocupado',
            'modalidad:id,nombre',
            'supervisor:id,nombre,cargo,correo,telefono',
            'responsableInterno:id,nombre,primer_apellido,segundo_apellido',
            'documentos.documento:id,nombre',
            'documentos.estado:id,clave,nombre',
            'excepciones.autorizadaPor.persona:id,nombre,primer_apellido,segundo_apellido',
            'transiciones.usuario.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);

        return Inertia::render('Procesos/Expedientes/Detalle', [
            'expediente' => $this->paraDetalle($expediente),
            'catalogos' => [
                /*
                 * Sólo las organizaciones que RECIBEN y que alcanzan a este
                 * alumno. Ofrecer las demás sería invitar a un rechazo que el
                 * servidor produce después: el desplegable no es una defensa,
                 * pero tampoco debe empujar al error.
                 */
                'organizaciones' => OrganizacionReceptora::query()
                    ->queReciben()
                    ->with('alcances')
                    ->orderBy('razon_social')
                    ->get()
                    ->filter(fn (OrganizacionReceptora $o) => $o->alcanzaA(
                        $expediente->matricula?->oferta?->campus_id,
                        $expediente->matricula?->oferta?->programa_academico_id,
                        $expediente->tipo_proceso_id,
                    ))
                    ->map(fn (OrganizacionReceptora $o) => [
                        'id' => $o->id,
                        'nombre' => $o->comoSeLeConoce(),
                    ])
                    ->values(),
                'plazas' => PlazaProceso::query()
                    ->disponibles()
                    ->where('tipo_proceso_id', $expediente->tipo_proceso_id)
                    ->with('programasAcademicos:id')
                    ->get()
                    ->filter(fn (PlazaProceso $p) => $p->aceptaAlPrograma(
                        $expediente->matricula?->oferta?->programa_academico_id,
                    ))
                    ->map(fn (PlazaProceso $p) => [
                        'id' => $p->id,
                        'organizacion_id' => $p->organizacion_id,
                        'nombre' => $p->nombre.' ('.$p->lugaresLibres().' de '.$p->cupo.' libres)',
                    ])
                    ->values(),
                'documentos' => DocumentoRequerido::query()
                    ->delAmbito(DocumentoRequerido::AMBITO_PROCESO_FORMATIVO)
                    ->orderBy('nombre')
                    ->get(['id', 'nombre']),
                'estadosDocumento' => EstadoDocumento::query()->get(['id', 'clave', 'nombre']),
                'modalidades' => ModalidadProceso::query()->activos()->get(['id', 'nombre']),
                'requisitos' => collect(ExcepcionExpediente::REQUISITOS)
                    ->map(fn ($texto, $clave) => ['valor' => $clave, 'texto' => $texto])
                    ->values(),
            ],
            'puedeRevisar' => $quien?->can('revisar-solicitudes-formativas') ?? false,
            'puedeExcepcionar' => $quien?->can('aprobar-excepciones-formativas') ?? false,
        ]);
    }

    /**
     * Mover el expediente. UNA ruta para los ocho actos.
     *
     * Con un método por acto —`aprobar`, `rechazar`, `tomar`…— la tabla de
     * transiciones acabaría repetida en ocho firmas, y el noveno acto llegaría
     * sin alguna de las comprobaciones. Aquí el destino es un dato validado
     * contra el enum y el servicio decide si cuelga del origen.
     */
    public function mover(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'estado' => ['required', Rule::enum(EstadoExpediente::class)],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ]);

        $destino = EstadoExpediente::from($datos['estado']);

        /*
         * Asignar NO se hace por aquí: necesita organización, plaza y fechas, y
         * el cupo se apalabra con un bloqueo. Se dice en vez de dejarlo pasar
         * sin organización, que dejaría un expediente «asignado» a nadie.
         */
        AvisoParaElUsuario::si(
            $destino === EstadoExpediente::Asignado,
            422,
            'Para asignar hace falta la organización y las fechas: usa el formulario de asignación.',
        );

        $movido = $this->transiciones->mover(
            $expediente,
            $destino,
            $peticion->user(),
            $datos['motivo'] ?? null,
            $peticion->ip(),
        );

        // Cancelar devuelve el lugar a la plaza: si no, una plaza de cinco se
        // llena con cancelaciones y deja de recibir a nadie.
        if ($destino === EstadoExpediente::Cancelado) {
            $this->asignador->liberarLugar($movido);
        }

        return back(303)->with('exito', 'El expediente pasó a «'.$destino->etiqueta().'».');
    }

    public function asignar(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'organizacion_id' => ['required', 'integer', 'exists:organizaciones_receptoras,id'],
            'plaza_id' => ['nullable', 'integer', 'exists:plazas_proceso,id'],
            'modalidad_id' => ['nullable', 'integer', 'exists:modalidades_proceso,id'],
            'contacto_supervisor_id' => ['nullable', 'integer', 'exists:organizacion_contactos,id'],
            'responsable_interno_id' => ['nullable', 'integer', 'exists:personas,id'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin_programada' => ['required', 'date', 'after:fecha_inicio'],
        ]);

        $this->alcance->exigirQueAlcance($expediente, $peticion->user());

        /*
         * El plazo máximo de la regla congelada. Se comprueba aquí y no en la
         * validación porque depende del expediente, no del formulario: la
         * escuela puso ese tope para que un servicio social no se estire un
         * año más de lo previsto.
         */
        $this->exigirQueQuepaEnElPlazo($expediente, $datos);

        $this->asignador->asignar($expediente, $datos, $peticion->user(), $peticion->ip());

        return back(303)->with('exito', 'Asignado. El alumno ya puede empezar en la fecha señalada.');
    }

    /**
     * Perdonarle un requisito a este alumno, con su motivo y su firma.
     */
    public function excepcionar(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'requisito' => ['required', 'string', Rule::in(array_keys(ExcepcionExpediente::REQUISITOS))],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->alcance->exigirQueAlcance($expediente, $peticion->user());

        AvisoParaElUsuario::si(
            $expediente->excepciones()->where('requisito', $datos['requisito'])->exists(),
            422,
            'Ese requisito ya está excepcionado en este expediente.',
        );

        $expediente->excepciones()->create([
            'requisito' => $datos['requisito'],
            'motivo' => $datos['motivo'],
            'autorizada_por' => $peticion->user()?->id,
            'autorizada_en' => now(),
        ]);

        return back(303)->with('exito', 'Excepción autorizada. Queda escrita con tu nombre y tu motivo.');
    }

    /**
     * Retirar una excepción.
     *
     * Se BORRA en lógico y no de verdad: quién la autorizó y por qué es
     * historia del expediente, igual que un renglón de acta corregida.
     */
    public function quitarExcepcion(Request $peticion, ExpedienteProceso $expediente, ExcepcionExpediente $excepcion): RedirectResponse
    {
        $this->alcance->exigirQueAlcance($expediente, $peticion->user());

        AvisoParaElUsuario::aMenosQue(
            (int) $excepcion->expediente_id === (int) $expediente->id,
            404,
            'Esa excepción no es de este expediente.',
        );

        $excepcion->delete();

        return back(303)->with('exito', 'Excepción retirada: el requisito vuelve a exigirse.');
    }

    private function exigirQueQuepaEnElPlazo(ExpedienteProceso $expediente, array $datos): void
    {
        $expediente->loadMissing('reglaVersion');

        $plazo = $expediente->reglaVersion?->plazo_maximo_dias;

        if ($plazo === null) {
            return;
        }

        $dias = (int) round(
            (strtotime($datos['fecha_fin_programada']) - strtotime($datos['fecha_inicio'])) / 86400,
        );

        AvisoParaElUsuario::si(
            $dias > $plazo,
            422,
            'Esas fechas dan '.$dias.' días y la regla de este proceso pone un tope de '.$plazo.'. '
            .'Acorta el periodo, o autoriza la excepción con su motivo.',
        );
    }

    /** @return array<string, mixed> */
    private function paraLista(ExpedienteProceso $e): array
    {
        return [
            'id' => $e->id,
            'estado' => $e->estado->value,
            'estado_texto' => $e->estado->etiqueta(),
            'estado_color' => $e->estado->color(),
            'alumno' => $e->matricula?->persona?->nombreCompleto(),
            'matricula' => $e->matricula?->matricula,
            'programa' => $e->matricula?->oferta?->programaAcademico?->nombre,
            'campus' => $e->matricula?->oferta?->campus?->nombre,
            'tipo' => $e->tipoProceso?->nombre,
            'organizacion' => $e->organizacion?->comoSeLeConoce(),
            'fecha_solicitud' => $e->fecha_solicitud?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function paraDetalle(ExpedienteProceso $e): array
    {
        return array_merge($this->paraLista($e), [
            'plan' => $e->matricula?->oferta?->plan?->nombre,
            'motivo_estado' => $e->motivo_estado,
            'horas_requeridas' => $e->horas_requeridas,
            'horas_aprobadas' => $e->horas_aprobadas,
            'fecha_aprobacion' => $e->fecha_aprobacion?->toDateString(),
            'fecha_inicio' => $e->fecha_inicio?->toDateString(),
            'fecha_fin_programada' => $e->fecha_fin_programada?->toDateString(),
            'organizacion_id' => $e->organizacion_id,
            'plaza' => $e->plaza?->nombre,
            'modalidad' => $e->modalidad?->nombre,
            'supervisor' => $e->supervisor?->nombre,
            'responsable' => $e->responsableInterno?->nombreCompleto(),
            'organizacion_propuesta' => $e->organizacion_propuesta,
            'notas' => $e->notas,
            'regla' => $e->reglaVersion?->regla?->nombre,
            'regla_version' => $e->reglaVersion?->version,
            'exige_convenio' => (bool) $e->reglaVersion?->exige_convenio_vigente,
            'plazo_maximo_dias' => $e->reglaVersion?->plazo_maximo_dias,
            /*
             * Los destinos posibles salen del ENUM, no de una lista escrita en
             * el Vue: con la tabla de transiciones repetida en la pantalla, un
             * botón ofrecería un movimiento que el servidor rehúsa.
             */
            'siguientes' => array_map(fn (EstadoExpediente $s) => [
                'valor' => $s->value,
                // El VERBO rotula el botón —«Aprobar»— y la ETIQUETA nombra el
                // estado en la frase —«pasa a Aprobado»—. Con una sola palabra
                // salía «El expediente pasa a "Aprobar"», que mezcla las dos
                // cosas y se lee como un error.
                'texto' => ucfirst($s->verbo()),
                'estado_texto' => $s->etiqueta(),
                'color' => $s->color(),
                'exige_motivo' => $s->exigeMotivo(),
            ], $e->estado->siguientes()),
            'documentos' => $e->documentos->map(fn ($d) => [
                'id' => $d->id,
                'nombre' => $d->documento?->nombre,
                'momento' => $d->momento,
                'entregado' => $d->ruta !== null,
                'nombre_original' => $d->nombre_original,
                'estado' => $d->estado?->nombre,
                'estado_clave' => $d->estado?->clave,
                'vigencia' => $d->vigencia?->toDateString(),
                'vigente' => $d->estaVigente(),
                'observaciones' => $d->observaciones,
            ])->values(),
            'excepciones' => $e->excepciones->map(fn (ExcepcionExpediente $x) => [
                'id' => $x->id,
                'requisito' => $x->requisito,
                'etiqueta' => $x->etiqueta(),
                'motivo' => $x->motivo,
                'autorizada_por' => $x->autorizadaPor?->persona?->nombreCompleto(),
                'autorizada_en' => $x->autorizada_en?->format('d/m/Y H:i'),
            ])->values(),
            'historia' => $e->transiciones->map(fn ($t) => [
                'origen' => $t->estado_origen?->etiqueta(),
                'destino' => $t->estado_destino->etiqueta(),
                'color' => $t->estado_destino->color(),
                'motivo' => $t->motivo,
                'quien' => $t->usuario?->persona?->nombreCompleto(),
                'momento' => $t->momento?->format('d/m/Y H:i'),
            ])->values(),
        ]);
    }
}
