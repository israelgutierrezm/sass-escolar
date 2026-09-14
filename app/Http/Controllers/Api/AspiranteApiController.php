<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResuelveMiSolicitud;
use App\Http\Controllers\Controller;
use App\Services\Admisiones\AutoservicioSolicitud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El portal del ASPIRANTE (interesado) para la app: su solicitud de admisión.
 *
 * ── Una sola verdad ─────────────────────────────────────────────────────────
 * Todo sale de `AutoservicioSolicitud`, el mismo servicio que la web
 * (`PortalAspiranteController` delega en él): el avance de los cuatro pasos, sus
 * datos, los documentos, los cargos y los formularios que le tocan. Las reglas
 * de validación de los datos también son las del servicio, así que la app no
 * deja pasar lo que la web rechaza.
 *
 * ── El alcance sale de la SESIÓN, no de la URL ─────────────────────────────
 * `api.faceta:aspirante` fija el rol activo; la solicitud es la de la persona
 * autenticada (`ResuelveMiSolicitud`), así que no hay id que cambiar para ver la
 * de otro. Quien conserva el rol pero ya se convirtió en alumno no tiene
 * solicitud abierta → 404.
 */
class AspiranteApiController extends Controller
{
    use ResuelveMiSolicitud;

    public function __construct(private readonly AutoservicioSolicitud $autoservicio) {}

    /** El panorama de la solicitud: avance, datos, documentos, cargos y formularios. */
    public function solicitud(Request $peticion): JsonResponse
    {
        return response()->json($this->autoservicio->panorama($this->miSolicitud($peticion)));
    }

    /** Guarda los datos del interesado (misma validación y regla que la web). */
    public function guardarDatos(Request $peticion): JsonResponse
    {
        $aspirante = $this->miSolicitud($peticion);

        $datos = $peticion->validate(
            $this->autoservicio->reglasDatos($aspirante->persona_id, $peticion->input('curp')),
            $this->autoservicio->mensajesDatos(),
        );

        $this->autoservicio->guardarDatos($aspirante, $datos);

        return response()->json(['ok' => true]);
    }

    /** Sube (o reemplaza) un documento del expediente; reinicia su revisión. */
    public function subirDocumento(Request $peticion): JsonResponse
    {
        $aspirante = $this->miSolicitud($peticion);

        $datos = $peticion->validate([
            'documento_id' => ['required', 'integer', Rule::exists('documentos_requeridos', 'id')->whereNull('deleted_at')],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'archivo.max' => 'El archivo no puede pasar de 5 MB.',
            'archivo.mimes' => 'Solo se aceptan PDF o imágenes.',
        ]);

        $this->autoservicio->subirDocumento($aspirante, (int) $datos['documento_id'], $peticion->file('archivo'));

        return response()->json(['ok' => true]);
    }
}
