<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\VeLaCarteraDelAlumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\CuentaBancaria;
use App\Services\RevisorDeComprobantes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagar por transferencia directa, sin pasarela.
 *
 * ── Las dos formas de pagar, y en qué se diferencian ───────────────────────
 * Con **pasarela** el dinero lo confirma el banco: el cargo se liquida solo y
 * nadie de la escuela tiene que hacer nada. Con **transferencia directa** no
 * hay quien confirme, así que quien pagó sube su comprobante y alguien de la
 * escuela lo valida. Es más trabajo, pero no cuesta comisión y funciona en
 * escuelas que no tienen pasarela contratada.
 *
 * Lo que NO cambia entre las dos: un cargo sólo se liquida cuando hay una
 * confirmación de verdad. Ahí la confirmación es una persona.
 *
 * ── Quién puede subir y quién puede aprobar ────────────────────────────────
 * Subir lo hace quien puede ver esa cuenta —el alumno la suya, el padre la de
 * su hijo—, con el mismo trait que lo decide todo. Aprobar pide
 * `registrar-pagos`, porque aprobar es cobrar.
 */
class ComprobantePagoController extends Controller
{
    use AcotaPorCampus;
    use VeLaCarteraDelAlumno;

    public function __construct(private readonly RevisorDeComprobantes $revisor) {}

    /**
     * Sube el comprobante de una transferencia ya hecha.
     */
    public function guardar(Request $request, MatriculaOferta $matricula): RedirectResponse
    {
        $this->exigirQuePuedaVerLaCuenta($request, $matricula);

        $datos = $request->validate([
            'cuenta_bancaria_id' => ['nullable', 'integer'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha_transferencia' => ['required', 'date', 'before_or_equal:today'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'adeudo_ids' => ['nullable', 'array'],
            'adeudo_ids.*' => ['integer'],
            /*
             * Se aceptan imágenes y PDF porque es lo que sale del banco: una
             * captura de pantalla del móvil o el comprobante descargado.
             */
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'fecha_transferencia.before_or_equal' => 'La fecha de la transferencia no puede ser futura.',
            'archivo.mimes' => 'El comprobante tiene que ser una imagen o un PDF.',
            'archivo.max' => 'El comprobante no puede pesar más de 5 MB.',
        ]);

        /*
         * Los cargos se filtran a los de ESTE alumno y que sigan abiertos: los
         * ids vienen del navegador, y sin filtrar se podría declarar que se está
         * pagando la deuda de otro.
         */
        $adeudos = $this->adeudosDe($matricula, $datos['adeudo_ids'] ?? []);

        // Disco privado: un comprobante trae nombre, banco y a veces número de
        // cuenta de una persona.
        $ruta = $request->file('archivo')->store("comprobantes/{$matricula->id}", 'local');

        ComprobantePago::create([
            'matricula_oferta_id' => $matricula->id,
            'cuenta_bancaria_id' => $datos['cuenta_bancaria_id'] ?? null,
            'monto' => $datos['monto'],
            'fecha_transferencia' => $datos['fecha_transferencia'],
            'referencia' => $datos['referencia'] ?? null,
            'archivo' => $ruta,
            'adeudo_ids' => $adeudos,
        ]);

        return back()->with(
            'exito',
            'Recibimos tu comprobante. La escuela lo revisa y, en cuanto lo valide, el cargo queda pagado.',
        );
    }

    /**
     * El archivo, sólo para quien puede ver esa cuenta o cobra.
     *
     * Nunca desde una URL pública: son datos financieros de una persona.
     */
    public function archivo(Request $request, ComprobantePago $comprobante)
    {
        $matricula = $comprobante->matriculaOferta;

        AvisoParaElUsuario::aMenosQue($matricula !== null, 404, 'Ese comprobante ya no existe.');

        // Quien cobra puede ver cualquiera; el resto, sólo los de su cuenta.
        if (! $request->user()->can('registrar-pagos')) {
            $this->exigirQuePuedaVerLaCuenta($request, $matricula);
        }

        AvisoParaElUsuario::aMenosQue(
            Storage::disk('local')->exists($comprobante->archivo),
            404,
            'El archivo del comprobante ya no está.',
        );

        return Storage::disk('local')->response($comprobante->archivo);
    }

    // ── Revisión (escuela) ─────────────────────────────────────────────────

    /** La cola de comprobantes por revisar. */
    public function index(Request $request): Response
    {
        $estado = $request->query('estado', ComprobantePago::PENDIENTE);

        $comprobantes = ComprobantePago::query()
            ->with(['matriculaOferta.persona', 'matriculaOferta.oferta.carrera:id,nombre', 'cuenta:id,nombre,banco', 'revisor.persona'])
            ->when(
                in_array($estado, [ComprobantePago::PENDIENTE, ComprobantePago::APROBADO, ComprobantePago::RECHAZADO], true),
                fn ($q) => $q->where('estado', $estado),
            )
            // Lo más viejo primero: quien lleva más esperando cobra antes.
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (ComprobantePago $c) => [
                'id' => $c->id,
                'alumno' => $c->matriculaOferta?->persona?->nombreCompleto(),
                'matricula' => $c->matriculaOferta?->matricula,
                'carrera' => $c->matriculaOferta?->oferta?->carrera?->nombre,
                'cuenta' => $c->cuenta?->nombre,
                'banco' => $c->cuenta?->banco,
                'monto' => (float) $c->monto,
                'fecha' => $c->fecha_transferencia?->toDateString(),
                'referencia' => $c->referencia,
                'estado' => $c->estado,
                'motivo_rechazo' => $c->motivo_rechazo,
                'revisor' => $c->revisor?->persona?->nombreCompleto(),
                'revisado_en' => $c->revisado_en?->toDateTimeString(),
                'subido_en' => $c->created_at?->toDateTimeString(),
                'cargos' => count($c->adeudo_ids ?? []),
            ]);

        return Inertia::render('Finanzas/Comprobantes', [
            'comprobantes' => $comprobantes,
            'estado' => $estado,
            'pendientes' => ComprobantePago::pendientes()->count(),
        ]);
    }

    public function aprobar(Request $request, ComprobantePago $comprobante): RedirectResponse
    {
        $datos = $request->validate([
            // Se puede corregir al revisar: el banco manda sobre lo declarado.
            'monto' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $this->revisor->aprobar($comprobante, $request->user(), $datos['monto'] ?? null);

        return back()->with('exito', 'Comprobante aprobado: el pago quedó registrado y aplicado a los cargos.');
    }

    public function rechazar(Request $request, ComprobantePago $comprobante): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Escribe por qué se rechaza: quien pagó necesita saber qué corregir.',
        ]);

        $this->revisor->rechazar($comprobante, $request->user(), $datos['motivo']);

        return back()->with('advertencia', 'Comprobante rechazado. Se le avisa el motivo a quien lo subió.');
    }

    // ── Interno ────────────────────────────────────────────────────────────

    /**
     * Los cargos abiertos de ESTA matrícula entre los elegidos.
     *
     * @param  array<int, int>  $elegidos
     * @return array<int, int>
     */
    private function adeudosDe(MatriculaOferta $matricula, array $elegidos): array
    {
        if ($elegidos === []) {
            return [];
        }

        return Adeudo::query()
            ->deMatricula($matricula->id)
            ->porCobrar()
            ->whereIn('id', $elegidos)
            ->pluck('id')
            ->all();
    }
}
