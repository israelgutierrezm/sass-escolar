<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Services\Familia\EntregaDocumentos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El padre o tutor entrega los documentos de su hijo MENOR de edad.
 *
 * Decisión del cliente (2026-08-31). La lista, las tres capas de permiso y la
 * escritura viven en {@see EntregaDocumentos}, que comparte con el portal de la
 * familia y con la API de la app: la pantalla necesita las mismas respuestas
 * para dibujarse, y con la regla escrita dos veces acabaría ofreciendo lo que el
 * servidor rechaza.
 *
 * ── Escribe en la MISMA tabla del alumno ───────────────────────────────────
 * El acta de nacimiento de un alumno es de su expediente, la haya subido él o
 * su madre. Quién lo entregó lo dice la auditoría (`created_by`).
 */
class DocumentosDelHijoController extends Controller
{
    public function __construct(private readonly EntregaDocumentos $entrega) {}

    public function subir(Request $peticion, Persona $hijo): RedirectResponse
    {
        $this->exigirPoderEntregar($peticion, $hijo);

        $datos = $peticion->validate([
            /*
             * El `exists` va acotado al ÁMBITO ALUMNO. Sin eso, el id de un
             * documento de aspirante o de tutor pasa la validación y acaba en el
             * expediente del alumno: el desplegable de la pantalla no es defensa.
             */
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

        $error = $this->entrega->subir(
            $hijo,
            (int) $datos['documento_id'],
            $peticion->file('archivo'),
            $datos['descripcion'] ?? null,
            $datos['vigencia'] ?? null,
        );

        return $error !== null
            ? back(303)->with('error', $error)
            : back(303)->with('exito', 'Documento cargado. Queda pendiente de revisión.');
    }

    public function descargar(Request $peticion, Persona $hijo, DocumentoAlumno $documento): StreamedResponse
    {
        $this->exigirPoderEntregar($peticion, $hijo);
        $this->entrega->exigirDeEseHijo($hijo, $documento);

        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download($documento->url);
    }

    public function eliminar(Request $peticion, Persona $hijo, DocumentoAlumno $documento): RedirectResponse
    {
        $this->exigirPoderEntregar($peticion, $hijo);
        $this->entrega->exigirDeEseHijo($hijo, $documento);

        $error = $this->entrega->eliminar($documento);

        return $error !== null
            ? back(303)->with('error', $error)
            : back(303)->with('exito', 'Documento eliminado.');
    }

    /** Busca el vínculo de este tutor con el hijo y aplica las tres capas. */
    private function exigirPoderEntregar(Request $peticion, Persona $hijo): void
    {
        $vinculo = TutorAlumno::query()
            ->where('tutor_persona_id', $peticion->user()?->persona_id)
            ->where('alumno_persona_id', $hijo->id)
            ->first();

        $this->entrega->exigirPoderEntregar($vinculo, $hijo);
    }
}
