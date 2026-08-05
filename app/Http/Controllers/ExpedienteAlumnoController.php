<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * "Mi expediente": el alumno mantiene sus propios datos y comprobantes.
 *
 * ── Por qué hacía falta ────────────────────────────────────────────────────
 * El ámbito `alumno` del catálogo de documentos requeridos existía desde el
 * principio y no lo consumía nadie: la escuela podía marcar el acta de
 * nacimiento como obligatoria para alumnos y ese requisito no aparecía en
 * ninguna pantalla. El alumno sólo entregaba papeles durante la admisión —en el
 * portal del aspirante—, y todo lo que la escuela le pidiera después había que
 * cobrárselo en ventanilla.
 *
 * ── Y por qué no reusa el expediente de admisión ───────────────────────────
 * `expediente_documentos` cuelga del ASPIRANTE: es lo que se juntó para decidir
 * si entraba, y se cierra cuando entra. Un alumno de traslado nunca fue
 * aspirante y no tendría dónde guardar nada. Ver {@see DocumentoAlumno}.
 *
 * Todo es sobre SÍ MISMO: la persona sale de la sesión, nunca de la URL, así
 * que no hay id que manipular para editar a otro.
 *
 * Los archivos van al disco `local`, que stancl/tenancy sufija por escuela, y
 * se sirven por ruta autenticada: son datos personales sujetos a la LFPDPPP y
 * nunca se exponen desde public/.
 *
 * Lo que el alumno NO controla: su matrícula, su carrera y su situación las
 * administra control escolar, y el estado de revisión de cada documento lo
 * decide quien valida. Subir un acta no es acreditarla.
 */
class ExpedienteAlumnoController extends Controller
{
    private const CARPETA = 'alumnos';

    public function show(Request $request): Response
    {
        $alumno = $this->miAlumno($request);
        $alumno->load(['persona', 'situacion:id,nombre', 'matriculas.oferta.carrera:id,nombre', 'matriculas.oferta.campus:id,nombre']);

        $persona = $alumno->persona;

        return Inertia::render('MiExpediente/Index', [
            'persona' => [
                'nombre' => $persona?->nombre,
                'primer_apellido' => $persona?->primer_apellido,
                'segundo_apellido' => $persona?->segundo_apellido,
                'curp' => $persona?->curp,
                'rfc' => $persona?->rfc,
                'fecha_nacimiento' => $persona?->fecha_nacimiento?->toDateString(),
                'genero_id' => $persona?->genero_id,
                'genero_id' => $persona?->genero_id,
                'email' => $persona?->email,
                'correo_institucional' => $persona?->correo_institucional,
                'celular' => $persona?->celular,
                'foto' => $persona?->urlFoto(),
                'persona_id' => $persona?->id,
            ],
            /*
             * De solo lectura: lo administra control escolar. Se manda una
             * inscripción por matrícula porque un alumno puede cursar dos
             * carreras a la vez, y decirle sólo una sería mentirle a medias.
             */
            'inscripciones' => $alumno->matriculas->map(fn ($m) => [
                'matricula' => $m->matricula,
                'carrera' => $m->oferta?->carrera?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
            ])->values(),
            'situacion' => $alumno->situacion?->nombre,
            'documentos' => DocumentoAlumno::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre'])
                ->where('persona_id', $alumno->persona_id)
                ->get()
                ->map(fn (DocumentoAlumno $d) => [
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
            // Solo lo que la escuela pide a los ALUMNOS, no el catálogo entero:
            // ofrecerle el del aspirante le pediría cosas que ya entregó al
            // inscribirse.
            'tiposDocumento' => DocumentoRequerido::query()
                ->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'obligatorio'])
                ->map(fn (DocumentoRequerido $d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'obligatorio' => (bool) $d->obligatorio,
                ]),
            'sexos' => Sexo::query()->orderBy('id')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
        ]);
    }

