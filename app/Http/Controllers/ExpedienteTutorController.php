<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Identidad\DocumentoTutor;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * «Mis documentos»: el tutor familiar entrega lo que la escuela le pide A ÉL.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * `DocumentoRequerido::AMBITO_TUTOR` está en el catálogo desde el principio y
 * la escuela demo YA lo usa —«Identificación oficial», obligatoria—, pero el
 * portal de la familia sólo mostraba a los hijos: no había dónde entregarla.
 * El requisito existía marcado en el sistema y se cobraba en ventanilla. Es el
 * mismo hueco que tenía el ámbito `alumno` antes de «Mi expediente».
 *
 * ── Son SUS papeles, no los de su hijo ─────────────────────────────────────
 * La identificación del padre, su comprobante de domicilio. Los del alumno
 * tienen su propia pantalla y los sube el alumno. Que un tutor pueda entregar
 * por su hijo menor es otra conversación y necesita una decisión que el modelo
 * no tiene tomada: el vínculo `tutores_alumno` declara qué puede VER —lo
 * académico, lo financiero— y nada sobre qué puede entregar en su nombre.
 * Inventar aquí esa autorización sería decidirlo por la escuela.
 *
 * ── Sube, no valida ────────────────────────────────────────────────────────
 * La regla de siempre: quien entrega no aprueba. El estado lo pone quien revisa
 * desde el lado administrativo, y re-subir reinicia la revisión porque el visto
 * bueno anterior no dice nada del archivo nuevo.
 *
 * Todo es sobre SÍ MISMO: la persona sale de la sesión, nunca de la URL.
 * Los archivos van al disco `local` —que stancl sufija por escuela— y se sirven
 * por ruta autenticada: son datos personales y nunca se exponen desde public/.
 */
class ExpedienteTutorController extends Controller
{
    private const CARPETA = 'tutores';

    public function show(Request $peticion): Response
    {
        $personaId = $this->miPersona($peticion);

        return Inertia::render('Padre/MiExpediente', [
            'documentos' => DocumentoTutor::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre'])
                ->where('persona_id', $personaId)
                ->get()
                ->map(fn (DocumentoTutor $d) => [
                    'id' => $d->id,
                    'documento_id' => $d->documento_id,
                    'documento' => $d->documento?->nombre,
                    'descripcion' => $d->descripcion,
                    'estado' => $d->estado?->nombre,
                    'estado_clave' => $d->estado?->clave,
                    'vigencia' => $d->vigencia?->toDateString(),
                    'vencido' => $d->estaVencido(),
                    'observaciones' => $d->observaciones,
                ]),
            /*
             * Sólo lo que la escuela pide a los TUTORES. Ofrecerle el catálogo
             * entero le pediría el certificado de bachillerato de su hijo.
             */
            'tiposDocumento' => DocumentoRequerido::query()
                ->delAmbito(DocumentoRequerido::AMBITO_TUTOR)
                ->orderByDesc('obligatorio')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'obligatorio'])
                ->map(fn (DocumentoRequerido $d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'obligatorio' => (bool) $d->obligatorio,
                ]),
        ]);
    }

    public function subir(Request $peticion): RedirectResponse
    {
        $personaId = $this->miPersona($peticion);

        $datos = $peticion->validate([
            // `exists` acotado al ÁMBITO: sin eso, el id de un documento de
            // aspirante pasaría la validación y acabaría en el expediente del
            // tutor, donde nadie lo pidió y nadie lo va a revisar.
            'documento_id' => [
                'required',
                'integer',
                function (string $atributo, mixed $valor, callable $falla) {
                    $delAmbito = DocumentoRequerido::query()
                        ->delAmbito(DocumentoRequerido::AMBITO_TUTOR)
                        ->whereKey($valor)
                        ->exists();

                    if (! $delAmbito) {
                        $falla('Ese documento no es de los que la escuela te pide a ti.');
                    }
                },
            ],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
            'descripcion' => ['nullable', 'string', 'max:100'],
            'vigencia' => ['nullable', 'date', 'after:today'],
        ], [
            'vigencia.after' => 'Un documento que ya venció no sirve como comprobante.',
        ]);

        $anterior = DocumentoTutor::query()
            ->where('persona_id', $personaId)
            ->where('documento_id', $datos['documento_id'])
            ->first();

        $ruta = $peticion->file('archivo')->store(
            sprintf('%s/%d', self::CARPETA, $personaId),
            'local',
        );

        DocumentoTutor::updateOrCreate(
            ['persona_id' => $personaId, 'documento_id' => $datos['documento_id']],
            [
                'url' => $ruta,
                'descripcion' => $datos['descripcion'] ?? null,
                'vigencia' => $datos['vigencia'] ?? null,
                // Re-subir reinicia la revisión: el archivo cambió, así que el
                // visto bueno anterior ya no dice nada del nuevo.
                'estado_documento_id' => EstadoDocumento::query()->where('clave', 'pendiente')->value('id'),
                'observaciones' => null,
            ],
        );

        // El archivo viejo se borra del disco: se reemplazó, y guardarlo sólo
        // acumula datos personales que ya nadie va a consultar.
        if ($anterior !== null && $anterior->url !== $ruta) {
            Storage::disk('local')->delete($anterior->url);
        }

        return back(303)->with('exito', 'Documento cargado. Queda pendiente de revisión.');
    }

    public function descargar(Request $peticion, DocumentoTutor $documento): StreamedResponse
    {
        $this->exigirMio($peticion, $documento);

        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download($documento->url);
    }

    public function eliminar(Request $peticion, DocumentoTutor $documento): RedirectResponse
    {
        $this->exigirMio($peticion, $documento);

        /*
         * Lo aceptado no se borra desde aquí.
         *
         * Es la constancia de un trámite ya cerrado: si el tutor pudiera
         * quitarla, el expediente que la escuela dio por bueno cambiaría a sus
         * espaldas. Para corregir algo aprobado se re-sube, y eso vuelve a
         * ponerlo en revisión.
         */
        if ($documento->estado?->clave === 'aceptado') {
            return back(303)->with('error', 'Ese documento ya fue aceptado. Si cambió, súbelo otra vez.');
        }

        Storage::disk('local')->delete($documento->url);
        $documento->delete();

        return back(303)->with('exito', 'Documento eliminado.');
    }

    /**
     * La persona de quien tiene la sesión, si de verdad es tutor de alguien.
     *
     * El permiso dice que puede entrar al portal de la familia; el VÍNCULO dice
     * que tiene a quién. Sin la segunda mitad, un administrativo que se
     * concediera el permiso tendría expediente de tutor sin serlo de nadie.
     */
    private function miPersona(Request $peticion): int
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        $esTutor = $usuario->persona_id !== null
            && TutorAlumno::query()->where('tutor_persona_id', $usuario->persona_id)->exists();

        if (! $esTutor) {
            throw new AccessDeniedHttpException('Tu cuenta no está vinculada a ningún alumno.');
        }

        return (int) $usuario->persona_id;
    }

    /** Que el documento sea suyo: la ruta lleva id y sin esto se leería el ajeno. */
    private function exigirMio(Request $peticion, DocumentoTutor $documento): void
    {
        if ((int) $documento->persona_id !== $this->miPersona($peticion)) {
            throw new AccessDeniedHttpException('Ese documento no es tuyo.');
        }
    }
}
