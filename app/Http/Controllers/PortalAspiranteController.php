<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveMiSolicitud;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Services\Admisiones\AutoservicioSolicitud;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El portal del interesado: llenar sus datos, subir sus papeles y ver lo que
 * debe.
 *
 * Todo lo que hace aquí lo puede hacer también un administrador desde la ficha
 * del aspirante — es el mismo expediente, no una copia—. Este portal existe
 * para que el dueño de la información pueda adelantarla él mismo.
 *
 * **No mueve la etapa del CRM.** El avance que calcula `ProgresoSolicitud` es
 * del EXPEDIENTE; el embudo lo sigue moviendo captación con su criterio. Un
 * aspirante que subió todo no está "listo" hasta que alguien lo revise.
 *
 * Alcance: siempre el aspirante de la persona autenticada. No recibe id por la
 * URL, así que no hay forma de pedir el expediente de otro.
 */
class PortalAspiranteController extends Controller
{
    use ResuelveMiSolicitud;

    public function __construct(private readonly AutoservicioSolicitud $autoservicio) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Portal/Solicitud', $this->autoservicio->panorama($this->miSolicitud($request)));
    }

    public function guardarDatos(Request $request): RedirectResponse
    {
        $aspirante = $this->miSolicitud($request);

        $datos = $request->validate(
            $this->autoservicio->reglasDatos($aspirante->persona_id, $request->input('curp')),
            $this->autoservicio->mensajesDatos(),
        );

        $this->autoservicio->guardarDatos($aspirante, $datos);

        return back()->with('exito', 'Tus datos quedaron guardados.');
    }

    public function subirDocumento(Request $request): RedirectResponse
    {
        $aspirante = $this->miSolicitud($request);

        $datos = $request->validate([
            'documento_id' => ['required', 'integer', Rule::exists('documentos_requeridos', 'id')->whereNull('deleted_at')],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $this->autoservicio->subirDocumento($aspirante, (int) $datos['documento_id'], $request->file('archivo'));

        return back()->with('exito', 'Documento cargado. Alguien de la escuela lo va a revisar.');
    }

    /** Descarga de un documento propio. Nunca de otro: se filtra por aspirante. */
    public function descargarDocumento(Request $request, ExpedienteDocumento $documento): StreamedResponse
    {
        $aspirante = $this->miSolicitud($request);

        abort_unless($documento->aspirante_id === $aspirante->id, 403);
        abort_unless($documento->url !== null && Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download($documento->url);
    }
}
