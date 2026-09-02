<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Academico\Campus;
use App\Models\Finanzas\Caja;
use App\Models\Finanzas\SesionCaja;
use App\Services\Caja\OperacionDeCaja;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Las cajas de la escuela y el turno de quien cobra.
 *
 * Son dos oficios y por eso son dos permisos: `gestionar-cajas` decide qué
 * mostradores existen —la dirección, una vez— y `operar-caja` abre y cierra el
 * turno —quien cobra, todos los días—. Y `autorizar-corte-caja` es un tercero,
 * porque quien cuenta el dinero no puede autorizarse su propio faltante.
 */
class CajaController extends Controller
{
    use AcotaPorCampus;

    public function __construct(private readonly OperacionDeCaja $caja) {}

    // ------------------------------------------------------------ Catálogo

    public function index(Request $peticion): Response
    {
        $consulta = Caja::query()->with('campus:id,nombre')->orderBy('nombre');

        // Una caja es un mostrador de un campus: quien está acotado a un
        // plantel no administra los de otro.
        $alcance = $this->alcanceCampus($peticion);

        if ($alcance !== null) {
            $consulta->whereIn('campus_id', $alcance);
        }

        return Inertia::render('Finanzas/Cajas', [
            'cajas' => $consulta->get()->map(fn (Caja $c) => [
                'id' => $c->id,
                'clave' => $c->clave,
                'nombre' => $c->nombre,
                'campus' => $c->campus?->nombre,
                'campus_id' => $c->campus_id,
                'activa' => $c->activa,
                // Con un turno abierto no se apaga: el dinero de ese turno
                // todavía no se ha contado.
                'con_turno_abierto' => $c->sesiones()->abiertas()->exists(),
            ])->values(),
            'campus' => $this->campusDisponibles($peticion),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        Caja::create($this->validar($peticion));

        return back()->with('exito', 'Caja creada.');
    }

    public function update(Request $peticion, Caja $caja): RedirectResponse
    {
        $datos = $this->validar($peticion, $caja);

        // Apagar una caja con turno abierto dejaría ese efectivo sin poder
        // contarse: el cierre exige la caja, y el arqueo no se puede hacer
        // sobre algo que ya no existe.
        if (! ($datos['activa'] ?? true) && $caja->sesiones()->abiertas()->exists()) {
            return back()->with('error', 'Esa caja tiene un turno abierto. Ciérralo antes de apagarla.');
        }

        $caja->update($datos);

        return back()->with('exito', 'Caja actualizada.');
    }

    // ----------------------------------------------------------- Operación

    public function operacion(Request $peticion): Response
    {
        $usuario = $peticion->user();
        $sesion = $this->caja->sesionDe($usuario);
        $alcance = $this->alcanceCampus($peticion);

        return Inertia::render('Finanzas/Caja', [
            'sesion' => $sesion === null ? null : $this->resumenSesion($sesion),
            // Sólo las que no tienen turno abierto: ofrecer una ocupada sería
            // ofrecer un botón que el servidor va a rechazar.
            'disponibles' => Caja::query()
                ->activas()
                ->when($alcance !== null, fn ($q) => $q->whereIn('campus_id', $alcance))
                ->whereDoesntHave('sesiones', fn ($q) => $q->abiertas())
                ->with('campus:id,nombre')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Caja $c) => ['id' => $c->id, 'nombre' => $c->nombre, 'campus' => $c->campus?->nombre])
                ->values(),
            'cortes' => $this->caja->cortes($alcance)->map(fn (SesionCaja $s) => [
                'id' => $s->id,
                'caja' => $s->caja?->nombre,
                'campus' => $s->caja?->campus?->nombre,
                'usuario' => $s->usuario?->persona?->nombreCompleto() ?? $s->usuario?->usuario,
                'abierta_en' => $s->abierta_en?->toDateTimeString(),
                'cerrada_en' => $s->cerrada_en?->toDateTimeString(),
                'estatus' => $s->estatus,
                'fondo_inicial' => (float) $s->fondo_inicial,
                'devuelto' => $this->caja->devuelto($s),
                'efectivo_esperado' => $s->efectivo_esperado === null ? null : (float) $s->efectivo_esperado,
                'efectivo_contado' => $s->efectivo_contado === null ? null : (float) $s->efectivo_contado,
                'diferencia' => $s->diferencia === null ? null : (float) $s->diferencia,
                // El signo solo no se lee: «−150» no dice si falta o sobra.
                'sentido' => $s->sentidoDeLaDiferencia(),
                'motivo_diferencia' => $s->motivo_diferencia,
                'notas' => $s->notas,
            ])->values(),
            'puedeAutorizar' => $peticion->user()->can('autorizar-corte-caja'),
            'porAutorizar' => $this->caja->porAutorizar($alcance),
        ]);
    }

