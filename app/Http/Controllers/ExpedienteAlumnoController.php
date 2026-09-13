<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\Alumno;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use App\Services\ControlEscolar\DocumentosDelAlumno;
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
 * Lo que el alumno NO controla: su matrícula, su programa académico y su situación las
 * administra control escolar, y el estado de revisión de cada documento lo
 * decide quien valida. Subir un acta no es acreditarla.
 */
class ExpedienteAlumnoController extends Controller
{
    public function __construct(private readonly DocumentosDelAlumno $documentos) {}

    public function show(Request $request): Response
    {
        $alumno = $this->miAlumno($request);
        $alumno->load(['persona', 'situacion:id,nombre', 'matriculas.oferta.programaAcademico:id,nombre', 'matriculas.oferta.campus:id,nombre']);

        $persona = $alumno->persona;
        $papeles = $this->documentos->datos($alumno->persona_id);

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
             * programas académicos a la vez, y decirle sólo una sería mentirle a medias.
             */
            'inscripciones' => $alumno->matriculas->map(fn ($m) => [
                'matricula' => $m->matricula,
                'programa_academico' => $m->oferta?->programaAcademico?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
            ])->values(),
            'situacion' => $alumno->situacion?->nombre,
            // Los papeles del expediente (con «lo entregó tu tutor» cuando aplica)
            // y el catálogo del ÁMBITO ALUMNO, del servicio compartido con la app.
            'documentos' => $papeles['documentos'],
            'tiposDocumento' => $papeles['tipos'],
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
            'email' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
        ], [], [
            'genero_id' => 'género',
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

        $this->documentos->subir(
            $alumno->persona_id,
            (int) $datos['documento_id'],
            $request->file('archivo'),
            $datos['descripcion'] ?? null,
            $datos['vigencia'] ?? null,
        );

        return back()->with('exito', 'Documento cargado. Queda pendiente de revisión.');
    }

    public function descargar(Request $request, DocumentoAlumno $documento): StreamedResponse
    {
        $this->documentos->exigirDelAlumno($this->miAlumno($request)->persona_id, $documento);

        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download($documento->url);
    }

    public function eliminar(Request $request, DocumentoAlumno $documento): RedirectResponse
    {
        $this->documentos->exigirDelAlumno($this->miAlumno($request)->persona_id, $documento);

        $error = $this->documentos->eliminar($documento);
        if ($error !== null) {
            return back()->with('error', $error);
        }

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
}
