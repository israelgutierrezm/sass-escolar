<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\ControlEscolar\TituloDocente;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use App\Services\GestorTitulosDocente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
    private const CARPETA = 'docentes';

    public function show(Request $request): Response
    {
        $docente = $this->miDocente($request);
        $docente->load(['persona', 'tipoDocente:id,nombre', 'situacion:id,nombre', 'campus:id,nombre', 'titulos']);

        $persona = $docente->persona;

        return Inertia::render('Docencia/Expediente', [
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
            'documentos' => DocumentoDocente::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre'])
                ->where('persona_id', $docente->persona_id)
                ->get()
                ->map(fn (DocumentoDocente $d) => [
                    'id' => $d->id,
                    // Con qué tipo cumple: es lo que permite saber cuáles de los
                    // obligatorios siguen sin entregar.
                    'documento_id' => $d->documento_id,
                    'documento' => $d->documento?->nombre,
                    'descripcion' => $d->descripcion,
                    'estado' => $d->estado?->nombre,
                    'estado_clave' => $d->estado?->clave,
                    'vigencia' => $d->vigencia?->toDateString(),
                    'vencido' => $d->estaVencido(),
                    'observaciones' => $d->observaciones,
                ]),
            // Solo lo que la escuela pide a los DOCENTES. Antes se ofrecía el
            // catálogo entero, que era el del aspirante.
            'tiposDocumento' => DocumentoRequerido::query()
                ->delAmbito(DocumentoRequerido::AMBITO_DOCENTE)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'obligatorio'])
                ->map(fn (DocumentoRequerido $d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    // Distinguirlos importa: faltar un obligatorio bloquea, y
                    // faltar uno opcional no es un pendiente que reclamar.
                    'obligatorio' => (bool) $d->obligatorio,
                ]),
            'sexos' => Sexo::query()->orderBy('id')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
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
            'genero_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
        ], [
            'curp.size' => 'La CURP tiene 18 caracteres.',
            'curp.unique' => 'Esa CURP ya está registrada en otra persona.',
        ], [
            'genero_id' => 'género',
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

        $anterior = DocumentoDocente::query()
            ->where('persona_id', $docente->persona_id)
            ->where('documento_id', $datos['documento_id'])
            ->first();

        $ruta = $request->file('archivo')->store(
            sprintf('%s/%d', self::CARPETA, $docente->persona_id),
            'local',
        );

        DocumentoDocente::updateOrCreate(
            ['persona_id' => $docente->persona_id, 'documento_id' => $datos['documento_id']],
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

    public function descargar(Request $request, DocumentoDocente $documento): StreamedResponse
    {
        $docente = $this->miDocente($request);

        abort_unless($documento->persona_id === $docente->persona_id, 404);
        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download(
            $documento->url,
            sprintf('%s - %s', $documento->documento?->nombre ?? 'documento', $docente->persona?->nombreCompleto()),
        );
    }

    public function eliminar(Request $request, DocumentoDocente $documento): RedirectResponse
    {
        $docente = $this->miDocente($request);

        abort_unless($documento->persona_id === $docente->persona_id, 404);

        // Un documento ya aceptado no lo retira el docente: es el comprobante
        // en el que la escuela se apoyó para acreditarlo.
        if ($documento->estado?->clave === 'aceptado') {
            return back()->with('error', 'Ese documento ya fue aceptado; pide a control escolar que lo retire.');
        }

        Storage::disk('local')->delete($documento->url);
        $documento->delete();

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

        return $docente ?? throw new AccessDeniedHttpException(
            'Tu cuenta no está dada de alta como docente.'
        );
    }
}