    public function abrir(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'caja_id' => ['required', 'integer', Rule::exists('cajas', 'id')],
            'fondo_inicial' => ['required', 'numeric', 'min:0'],
        ]);

        $caja = Caja::findOrFail((int) $datos['caja_id']);

        // El id viaja en la petición: sin comprobarlo, quien está acotado a un
        // campus abriría la caja de otro plantel.
        $this->autorizarCampus($peticion, $caja->campus_id);

        try {
            // Se convierte a propósito: `numeric` ACEPTA la cadena «500» y la
            // devuelve como cadena, y el servicio la tipa.
            $this->caja->abrir($caja, $peticion->user(), (float) $datos['fondo_inicial']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', 'Turno de caja abierto.');
    }

    public function cerrar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'efectivo_contado' => ['required', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $sesion = $this->caja->sesionDe($peticion->user());

        if ($sesion === null) {
            return back()->with('error', 'No tienes ningún turno abierto.');
        }

        try {
            $cerrada = $this->caja->cerrar(
                $sesion, $peticion->user(), (float) $datos['efectivo_contado'], $datos['notas'] ?? null
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            $cerrada->estatus === SesionCaja::POR_AUTORIZAR ? 'advertencia' : 'exito',
            $cerrada->estatus === SesionCaja::POR_AUTORIZAR
                ? 'El corte quedó con una diferencia de '.number_format(abs((float) $cerrada->diferencia), 2)
                    .' ('.$cerrada->sentidoDeLaDiferencia().'). Falta que alguien la explique y la autorice.'
                : 'Turno cerrado y cuadrado.'
        );
    }

    public function autorizar(Request $peticion, SesionCaja $sesion): RedirectResponse
    {
        // El permiso se comprueba AQUÍ y no en la ruta: por esta pantalla
        // entran el cajero y el supervisor, y un middleware con el permiso del
        // segundo rebotaría al primero de la pantalla entera.
        abort_unless($peticion->user()->can('autorizar-corte-caja'), 403);

        $sesion->loadMissing('caja');
        $this->autorizarCampus($peticion, $sesion->caja?->campus_id);

        // Quien cuenta el dinero no autoriza su propio faltante: sin esto, el
        // permiso de supervisión sobre la propia caja convierte el corte en una
        // formalidad.
        if ($sesion->usuario_id === $peticion->user()->id) {
            return back()->with('error', 'No puedes autorizar la diferencia de tu propio turno.');
        }

        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->caja->autorizar($sesion, $peticion->user(), $datos['motivo']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', 'Diferencia autorizada.');
    }

    // -------------------------------------------------------------- Interno

    /**
     * @return array<string, mixed>
     */
    private function resumenSesion(SesionCaja $sesion): array
    {
        $totales = $this->caja->totales($sesion);

        return [
            'id' => $sesion->id,
            'caja' => $sesion->caja?->nombre,
            'campus' => $sesion->caja?->campus?->nombre,
            'abierta_en' => $sesion->abierta_en?->toDateTimeString(),
            'fondo_inicial' => (float) $sesion->fondo_inicial,
            'totales' => $totales,
            // Lo que salió del cajón. Va aparte de los totales porque no es un
            // cobro: es la otra mitad de la cuenta del arqueo.
            'devuelto' => $this->caja->devuelto($sesion),
            'efectivo_esperado' => $this->caja->efectivoEsperado($sesion),
            'cobros' => $sesion->pagos()->count(),
        ];
    }

    /**
     * @return array<int, array{id: int, nombre: string}>
     */
    private function campusDisponibles(Request $peticion): array
    {
        $alcance = $this->alcanceCampus($peticion);

        return Campus::query()
            ->when($alcance !== null, fn ($q) => $q->whereIn('id', $alcance))
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Campus $c) => ['id' => $c->id, 'nombre' => $c->nombre])
            ->all();
    }

    /** Lo que el rol NO alcanza no se toca, aunque el id llegue en la petición. */
    private function autorizarCampus(Request $peticion, ?int $campusId): void
    {
        $alcance = $this->alcanceCampus($peticion);

        abort_if($alcance !== null && ! in_array($campusId, $alcance, true), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $peticion, ?Caja $caja = null): array
    {
        $datos = $peticion->validate([
            'clave' => ['required', 'string', 'max:30', Rule::unique('cajas', 'clave')->ignore($caja?->id)],
            'nombre' => ['required', 'string', 'max:100'],
            'campus_id' => ['required', 'integer', Rule::exists('campus', 'id')],
            'activa' => ['boolean'],
        ]);

        $this->autorizarCampus($peticion, (int) $datos['campus_id']);

        return [
            'clave' => $datos['clave'],
            'nombre' => $datos['nombre'],
            'campus_id' => (int) $datos['campus_id'],
            'activa' => $peticion->boolean('activa'),
        ];
    }
}
