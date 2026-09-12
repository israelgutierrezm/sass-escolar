<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\VeLaCarteraDelAlumno;
use App\Http\Controllers\Controller;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Disciplina\Incidencia;
use App\Models\Disciplina\Sancion;
use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\SolicitudFactura;
use App\Models\Identidad\Autorizacion;
use App\Models\Identidad\AutorizadoRecoger;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Services\EstadoCuenta;
use App\Services\EstadoDelAlumno;
use App\Services\Familia\AutorizadosParaRecoger;
use App\Services\Familia\EntregaDocumentos;
use App\Services\Familia\GestorDeCitas;
use App\Services\Familia\PuedeRecoger;
use App\Services\Familia\RespuestaAutorizacion;
use App\Services\Finanzas\AutoservicioFactura;
use App\Services\GestorSolicitudFactura;
use App\Services\HistorialDelAlumno;
use App\Services\Pagos\CobroEnLinea;
use App\Services\Pagos\Pasarelas;
use App\Services\Pagos\RegistroDeComprobante;
use App\Services\Plataforma\ModulosDeLaEscuela;
use App\Support\CatalogosSat;
use App\Support\PasarelasCatalogo;
use App\Support\UrlPublica;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * El portal de la FAMILIA para la app móvil.
 *
 * ── Una sola verdad, no una segunda para la app ────────────────────────────
 * No calcula nada por su cuenta: el estado de cada hijo sale de `EstadoDelAlumno`,
 * el promedio y los renglones de `HistorialDelAlumno`, y el saldo de
 * `EstadoCuenta` —los mismos servicios que la web y el portal del alumno—. El
 * día que una regla cambie, la web y la app dicen lo mismo porque leen del
 * mismo sitio.
 *
 * ── Qué se sirve ───────────────────────────────────────────────────────────
 * El NÚCLEO de lectura, como hizo el portal del alumno: la lista de hijos con su
 * estado, y por cada hijo lo académico, lo financiero y la conducta. Y los
 * flujos INTERACTIVOS que ya se portaron —confirmar autorizaciones, entregar
 * documentos, solicitar/generar factura, pagar en línea (pasarela y comprobante),
 * la salida segura (quién recoge al hijo) y las citas con los docentes—, cada
 * uno sobre su servicio compartido con la web. Con esto el portal de la familia
 * queda completo en la app.
 *
 * ── El alcance lo pone el VÍNCULO, no la URL ───────────────────────────────
 * Qué hijo es suyo lo decide `tutores_alumno` (la misma puerta que la web): un
 * id de persona ajeno no está vinculado y responde 403. Y qué le dejó ver la
 * escuela —académico, financiero— sale del pivote del vínculo, no del permiso:
 * el permiso deja entrar al portal, el vínculo decide qué se enseña. Para la
 * cartera —y facturar es una operación de la cartera— la pregunta «¿de quién es
 * esta cuenta?» la responde `VeLaCarteraDelAlumno`, el MISMO trait que la web,
 * que ya sabe del padre por vínculo + `puede_ver_finanzas`.
 *
 * ── La faceta la fija `api.faceta:padre_familia` ───────────────────────────
 * De él depende que el `Gate::before` de `ver-conducta-hijo`, el ámbito de
 * `EstadoCuenta` y el de `VeLaCarteraDelAlumno` resuelvan como FAMILIA. Sin ese
 * middleware, un permiso de la faceta no tendría rol activo contra el que
 * comprobarse.
 */
class PadreApiController extends Controller
{
    use AcotaPorCampus;
    use VeLaCarteraDelAlumno;

    public function __construct(
        private readonly EstadoDelAlumno $estadoDelAlumno,
        private readonly HistorialDelAlumno $historial,
        private readonly EstadoCuenta $estadoCuenta,
        private readonly RespuestaAutorizacion $autorizaciones,
        private readonly EntregaDocumentos $documentos,
        private readonly AutoservicioFactura $autoservicioFactura,
        private readonly GestorSolicitudFactura $gestorFactura,
        private readonly Pasarelas $pasarelas,
        private readonly CobroEnLinea $cobro,
        private readonly RegistroDeComprobante $registroComprobante,
        private readonly PuedeRecoger $puedeRecoger,
        private readonly AutorizadosParaRecoger $autorizadosRecoger,
        private readonly GestorDeCitas $citasGestor,
    ) {}