    /** Actualiza sus datos de contacto e identidad. */
    public function actualizar(Request $request): RedirectResponse
    {
        $alumno = $this->miAlumno($request);
        $persona = $alumno->persona;

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'size:18', Rule::unique('personas', 'curp')->ignore($persona->id)->whereNull('deleted_at')],
            'rfc' => ['nullable', 'string', 'max:13'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            // La SEP lo llama GÉNERO (`idGenero` del XML) y es el campo que el
            // certificado lee. Aquí se capturaba `sexo_id`, un duplicado que no
            // llegaba a ningún documento.
            'genero_id' => ['required', 'integer'],
            'genero_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
        ]);

        $persona->update($datos);

        return back()->with('exito', 'Tus datos quedaron actualizados.');
    }

    public function subir(Request $request): RedirectResponse
    {
        $alumno = $this->miAlumno($request);

        $datos = $request->validate([
            'documento_id' => ['required', 'integer', Rule::exists('documentos_requeridos', 'id')->whereNull('deleted_at')],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'descripcion' => ['nullable', 'string', 'max:100'],
            'vigencia' => ['nullable', 'date', 'after:today'],
        ], [
            'archivo.max' => 'El archivo no puede pasar de 5 MB.',
            'archivo.mimes' => 'Solo se aceptan PDF o imágenes.',
            'vigencia.after' => 'Un documento que ya venció no sirve como comprobante.',
        ]);

        $anterior = DocumentoAlumno::query()
            ->where('persona_id', $alumno->persona_id)
            ->where('documento_id', $datos['documento_id'])
            ->first();

        $ruta = $request->file('archivo')->store(
            sprintf('%s/%d', self::CARPETA, $alumno->persona_id),
            'local',
        );

        DocumentoAlumno::updateOrCreate(
            ['persona_id' => $alumno->persona_id, 'documento_id' => $datos['documento_id']],
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

        // El archivo viejo se borra del disco: se reemplazó, y guardarlo solo
        // acumula datos personales que ya nadie va a consultar.
        if ($anterior !== null && $anterior->url !== $ruta) {
            Storage::disk('local')->delete($anterior->url);
        }

        return back()->with('exito', 'Documento cargado. Queda pendiente de revisión.');
    }

    public function descargar(Request $request, DocumentoAlumno $documento): StreamedResponse
    {
        $this->exigirMio($request, $documento);

        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download($documento->url);
    }

    public function eliminar(Request $request, DocumentoAlumno $documento): RedirectResponse
    {
        $this->exigirMio($request, $documento);

        /*
         * Lo aceptado no se borra desde aquí.
         *
         * Es la constancia de un trámite ya cerrado: si el alumno pudiera
         * quitarla, el expediente que control escolar dio por bueno cambiaría a
         * sus espaldas. Para corregir algo aprobado se re-sube, y eso vuelve a
         * ponerlo en revisión.
         */
        if ($documento->estado?->clave === 'aceptado') {
            return back()->with('error', 'Ese documento ya fue aceptado. Si cambió, súbelo otra vez.');
        }

        Storage::disk('local')->delete($documento->url);
        $documento->delete();

        return back()->with('exito', 'Documento eliminado.');
    }

    /** El alumno de quien tiene la sesión, o 403 si quien entra no lo es. */
    private function miAlumno(Request $request): Alumno
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $alumno = $usuario->persona_id === null
            ? null
            : Alumno::query()->whereKey($usuario->persona_id)->first();

        if ($alumno === null) {
            throw new AccessDeniedHttpException('Tu cuenta no está registrada como alumno.');
        }

        return $alumno;
    }

    /** Que el documento sea suyo: la ruta lleva id y sin esto se leería el ajeno. */
    private function exigirMio(Request $request, DocumentoAlumno $documento): void
    {
        $alumno = $this->miAlumno($request);

        if ((int) $documento->persona_id !== (int) $alumno->persona_id) {
            throw new AccessDeniedHttpException('Ese documento no es tuyo.');
        }
    }
}
