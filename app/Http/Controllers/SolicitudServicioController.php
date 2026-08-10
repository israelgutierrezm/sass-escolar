<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Servicio;
use App\Models\Finanzas\SolicitudServicio;
use App\Models\Identidad\Usuario;
use App\Services\SolicitudDeServicios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La solicitud de servicios: lo que pide el alumno y lo que resuelve la escuela.
 *
 * ── De dónde sale la matrícula ─────────────────────────────────────────────
 * Siempre de la SESIÓN, nunca de la petición. Es lo que impide que alguien pida
 * una constancia a nombre de otro cambiando un número en el formulario —y el
 * cargo le llegaría al otro—. Cuando alguien tiene dos carreras elige entre las
 * suyas, y esa elección se comprueba contra las suyas otra vez.
 */
class SolicitudServicioController extends Controller
{
    public function __construct(private readonly SolicitudDeServicios $solicitudes) {}

    /** Lo que ve el alumno: qué puede pedir y cómo van sus trámites. */
    public function index(Request $peticion): Response
    {
        $matriculas = $this->matriculasDe($peticion);

        return Inertia::render('Servicios/Index', [
            'catalogo' => Servicio::query()
                ->ofrecidos()
                ->get(['id', 'nombre', 'descripcion', 'precio', 'instrucciones'])
                ->map(fn (Servicio $s) => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'descripcion' => $s->descripcion,
                    'precio' => (float) $s->precio,
                    'tiene_costo' => $s->tieneCosto(),
                    'instrucciones' => $s->instrucciones,
                ]),

            'matriculas' => $matriculas
                ->map(fn (MatriculaOferta $m) => ['id' => $m->id, 'matricula' => $m->matricula])
                ->values(),

            /*
             * Si quien mira puede pedir, o sólo está revisando.
             *
             * A esta pantalla entran dos oficios: el alumno, y quien atiende el
             * mostrador viendo cómo le queda el catálogo. Sin esta bandera, el
             * segundo vería el botón «Solicitar» y se llevaría un 403 al
             * pulsarlo —un botón que rebota es peor que un botón que no está,
             * porque el primero parece una avería.
             */
            'puedePedir' => $peticion->user()?->can('solicitar-servicios') ?? false,

            'solicitudes' => SolicitudServicio::query()
                ->whereIn('matricula_oferta_id', $matriculas->pluck('id'))
                ->with(['servicio:id,nombre', 'adeudo'])
                ->latest('id')
                ->get()
                ->map(fn (SolicitudServicio $s) => $this->paraElAlumno($s)),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        $matriculas = $this->matriculasDe($peticion);

        $datos = $peticion->validate([
            'servicio_id' => ['required', 'integer', 'exists:servicios,id'],
            // Sólo entre las SUYAS. Sin esta lista, el número de matrícula que
            // llegue en el formulario mandaría el cargo a quien sea.
            'matricula_oferta_id' => ['required', 'integer', Rule::in($matriculas->pluck('id'))],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->solicitudes->pedir(
                Servicio::findOrFail($datos['servicio_id']),
                $matriculas->firstWhere('id', $datos['matricula_oferta_id']),
                $datos['nota'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['servicio_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Tu solicitud quedó registrada.');
    }

    public function cancelar(Request $peticion, SolicitudServicio $solicitud): RedirectResponse
    {
        $this->exigirQueSeaSuya($peticion, $solicitud);

        try {
            $this->solicitudes->cancelar($solicitud);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Se canceló tu solicitud.');
    }

    // ── El mostrador ───────────────────────────────────────────────────────

    /** La bandeja de quien atiende, y el catálogo de lo que se ofrece. */
    public function bandeja(Request $peticion): Response
    {
        return Inertia::render('Escolar/Servicios', [
            'solicitudes' => SolicitudServicio::query()
                ->with(['servicio:id,nombre', 'matricula.persona', 'adeudo'])
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (SolicitudServicio $s) => array_merge($this->paraElAlumno($s), [
                    'alumno' => $s->matricula?->persona?->nombreCompleto(),
                    'matricula' => $s->matricula?->matricula,
                    'nota_alumno' => $s->nota_alumno,
                ])),

            'servicios' => Servicio::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'precio', 'solicitable', 'instrucciones'])
                ->map(fn (Servicio $s) => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'precio' => (float) $s->precio,
                    'tiene_costo' => $s->tieneCosto(),
                    'solicitable' => $s->solicitable,
                    'instrucciones' => $s->instrucciones,
                ]),
        ]);
    }

    /**
     * Qué se ofrece y con qué instrucciones.
     *
     * Sólo estos dos campos: el precio y el concepto son de Finanzas. Es el
     * mismo reparto visto desde el otro lado —ver `ServicioController`.
     */
    public function ofrecer(Request $peticion, Servicio $servicio): RedirectResponse
    {
        $datos = $peticion->validate([
            'solicitable' => ['required', 'boolean'],
            'instrucciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $servicio->update($datos);

        return back()->with('success', 'Se guardó qué puede pedir el alumno.');
    }

    public function resolver(Request $peticion, SolicitudServicio $solicitud): RedirectResponse
    {
        $datos = $peticion->validate([
            'estado' => ['required', Rule::in([
                SolicitudServicio::ESTADO_ATENDIDA,
                SolicitudServicio::ESTADO_RECHAZADA,
            ])],
            'respuesta' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * No se atiende lo que todavía no está pagado.
         *
         * Es la regla que sostiene todo el módulo: el trámite arranca cuando el
         * dinero entró. Se comprueba aquí, contra el adeudo, y no con el botón
         * escondido en pantalla —esconder el botón deja la petición viva—.
         * Rechazar sí se puede en cualquier momento: negar un trámite no
         * depende de que lo hayan pagado.
         */
        if ($datos['estado'] === SolicitudServicio::ESTADO_ATENDIDA && $solicitud->esperandoPago()) {
            throw ValidationException::withMessages([
                'estado' => 'Todavía no se cubre el pago de este servicio. Aplica el cobro y vuelve a intentarlo.',
            ]);
        }

        try {
            $this->solicitudes->cerrar(
                $solicitud,
                $datos['estado'],
                $datos['respuesta'] ?? null,
                $peticion->user()?->persona_id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Se resolvió la solicitud.');
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Las matrículas de quien pide, sacadas de la sesión.
     *
     * @return \Illuminate\Support\Collection<int, MatriculaOferta>
     */
    private function matriculasDe(Request $peticion)
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        return MatriculaOferta::query()
            ->where('persona_id', $usuario->persona_id)
            ->orderBy('matricula')
            ->get();
    }

    private function exigirQueSeaSuya(Request $peticion, SolicitudServicio $solicitud): void
    {
        abort_unless(
            $this->matriculasDe($peticion)->contains('id', $solicitud->matricula_oferta_id),
            403,
        );
    }

    /** @return array<string, mixed> */
    private function paraElAlumno(SolicitudServicio $solicitud): array
    {
        return [
            'id' => $solicitud->id,
            'servicio' => $solicitud->servicio?->nombre,
            'estado' => $solicitud->estado,
            /*
             * «Esperando pago» se calcula, no se guarda.
             *
             * El adeudo es el único sitio donde vive la verdad sobre lo cobrado.
             * Guardarlo aquí crearía un segundo, y en cuanto un pago se aplique
             * desde otra pantalla los dos empezarían a decir cosas distintas.
             */
            'esperando_pago' => $solicitud->esperandoPago(),
            'saldo' => $solicitud->adeudo?->saldo(),
            'adeudo_id' => $solicitud->adeudo_id,
            // Para llevar a pagar a SU estado de cuenta. La cuenta se abre por
            // matrícula (`/finanzas/cuentas/{matricula}`), así que el id tiene
            // que viajar: sin él la pantalla tendría que inventarse la
            // dirección, que es como se acaba enlazando a un 404.
            'matricula_oferta_id' => $solicitud->matricula_oferta_id,
            'respuesta' => $solicitud->respuesta,
            'cancelable' => $solicitud->esCancelable(),
            'pedida_en' => $solicitud->created_at?->toDateString(),
        ];
    }
}