    /** Los hijos vinculados, con su estado según lo que la escuela le dejó ver. */
    public function hijos(Request $peticion): JsonResponse
    {
        $persona = $peticion->user()->persona;

        $hijos = $persona->hijos()->get()->map(function (Persona $hijo) {
            $verAcademico = (bool) $hijo->pivot->puede_ver_academico;
            $verFinanzas = (bool) $hijo->pivot->puede_ver_finanzas;

            $programas = $hijo->matriculas()
                ->with('oferta.programaAcademico:id,nombre')
                ->get()
                ->map(fn (MatriculaOferta $m) => $m->oferta?->programaAcademico?->nombre)
                ->filter()
                ->values();

            return [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'parentesco' => Parentesco::nombreDe($hijo->pivot->parentesco_id),
                'programas_academicos' => $programas,
                'puede_ver_academico' => $verAcademico,
                'puede_ver_finanzas' => $verFinanzas,
                // El ESTADO, no sólo el nombre: si debe algo o si va mal. Se
                // respeta lo que la escuela le dejó ver —la señal no existe, en
                // vez de ocultarse en la vista—. Mismo servicio que la web.
                'estado' => $this->estadoDelAlumno->de($hijo, $verAcademico, $verFinanzas),
            ];
        })->values();

        return response()->json(['hijos' => $hijos]);
    }

    /** Un hijo: académico, finanzas y conducta, según los permisos del vínculo. */
    public function hijo(Request $peticion, Persona $hijo): JsonResponse
    {
        $vinculo = TutorAlumno::query()
            ->where('tutor_persona_id', $peticion->user()->persona_id)
            ->where('alumno_persona_id', $hijo->id)
            ->first();

        AvisoParaElUsuario::si($vinculo === null, 403, 'Este alumno no está vinculado a tu cuenta.');

        // El autoservicio de factura: el canal abierto por la escuela, el permiso
        // de faceta y que este vínculo alcance lo financiero. Las tres, como en la
        // web. Generar (emite al momento) manda sobre solicitar si ambos.
        $facturaModo = $this->facturaModo($peticion, $vinculo);

        $matriculas = $hijo->matriculas()
            ->with([
                'oferta.programaAcademico:id,nombre',
                'oferta.plan:id,nombre,total_creditos',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->orderByDesc('fecha_ingreso')
            ->get();

        return response()->json([
            'hijo' => [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'curp' => $hijo->curp,
                'parentesco' => $vinculo->parentesco?->nombre,
            ],
            'permisos' => [
                'academico' => (bool) $vinculo->puede_ver_academico,
                'finanzas' => (bool) $vinculo->puede_ver_finanzas,
            ],
            'academico' => $vinculo->puede_ver_academico
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->academicoDe($m))->values()
                : null,
            'finanzas' => $vinculo->puede_ver_finanzas
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->finanzasDe($m, $facturaModo !== null))->values()
                : null,
            // Qué botón de factura ofrecer: 'generar', 'solicitar' o null. Es del
            // usuario, no de cada matrícula, así que va arriba —igual que la web—.
            'factura_modo' => $facturaModo,
            /*
             * Con qué se puede pagar en línea, atado al permiso financiero del
             * vínculo igual que los saldos: sin ver lo que se debe no hay por qué
             * ver botones para pagarlo. Las pasarelas de la escuela, el abono
             * mínimo y si se permite pagar todo de una vez —el servidor lo vuelve
             * a exigir al cobrar—. Las cuentas para transferencia van por
             * matrícula (dependen del programa) dentro de `finanzas`.
             */
            'pago' => $vinculo->puede_ver_finanzas ? [
                'pasarelas' => $this->pasarelas->disponibles(),
                'abono_minimo' => app(Ajustes::class)->entero(CatalogoAjustes::ABONO_MINIMO),
                'pago_total' => app(Ajustes::class)->bool(CatalogoAjustes::PAGO_TOTAL),
            ] : null,
            // La conducta va con el permiso de faceta —no con el vínculo, que
            // distingue académico de financiero pero no disciplina— y sólo si el
            // módulo está encendido.
            'conducta' => ($peticion->user()->can('ver-conducta-hijo') && app(ModulosDeLaEscuela::class)->activo('disciplina'))
                ? $this->conductaDe($matriculas)
                : null,
        ]);
    }

