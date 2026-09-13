<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\ControlEscolar\TituloDocente;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use App\Services\Docencia\DocumentosDelDocente;
use App\Services\GestorTitulosDocente;
use App\Services\ResolutorFormularios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Mi expediente": el docente mantiene sus propios datos y comprobantes.
 *
 * Todo es sobre SÍ MISMO: la persona sale de la sesión, nunca de la URL, así
 * que no hay id que manipular para editar a otro.
 *
 * Los archivos van al disco `local`, que stancl/tenancy sufija por escuela, y
 * se sirven por ruta autenticada: son datos personales sujetos a la LFPDPPP y
 * nunca se exponen desde public/.
 *
 * Lo que el docente NO controla: su clave de profesor, su tipo y su situación
 * los administra control escolar, y el estado de revisión de cada documento lo
 * decide quien valida. Subir un título no es acreditarlo.
 */
class ExpedienteDocenteController extends Controller
{
    public function __construct(private readonly DocumentosDelDocente $documentos) {}

    public function show(Request $request): Response
    {
        $docente = $this->miDocente($request);
        $docente->load(['persona', 'tipoDocente:id,nombre', 'situacion:id,nombre', 'campus:id,nombre', 'titulos']);

        $persona = $docente->persona;
        $papeles = $this->documentos->datos($docente->persona_id);

        return Inertia::render('Docencia/Expediente', [
            'persona' => [
                'nombre' => $persona?->nombre,
                'primer_apellido' => $persona?->primer_apellido,
                'segundo_apellido' => $persona?->segundo_apellido,
                'curp' => $persona?->curp,
                'rfc' => $persona?->rfc,
                'fecha_nacimiento' => $persona?->fecha_nacimiento?->toDateString(),
                'genero_id' => $persona?->genero_id,
                'email' => $persona?->email,
                'correo_institucional' => $persona?->correo_institucional,
                'celular' => $persona?->celular,
                'foto' => $persona?->urlFoto(),
                'persona_id' => $persona?->id,
            ],
            // De solo lectura: lo administra control escolar, no el docente.
            'docente' => [
                'clave_profesor' => $docente->clave_profesor,
                'tipo' => $docente->tipoDocente?->nombre,
                'situacion' => $docente->situacion?->nombre,
                'campus' => $docente->campus->pluck('nombre')->all(),
            ],
            // Sus títulos/grados: los administra él mismo. La URL del archivo
            // apunta a la descarga del autoservicio.
            'titulos' => $docente->titulos->map(fn (TituloDocente $t) => [
                'id' => $t->id,
                'grado' => $t->grado,
                'titulo_obtenido' => $t->titulo_obtenido,
                'cedula' => $t->cedula,
                'institucion' => $t->institucion,
                'anio' => $t->anio,
                'archivo' => $t->archivo_url === null ? null : "/docencia/expediente/titulos/{$t->id}/archivo",
            ]),
            // Los papeles del expediente y el catálogo del ÁMBITO DOCENTE, del
            // servicio compartido con la app móvil (`DocumentosDelDocente`).
            'documentos' => $papeles['documentos'],
            'tiposDocumento' => $papeles['tipos'],
            'sexos' => Sexo::query()->orderBy('id')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
            // Los bloques de datos que le tocan y llena él mismo. El titular es
            // su persona: aquí no hay matrícula de la cual colgarlos.
            'formularios' => $persona === null
                ? []
                : app(ResolutorFormularios::class)->para($persona),
            // Su disponibilidad, que declara él mismo: es quien la sabe.
            ...DisponibilidadDocenteController::datosPara($docente->persona_id),
            'puedeDeclararDisponibilidad' => $request->user()->can('editar-mi-disponibilidad'),
        ]);
    }

    /** Actualiza sus datos de contacto e identidad. */
    public function actualizar(Request $request): RedirectResponse
    {
        $docente = $this->miDocente($request);
        $persona = $docente->persona;

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'size:18', Rule::unique('personas', 'curp')->ignore($persona->id)->whereNull('deleted_at')],
            'rfc' => ['nullable', 'string', 'max:13'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            // Ver ExpedienteAlumnoController: el dato oficial es el género.
            'genero_id' => ['required', 'integer'],
            'email' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
        ], [
            'curp.size' => 'La CURP tiene 18 caracteres.',
            'curp.unique' => 'Esa CURP ya está registrada en otra persona.',
        ], [
            'genero_id' => 'género',
        ]);

        // El correo institucional NO se toca aquí: lo asigna la escuela.
        $persona->update($datos);

        return back()->with('exito', 'Tus datos quedaron actualizados.');
    }

    /** Carga un comprobante. Vuelve a quedar pendiente de revisión. */
    public function subir(Request $request): RedirectResponse
    {
        $docente = $this->miDocente($request);

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
            $docente->persona_id,
            (int) $datos['documento_id'],
            $request->file('archivo'),
            $datos['descripcion'] ?? null,
            $datos['vigencia'] ?? null,
        );

        return back()->with('exito', 'Documento cargado. Queda pendiente de revisión.');
    }

    public function descargar(Request $request, DocumentoDocente $documento): StreamedResponse
    {
        $docente = $this->miDocente($request);

        $this->documentos->exigirDelDocente($docente->persona_id, $documento);
        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download(
            $documento->url,
            sprintf('%s - %s', $documento->documento?->nombre ?? 'documento', $docente->persona?->nombreCompleto()),
        );
    }

    public function eliminar(Request $request, DocumentoDocente $documento): RedirectResponse
    {
        $docente = $this->miDocente($request);

        $this->documentos->exigirDelDocente($docente->persona_id, $documento);

        $error = $this->documentos->eliminar($documento);
        if ($error !== null) {
            return back()->with('error', $error);
        }

        return back()->with('exito', 'Documento eliminado.');
    }

    public function agregarTitulo(Request $request, GestorTitulosDocente $gestor): RedirectResponse
    {
        $docente = $this->miDocente($request);
        $datos = $request->validate($gestor->reglas());
        $gestor->agregar($docente->persona_id, $datos, $request->file('archivo'));

        return back()->with('exito', 'Título agregado.');
    }

    public function quitarTitulo(Request $request, TituloDocente $titulo, GestorTitulosDocente $gestor): RedirectResponse
    {
        $docente = $this->miDocente($request);
        abort_unless($titulo->persona_id === $docente->persona_id, 404);
        $gestor->quitar($titulo);

        return back()->with('exito', 'Título eliminado.');
    }

    public function descargarTitulo(Request $request, TituloDocente $titulo, GestorTitulosDocente $gestor): StreamedResponse
    {
        $docente = $this->miDocente($request);
        abort_unless($titulo->persona_id === $docente->persona_id, 404);

        return $gestor->descargar($titulo);
    }

    /**
     * El docente de la sesión. Si el usuario no está dado de alta como docente
     * no hay expediente que mostrar.
     */
    private function miDocente(Request $request): Docente
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $docente = $usuario->persona_id === null
            ? null
            : Docente::query()->with('persona')->find($usuario->persona_id);

        return $docente ?? AvisoParaElUsuario::lanzar(
            403,
            'Tu cuenta todavía no está dada de alta como docente. Pídele a control escolar que la registre.',
        );
    }
}
