<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Services\Familia\RepresentacionDelTutor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El padre o tutor entrega los documentos de su hijo MENOR de edad.
 *
 * Decisión del cliente (2026-08-31). Hasta hoy `documentos_alumno` sólo lo
 * alimentaba el propio alumno, y en una secundaria eso significa pedirle el
 * acta de nacimiento a un niño de doce años o cobrárselo al padre en
 * ventanilla.
 *
 * ── Escribe en la MISMA tabla, no en una suya ──────────────────────────────
 * El acta de nacimiento de un alumno es del expediente del ALUMNO, la haya
 * subido él o su madre. Con una tabla aparte habría dos sitios donde buscar el
 * mismo papel y dos estados de revisión para el mismo trámite. Quién lo entregó
 * ya lo dice la auditoría (`created_by`), que es de dónde sale la nota que el
 * alumno ve en su propio expediente.
 *
 * ── Tres capas, y ninguna sobra ────────────────────────────────────────────
 *  1. El PERMISO (`ver-mis-hijos`) deja entrar al portal de la familia.
 *  2. El AJUSTE de la escuela dice si este acto existe aquí — apagado, 404.
 *  3. El VÍNCULO y la EDAD dicen si es por ESTE hijo — si no, 403 con su razón.
 *
 * Las tres las resuelve {@see RepresentacionDelTutor}, no este controlador:
 * la pantalla necesita las mismas respuestas para dibujarse, y con la regla
 * escrita dos veces acabaría ofreciendo lo que el servidor rechaza.
 */
class DocumentosDelHijoController extends Controller
{
    /**
     * La misma carpeta que usa «Mi expediente» del alumno.
     *
     * Es el expediente del mismo alumno: separar los archivos por quién los
     * subió obligaría a mirar en dos sitios para reunir un solo expediente.
     */
    private const CARPETA = 'alumnos';

    public function __construct(private readonly RepresentacionDelTutor $representacion) {}

    public function subir(Request $peticion, Persona $hijo): RedirectResponse
    {
        $this->exigirPoderEntregar($peticion, $hijo);

        $datos = $peticion->validate([
            /*
             * El `exists` va acotado al ÁMBITO ALUMNO. Sin eso, el id de un
             * documento de aspirante o de tutor pasa la validación y acaba en
             * el expediente del alumno, donde nadie lo pidió y nadie lo va a
             * revisar: el desplegable de la pantalla no es una defensa.
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

        $anterior = DocumentoAlumno::query()
            ->where('persona_id', $hijo->id)
            ->where('documento_id', $datos['documento_id'])
            ->first();

        /*
         * Lo aceptado no se pisa desde aquí.
         *
         * Al alumno se le niega BORRAR lo aceptado por la misma razón —el
         * expediente que control escolar dio por bueno no puede cambiar a sus
         * espaldas— y re-subir es la salida que se le ofrece. Al tutor no: un
         * documento aprobado y luego reemplazado por otra persona vuelve a
         * revisión sin que el alumno se entere de que su expediente cambió.
         */
        if ($anterior?->estado?->clave === 'aceptado') {
            return back(303)->with('error', 'Ese documento ya fue aceptado. Si cambió, pídelo en control escolar.');
        }

        $ruta = $peticion->file('archivo')->store(
            sprintf('%s/%d', self::CARPETA, $hijo->id),
            'local',
        );

        DocumentoAlumno::updateOrCreate(
            ['persona_id' => $hijo->id, 'documento_id' => $datos['documento_id']],
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

        if ($anterior !== null && $anterior->url !== $ruta) {
            Storage::disk('local')->delete($anterior->url);
        }

        return back(303)->with('exito', 'Documento cargado. Queda pendiente de revisión.');
    }

    public function descargar(Request $peticion, Persona $hijo, DocumentoAlumno $documento): StreamedResponse
    {
        $this->exigirPoderEntregar($peticion, $hijo);
        $this->exigirDeEseHijo($hijo, $documento);

        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download($documento->url);
    }

    public function eliminar(Request $peticion, Persona $hijo, DocumentoAlumno $documento): RedirectResponse
    {
        $this->exigirPoderEntregar($peticion, $hijo);
        $this->exigirDeEseHijo($hijo, $documento);

        if ($documento->estado?->clave === 'aceptado') {
            return back(303)->with('error', 'Ese documento ya fue aceptado y no se puede retirar desde aquí.');
        }

        Storage::disk('local')->delete($documento->url);
        $documento->delete();

        return back(303)->with('exito', 'Documento eliminado.');
    }

    /**
     * Las tres capas, en el orden en que cambian la respuesta.
     *
     * Primero el 404 de la escuela —lo que no está contratado no existe para
     * nadie— y después el 403 de este vínculo, que sí es personal y por eso
     * lleva su razón escrita.
     */
    private function exigirPoderEntregar(Request $peticion, Persona $hijo): void
    {
        abort_unless($this->representacion->laEscuelaPermiteEntregarDocumentos(), 404);

        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        $vinculo = TutorAlumno::query()
            ->where('tutor_persona_id', $usuario->persona_id)
            ->where('alumno_persona_id', $hijo->id)
            ->first();

        $motivo = $this->representacion->motivoParaNoEntregarDocumentos($vinculo, $hijo);

        AvisoParaElUsuario::si($motivo !== null, 403, (string) $motivo);
    }

    /**
     * Que el documento sea de ESE hijo.
     *
     * Las dos ids viajan por la URL. Sin esto, un tutor legítimo de un hijo
     * podría pedir el documento de cualquier alumno poniendo el id del suyo en
     * el primer hueco y el ajeno en el segundo.
     */
    private function exigirDeEseHijo(Persona $hijo, DocumentoAlumno $documento): void
    {
        AvisoParaElUsuario::aMenosQue(
            (int) $documento->persona_id === (int) $hijo->id,
            403,
            'Ese documento no es de tu hijo.',
        );
    }
}