    /**
     * El modo del autoservicio de factura para este vínculo: 'generar' si emite
     * al momento, 'solicitar' si sólo pide, o null si aquí no aplica.
     *
     * Las tres capas de la web: el vínculo alcanza lo financiero, el permiso de
     * faceta, y la escuela abrió ese canal. Generar manda si están los dos.
     */
    private function facturaModo(Request $peticion, TutorAlumno $vinculo): ?string
    {
        if (! $vinculo->puede_ver_finanzas) {
            return null;
        }

        $ajustes = app(Ajustes::class);
        $usuario = $peticion->user();

        if ($usuario->can('generar-mi-factura') && $ajustes->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR)) {
            return 'generar';
        }

        if ($usuario->can('solicitar-factura') && $ajustes->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD)) {
            return 'solicitar';
        }

        return null;
    }

    /**
     * Las autorizaciones que la escuela le pide a esta familia —lo que falta
     * contestar primero—. Del mismo servicio que el portal web.
     */
    public function autorizaciones(Request $peticion): JsonResponse
    {
        return response()->json(['autorizaciones' => $this->autorizaciones->lista($peticion->user())]);
    }

    /**
     * Concede o niega una autorización. La escritura y sus guardas viven en el
     * servicio: un vínculo ajeno o una que ya no admite respuesta → 404.
     */
    public function responder(Request $peticion, Autorizacion $autorizacion): JsonResponse
    {
        $datos = $peticion->validate([
            'concedida' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:500'],
        ]);

        $this->autorizaciones->responder($autorizacion, $peticion->user(), $datos['concedida'], $datos['comentario'] ?? null);

        return response()->json(['ok' => true]);
    }

    /** Retira un consentimiento en vigor. Lo que no está en vigor → 404. */
    public function revocar(Request $peticion, Autorizacion $autorizacion): JsonResponse
    {
        $datos = $peticion->validate([
            'comentario' => ['nullable', 'string', 'max:500'],
        ]);

        $this->autorizaciones->revocar($autorizacion, $peticion->user(), $datos['comentario'] ?? null);

        return response()->json(['ok' => true]);
    }

    /**
     * Los documentos que la escuela le pide al hijo y los que ya subió, cuando
     * el tutor puede entregarlos por él. El motivo viaja dentro cuando no puede;
     * 404 cuando la escuela no tiene contratado este acto.
     */
    public function documentos(Request $peticion, Persona $hijo): JsonResponse
    {
        $vinculo = $this->vinculoCon($hijo, $peticion->user());
        AvisoParaElUsuario::si($vinculo === null, 403, 'Este alumno no está vinculado a tu cuenta.');

        $datos = $this->documentos->datos($hijo, $vinculo);
        AvisoParaElUsuario::si($datos === null, 404, 'Tu escuela no tiene activada la entrega de documentos por la familia.');

        return response()->json($datos);
    }

    /** Sube (o reemplaza) un documento del hijo. Multipart: documento_id + archivo. */
    public function subirDocumento(Request $peticion, Persona $hijo): JsonResponse
    {
        $vinculo = $this->vinculoCon($hijo, $peticion->user());
        $this->documentos->exigirPoderEntregar($vinculo, $hijo);

        $datos = $peticion->validate([
            // Sólo del ÁMBITO ALUMNO: el id de un documento de otro ámbito no
            // debe acabar en el expediente del alumno.
            'documento_id' => [
                'required',
                'integer',
                function (string $atributo, mixed $valor, callable $falla) {
                    $delAmbito = DocumentoRequerido::query()
                        ->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
                        ->whereKey($valor)
                        ->exists();

                    if (! $delAmbito) {
                        $falla('Ese documento no es de los que la escuela le pide a tu hijo.');
                    }
                },
            ],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'descripcion' => ['nullable', 'string', 'max:100'],
            'vigencia' => ['nullable', 'date', 'after:today'],
        ], [
            'archivo.max' => 'El archivo no puede pasar de 5 MB.',
            'archivo.mimes' => 'Solo se aceptan PDF o imágenes.',
            'vigencia.after' => 'Un documento que ya venció no sirve como comprobante.',
        ]);

        $error = $this->documentos->subir(
            $hijo,
            (int) $datos['documento_id'],
            $peticion->file('archivo'),
            $datos['descripcion'] ?? null,
            $datos['vigencia'] ?? null,
        );

        // Lo aceptado no se pisa: se dice, no se traga.
        AvisoParaElUsuario::si($error !== null, 422, (string) $error);

        return response()->json(['ok' => true]);
    }

    /** Retira un documento del hijo (lo aceptado no se retira desde aquí). */
    public function eliminarDocumento(Request $peticion, Persona $hijo, DocumentoAlumno $documento): JsonResponse
    {
        $vinculo = $this->vinculoCon($hijo, $peticion->user());
        $this->documentos->exigirPoderEntregar($vinculo, $hijo);
        $this->documentos->exigirDeEseHijo($hijo, $documento);

        $error = $this->documentos->eliminar($documento);
        AvisoParaElUsuario::si($error !== null, 422, (string) $error);

        return response()->json(['ok' => true]);
    }

    // ── Salida segura: quién puede recoger ──────────────────────────────────

    /**
     * Quién puede recoger al hijo: la lista EFECTIVA (tutores + terceros
     * vigentes, del servicio compartido), los terceros que la familia puede
     * editar y el catálogo de parentescos para el alta. No se gatea por lo
     * financiero ni lo académico: cualquier tutor del hijo gestiona sus recogidas.
     */
    public function recogen(Request $peticion, Persona $hijo): JsonResponse
    {
        AvisoParaElUsuario::si($this->vinculoCon($hijo, $peticion->user()) === null, 403, 'Este alumno no está vinculado a tu cuenta.');

        return response()->json([
            'efectiva' => $this->puedeRecoger->listaEfectiva($hijo->id),
            'terceros' => AutorizadoRecoger::query()
                ->where('alumno_persona_id', $hijo->id)->autoriza()
                ->with('parentesco:id,nombre')->orderBy('nombre')->get()
                ->map(fn (AutorizadoRecoger $a) => [
                    'id' => $a->id,
                    'nombre' => $a->nombre,
                    'identificacion' => $a->identificacion,
                    'parentesco' => $a->parentesco?->nombre,
                    'vigencia_hasta' => $a->vigencia_hasta?->toDateString(),
                    'vigente' => $a->vigente(),
                ])->values(),
            'parentescos' => Parentesco::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /** La familia autoriza a un tercero a recoger a su hijo. */
    public function agregarAutorizado(Request $peticion, Persona $hijo): JsonResponse
    {
        AvisoParaElUsuario::si($this->vinculoCon($hijo, $peticion->user()) === null, 403, 'Este alumno no está vinculado a tu cuenta.');

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:180'],
            'identificacion' => ['nullable', 'string', 'max:120'],
            'parentesco_id' => ['nullable', 'integer', 'exists:parentescos,id'],
            'vigencia_desde' => ['nullable', 'date'],
            'vigencia_hasta' => ['nullable', 'date', 'after_or_equal:vigencia_desde'],
        ]);

        $this->autorizadosRecoger->agregar($hijo, $datos);

        return response()->json(['ok' => true]);
    }

    /**
     * La familia retira a un tercero SUYO. El servicio rehúsa un bloqueo de
     * custodia (404); aquí además se comprueba que el autorizado sea de ESTE hijo.
     */
    public function quitarAutorizado(Request $peticion, Persona $hijo, AutorizadoRecoger $autorizado): JsonResponse
    {
        AvisoParaElUsuario::si($this->vinculoCon($hijo, $peticion->user()) === null, 403, 'Este alumno no está vinculado a tu cuenta.');
        AvisoParaElUsuario::si($autorizado->alumno_persona_id !== $hijo->id, 404, 'Ese autorizado no es de este alumno.');

        $this->autorizadosRecoger->quitar($autorizado);

        return response()->json(['ok' => true]);
    }

    // ── Citas familia–docente ───────────────────────────────────────────────

    /**
     * Las citas del hijo: los docentes que le dan clase (con sus ventanas de
     * atención), las modalidades y las citas ya pedidas con su estado. Del mismo
     * servicio que el portal web. Un hijo ajeno → 404 (no confirma que exista).
     */
    public function citas(Request $peticion, Persona $hijo): JsonResponse
    {
        $tutorId = (int) $peticion->user()->persona_id;
        AvisoParaElUsuario::aMenosQue($this->citasGestor->esHijoDe($tutorId, $hijo->id), 404, 'Ese alumno no está vinculado a tu cuenta.');

        $docentes = $this->citasGestor->docentesDelAlumno($hijo->id);
        $ventanas = $this->citasGestor->ventanasPorDocente(array_column($docentes, 'persona_id'));

        return response()->json([
            'docentes' => array_map(fn (array $d) => [...$d, 'ventanas' => $ventanas[$d['persona_id']] ?? []], $docentes),
            'modalidades' => DisponibilidadCitaDocente::MODALIDADES,
            'citas' => Cita::query()
                ->where('alumno_persona_id', $hijo->id)
                ->where('solicitante_persona_id', $tutorId)
                ->with('docente:id,nombre,primer_apellido,segundo_apellido')
                ->orderByDesc('inicio')->limit(100)->get()
                ->map(fn (Cita $c) => [
                    'id' => $c->id,
                    'docente' => $c->docente?->nombreCompleto(),
                    'inicio' => $c->inicio?->format('Y-m-d H:i'),
                    'fin' => $c->fin?->format('H:i'),
                    'modalidad' => $c->modalidad,
                    'motivo' => $c->motivo,
                    'lugar' => $c->lugar,
                    'estado' => $c->estado,
                    'respuesta' => $c->respuesta,
                    'ya_paso' => $c->yaPaso(),
                ])->values(),
        ]);
    }

    /**
     * La familia SOLICITA una cita en un hueco de una ventana del docente. Las
     * guardas —el hijo es suyo, el docente le da clase, el hueco es válido, no en
     * el pasado ni ya ocupado— viven en `GestorDeCitas`.
     */
    public function solicitarCita(Request $peticion, Persona $hijo): JsonResponse
    {
        $datos = $peticion->validate([
            'disponibilidad_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        $this->citasGestor->solicitar(
            (int) $peticion->user()->persona_id,
            $hijo->id,
            (int) $datos['disponibilidad_id'],
            $datos['fecha'],
            $datos['hora_inicio'],
            $datos['motivo'],
        );

        return response()->json(['ok' => true]);
    }

    /** La familia CANCELA una cita suya, con motivo (el servicio exige ser parte). */
    public function cancelarCita(Request $peticion, Cita $cita): JsonResponse
    {
        $datos = $peticion->validate(['respuesta' => ['required', 'string', 'max:500']]);

        $this->citasGestor->cancelar($cita, (int) $peticion->user()->persona_id, $datos['respuesta']);

        return response()->json(['ok' => true]);
    }

    /** El vínculo de este tutor con el hijo, o null si no es suyo. */
    private function vinculoCon(Persona $hijo, $usuario): ?TutorAlumno
    {
        return TutorAlumno::query()
            ->where('tutor_persona_id', $usuario?->persona_id)
            ->where('alumno_persona_id', $hijo->id)
            ->first();
    }

    // ── Pagar en línea ──────────────────────────────────────────────────────

    /**
     * Empieza un cobro en línea y devuelve a dónde mandar a quien paga.
     *
     * Reusa `CobroEnLinea::iniciar` —el mismo motor y las mismas guardas que la
     * web— con las mismas dos URLs: el RETORNO lo abre el navegador de vuelta; el
     * AVISO lo abre la pasarela desde internet (el webhook, que es lo único que
     * cobra). La app abre la URL devuelta y luego relee el estado de cuenta: el
     * webhook concilia aunque la app se cierre.
     */
    public function iniciarPago(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        $datos = $peticion->validate([
            'pasarela' => ['required', 'string', 'max:30'],
            'adeudo_ids' => ['required', 'array', 'min:1'],
            'adeudo_ids.*' => ['integer'],
            'metodo' => ['nullable', 'string', 'max:20'],
            'importe' => ['nullable', 'numeric', 'min:0.01'],
        ], [
            'adeudo_ids.required' => 'Elige al menos un cargo para pagar.',
        ]);

        try {
            $intencion = $this->cobro->iniciar(
                $matricula,
                $datos['pasarela'],
                $datos['adeudo_ids'],
                route('tenant.pagos.retorno'),
                UrlPublica::paraAfuera(route('tenant.pagos.aviso', ['pasarela' => $datos['pasarela']])),
                $datos['metodo'] ?? null,
                isset($datos['importe']) ? (float) $datos['importe'] : null,
            );
        } catch (HttpException $e) {
            // Un aviso para quien paga (falta elegir método, cargos, «pagar
            // todo»…) ya trae su mensaje y su código; el manejador de /api lo da
            // como JSON. Sin esta rama caería abajo y se culparía a la pasarela.
            throw $e;
        } catch (RuntimeException $e) {
            // Falló algo de la escuela (credenciales, pasarela mal configurada):
            // al registro el motivo, a quien paga que no es culpa suya.
            Log::error('No se pudo abrir un cobro en línea desde la app.', [
                'pasarela' => $datos['pasarela'],
                'matricula' => $matricula->id,
                'motivo' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo abrir el pago con '.PasarelasCatalogo::nombreDe($datos['pasarela'])
                    .'. No es problema tuyo: avísale a la escuela para que lo revise.',
            ], 422);
        }

        return response()->json(['url' => $intencion->url_pago]);
    }

    /**
     * Sube el comprobante de una transferencia ya hecha (multipart). Nace
     * PENDIENTE; la escuela lo valida y ahí se liquida el cargo. Misma
     * autorización (cartera) y mismo servicio que la web.
     */
    public function subirComprobante(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        $datos = $peticion->validate([
            'cuenta_bancaria_id' => ['nullable', 'integer'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha_transferencia' => ['required', 'date', 'before_or_equal:today'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'adeudo_ids' => ['nullable', 'array'],
            'adeudo_ids.*' => ['integer'],
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'fecha_transferencia.before_or_equal' => 'La fecha de la transferencia no puede ser futura.',
            'archivo.mimes' => 'El comprobante tiene que ser una imagen o un PDF.',
            'archivo.max' => 'El comprobante no puede pesar más de 5 MB.',
        ]);

        $this->registroComprobante->registrar($matricula, $peticion->file('archivo'), $datos);

        return response()->json(['ok' => true]);
    }

    /**
     * Lo académico de una matrícula, con las cifras del servicio compartido.
     *
     * El promedio y los créditos salen de `HistorialDelAlumno` —el mejor intento
     * por materia, la regla oficial— y no de sumar renglones aquí; los renglones
     * se enseñan todos, porque son historia escolar.
     *
     * @return array<string, mixed>
     */
    private function academicoDe(MatriculaOferta $m): array
    {
        $resumen = $this->historial->resumen($m);

        return [
            'matricula' => $m->matricula,
            'programa_academico' => $m->oferta?->programaAcademico?->nombre,
            'plan' => $m->oferta?->plan?->nombre,
            'estatus' => $m->estatus,
            'promedio' => $resumen['promedio'],
            'creditos' => $resumen['creditos'],
            'creditos_del_plan' => $resumen['creditos_del_plan'],
            'renglones' => $this->historial->renglones($m),
        ];
    }

    /**
     * Lo financiero de una matrícula, con la cuenta del servicio compartido y el
     * autoservicio de factura del MISMO servicio que la web.
     *
     * `factura_autoservicio` (qué se puede facturar, con qué perfil) sólo cuando
     * el canal está abierto; las solicitudes y las facturas ya emitidas viajan
     * siempre —el historial no se esconde—.
     *
     * @return array<string, mixed>
     */
    private function finanzasDe(MatriculaOferta $m, bool $conAutoservicio = false): array
    {
        $facturas = Factura::query()
            ->where('matricula_oferta_id', $m->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Factura $f) => [
                'uuid' => $f->uuid,
                'total' => (float) $f->total,
                'estatus' => $f->estatus,
                'fecha' => $f->fecha_timbrado?->toDateString(),
            ])->values();

        return [
            'matricula_id' => $m->id,
            'matricula' => $m->matricula,
            'programa_academico' => $m->oferta?->programaAcademico?->nombre,
            // El mismo servicio que la pantalla de finanzas y el expediente.
            'cuenta' => $this->estadoCuenta->para($m),
            'facturas' => $facturas,
            'factura_autoservicio' => $conAutoservicio ? $this->autoservicioFactura->datosParaSolicitar($m) : null,
            'solicitudes_factura' => $this->autoservicioFactura->solicitudesDe($m),
            // Las cuentas para transferencia directa: las del programa de esta
            // matrícula que pueden recibir. Copiar la CLABE es el otro camino de
            // pago, con su comprobante.
            'cuentas_bancarias' => CuentaBancaria::paraProgramaAcademico($m->oferta?->programa_academico_id)
                ->filter(fn (CuentaBancaria $c) => $c->puedeRecibir())
                ->map(fn (CuentaBancaria $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'banco' => $c->banco,
                    'titular' => $c->titular,
                    'clabe' => $c->clabe,
                    'numero_cuenta' => $c->numero_cuenta,
                    'instrucciones' => $c->instrucciones,
                ])->values()->all(),
        ];
    }

    // ── Solicitar / generar / descargar factura ────────────────────────────

    /**
     * La familia PIDE factura de unos pagos del hijo (la emite la escuela).
     *
     * La regla de «de quién es esta cuenta» la responde `VeLaCarteraDelAlumno`
     * —vínculo + `puede_ver_finanzas`, la misma que la web—; el canal cerrado por
     * la escuela responde 404 (no 403), igual que solicitar-en-la-web. La regla
     * de negocio y las guardas viven en `GestorSolicitudFactura`.
     */
    public function solicitarFactura(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        AvisoParaElUsuario::aMenosQue(
            app(Ajustes::class)->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD),
            404,
            'La solicitud de factura en línea no está disponible en esta escuela.',
        );

        $datos = $this->datosDelReceptor($peticion);
        $this->gestorFactura->solicitar($matricula, $datos['pago_ids'], $datos['receptor'], $peticion->user());

        return response()->json(['ok' => true]);
    }

    /**
     * La familia GENERA su factura al momento: nace el CFDI sin pasar por la
     * bandeja. Capacidad y canal APARTE de solicitar (emitir a nombre de la
     * escuela es más delicado). Mismo motor, mismas guardas.
     */
    public function generarFactura(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        AvisoParaElUsuario::aMenosQue(
            app(Ajustes::class)->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR),
            404,
            'Generar tu factura en línea no está disponible en esta escuela.',
        );

        $datos = $this->datosDelReceptor($peticion);
        $this->gestorFactura->generarDirecto($matricula, $datos['pago_ids'], $datos['receptor'], $peticion->user());

        return response()->json(['ok' => true]);
    }

    /**
     * El CFDI de una solicitud ya emitida, para quien puede ver esa cuenta.
     *
     * La misma acotación que el estado de cuenta: un padre alcanza el CFDI del
     * hijo cuyo vínculo le deja lo financiero, y de nadie más. Sólo se descarga
     * lo TIMBRADO —lo que la lista marca `descargable`—; lo demás → 404.
     */
    public function descargarCfdi(Request $peticion, SolicitudFactura $solicitud, string $tipo): StreamedResponse
    {
        abort_unless(in_array($tipo, ['xml', 'pdf'], true), 404);

        $matricula = $solicitud->matriculaOferta;
        abort_unless($matricula !== null, 404);

        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        $factura = $solicitud->factura;
        abort_if($factura === null || ! $factura->estaVigente(), 404);

        $ruta = $tipo === 'xml' ? $factura->xml_ruta : $factura->pdf_ruta;
        abort_if($ruta === null || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->download($ruta, ($factura->uuid ?? 'factura-'.$factura->id).'.'.$tipo);
    }

    /**
     * Valida y arma el receptor del autoservicio. Contra el catálogo del SAT,
     * como la emisión y como la web: lo que se pide tiene que poder timbrarse
     * tal cual. El correo es de ENTREGA, aparte de los datos fiscales.
     *
     * @return array{pago_ids: array<int, int>, receptor: array<string, string|null>}
     */
    private function datosDelReceptor(Request $peticion): array
    {
        $datos = $peticion->validate([
            'pago_ids' => ['required', 'array', 'min:1'],
            'pago_ids.*' => ['integer'],
            'rfc' => ['required', 'string', 'min:12', 'max:13'],
            'razon_social' => ['required', 'string', 'max:255'],
            'uso_cfdi' => ['required', 'string', Rule::in(CatalogosSat::clavesUsosCfdi())],
            'regimen_fiscal' => ['required', 'string', Rule::in(CatalogosSat::clavesRegimenes())],
            'cp' => ['required', 'string', 'size:5'],
            'correo' => ['nullable', 'email', 'max:190'],
        ]);

        return [
            'pago_ids' => $datos['pago_ids'],
            'receptor' => [
                'rfc' => $datos['rfc'],
                'razon_social' => $datos['razon_social'],
                'uso_cfdi' => $datos['uso_cfdi'],
                'regimen_fiscal' => $datos['regimen_fiscal'],
                'cp' => $datos['cp'],
                'correo' => $datos['correo'] ?? null,
            ],
        ];
    }

    /**
     * Incidencias y sanciones de todas las matrículas del hijo. De sólo lectura:
     * la familia CONSULTA, no registra.
     *
     * @param  Collection<int, MatriculaOferta>  $matriculas
     * @return array{incidencias: array<int, mixed>, sanciones: array<int, mixed>}
     */
    private function conductaDe(Collection $matriculas): array
    {
        $ids = $matriculas->pluck('id');

        $incidencias = Incidencia::query()
            ->whereIn('matricula_oferta_id', $ids)
            ->with('tipo:id,nombre,nivel')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get()
            ->map(fn (Incidencia $i) => [
                'id' => $i->id,
                'tipo' => $i->tipo?->nombre,
                'nivel' => $i->tipo?->nivel,
                'fecha' => $i->fecha?->format('Y-m-d'),
                'descripcion' => $i->descripcion,
            ])->all();

        $sanciones = Sancion::query()
            ->whereIn('matricula_oferta_id', $ids)
            ->with('tipo:id,nombre')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get()
            ->map(fn (Sancion $s) => [
                'id' => $s->id,
                'tipo' => $s->tipo?->nombre,
                'fecha' => $s->fecha?->format('Y-m-d'),
                'desde' => $s->desde?->format('Y-m-d'),
                'hasta' => $s->hasta?->format('Y-m-d'),
                'vigente' => $s->vigente(),
                'motivo' => $s->motivo,
            ])->all();

        return ['incidencias' => $incidencias, 'sanciones' => $sanciones];
    }
}
