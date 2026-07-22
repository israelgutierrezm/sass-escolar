<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\ControlEscolar\SituacionDocente;
use App\Models\ControlEscolar\TipoDocente;
use App\Models\Identidad\Persona;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use App\Services\Suplantador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogo de docentes: quién da clase en la escuela.
 *
 * Es la contraparte administrativa del portal `/docencia`. Aquí control escolar
 * da de alta al docente, mantiene lo que el docente NO controla —clave, cédula,
 * tipo, situación, campus— y **revisa los documentos que él sube**.
 *
 * Un docente ES una persona: si llega alguien cuya CURP ya está registrada
 * —fue alumno, es tutor, o ya estuvo dado de alta antes— se REUTILIZA esa
 * persona y solo se le crea el registro docente. Es el mismo principio de cero
 * recaptura que rige en admisiones.
 */
class DocenteController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'situacion_id' => $request->query('situacion_id'),
            'tipo_docente_id' => $request->query('tipo_docente_id'),
            'campus_id' => $request->query('campus_id'),
        ];

        $docentes = Docente::query()
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp,email,celular,foto_url',
                'tipoDocente:id,nombre',
                'situacion:id,clave,nombre',
                'campus:id,nombre',
            ])
            ->withCount('asignaturasGrupo')
            ->when($filtros['busqueda'] !== '', fn ($q) => $this->buscar($q, $filtros['busqueda']))
            ->when($filtros['situacion_id'], fn ($q, $id) => $q->where('situacion_id', $id))
            ->when($filtros['tipo_docente_id'], fn ($q, $id) => $q->where('tipo_docente_id', $id))
            ->when($filtros['campus_id'], fn ($q, $id) => $q->whereHas('campus', fn ($c) => $c->where('campus.id', $id)))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Docente $d) => [
                'id' => $d->persona_id,
                'nombre_completo' => $d->persona?->nombreCompleto(),
                'foto' => $d->persona?->urlFoto(),
                'clave_profesor' => $d->clave_profesor,
                'cedula_profesional' => $d->cedula_profesional,
                'curp' => $d->persona?->curp,
                'email' => $d->persona?->email,
                'tipo' => $d->tipoDocente?->nombre,
                'situacion' => $d->situacion?->nombre,
                'situacion_clave' => $d->situacion?->clave,
                'campus' => $d->campus->pluck('nombre')->all(),
                'materias' => $d->asignaturas_grupo_count,
                'documentos_pendientes' => $this->pendientesDe($d->persona_id),
            ]);

        return Inertia::render('Docentes/Index', [
            'docentes' => $docentes,
            'filtros' => $filtros,
            'situaciones' => SituacionDocente::query()->orderBy('id')->get(['id', 'nombre']),
            'tipos' => TipoDocente::query()->orderBy('id')->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puedeGestionar' => $request->user()->can('gestionar-docentes'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Docentes/Formulario', [
            'docente' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $docente = DB::transaction(function () use ($datos) {
            // Cero recaptura: si la CURP ya existe, esa persona se reutiliza.
            $persona = $this->personaExistente($datos['curp'] ?? null);

            if ($persona === null) {
                $persona = Persona::create($this->datosPersona($datos));
            } else {
                // Se completan los huecos sin pisar lo que ya estaba con vacíos.
                $persona->fill(array_filter(
                    $this->datosPersona($datos),
                    fn ($valor) => $valor !== null && $valor !== '',
                ))->save();
            }

            $docente = Docente::updateOrCreate(
                ['persona_id' => $persona->id],
                $this->datosDocente($datos),
            );

            $docente->campus()->sync($datos['campus_ids'] ?? []);

            return $docente;
        });

        return redirect()
            ->route('tenant.escolar.docentes.show', $docente->persona_id)
            ->with('exito', "Docente {$docente->persona->nombreCompleto()} dado de alta.");
    }

    /** Ficha del docente: sus datos, sus materias y su expediente por revisar. */
    public function show(Request $request, Docente $docente): Response
    {
        $docente->load(['persona.sexo', 'persona.genero', 'tipoDocente', 'situacion', 'campus:id,nombre']);

        $materias = AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'grupo:id,clave,ciclo_id,campus_id',
                'grupo.ciclo:id,clave,nombre',
                'grupo.campus:id,nombre',
            ])
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $docente->persona_id))
            ->get()
            ->map(fn (AsignaturaGrupo $ag) => [
                'id' => $ag->id,
                'clave_en_plan' => $ag->planMateria?->clave_en_plan,
                'materia' => $ag->planMateria?->asignatura?->nombre,
                'grupo' => $ag->grupo?->clave,
                'ciclo' => $ag->grupo?->ciclo?->clave,
                'campus' => $ag->grupo?->campus?->nombre,
                'tipo' => $ag->docentes->firstWhere('persona_id', $docente->persona_id)?->pivot?->tipo,
            ])
            ->sortByDesc('ciclo')
            ->values();

        return Inertia::render('Docentes/Detalle', [
            'docente' => [
                'id' => $docente->persona_id,
                'clave_profesor' => $docente->clave_profesor,
                'cedula_profesional' => $docente->cedula_profesional,
                'tipo_docente_id' => $docente->tipo_docente_id,
                'tipo' => $docente->tipoDocente?->nombre,
                'situacion_id' => $docente->situacion_id,
                'situacion' => $docente->situacion?->nombre,
                'edicion_contenido' => $docente->edicion_contenido,
                'campus_ids' => $docente->campus->pluck('id')->all(),
                'campus' => $docente->campus->pluck('nombre')->all(),
            ],
            'persona' => [
                'id' => $docente->persona?->id,
                'nombre' => $docente->persona?->nombre,
                'primer_apellido' => $docente->persona?->primer_apellido,
                'segundo_apellido' => $docente->persona?->segundo_apellido,
                'curp' => $docente->persona?->curp,
                'rfc' => $docente->persona?->rfc,
                'fecha_nacimiento' => $docente->persona?->fecha_nacimiento?->toDateString(),
                'sexo_id' => $docente->persona?->sexo_id,
                'genero_id' => $docente->persona?->genero_id,
                'email' => $docente->persona?->email,
                'correo_institucional' => $docente->persona?->correo_institucional,
                'celular' => $docente->persona?->celular,
                'foto' => $docente->persona?->urlFoto(),
            ],
            'materias' => $materias,
            'documentos' => DocumentoDocente::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre'])
                ->where('persona_id', $docente->persona_id)
                ->get()
                ->map(fn (DocumentoDocente $d) => [
                    'id' => $d->id,
                    'documento' => $d->documento?->nombre,
                    'descripcion' => $d->descripcion,
                    'estado_id' => $d->estado_documento_id,
                    'estado' => $d->estado?->nombre,
                    'estado_clave' => $d->estado?->clave,
                    'vigencia' => $d->vigencia?->toDateString(),
                    'vencido' => $d->estaVencido(),
                    'observaciones' => $d->observaciones,
                    'subido' => $d->created_at?->format('d/m/Y'),
                ]),
            'estadosDocumento' => EstadoDocumento::query()->orderBy('id')->get(['id', 'clave', 'nombre']),
            ...$this->catalogos(),
            'puedeGestionar' => $request->user()->can('gestionar-docentes'),
            'suplantable' => app(Suplantador::class)->datosPara($request, $docente->persona),
        ]);
    }

    public function update(Request $request, Docente $docente): RedirectResponse
    {
        $datos = $this->validar($request, $docente);

        DB::transaction(function () use ($docente, $datos): void {
            $docente->persona?->update($this->datosPersona($datos));
            $docente->update($this->datosDocente($datos));
            $docente->campus()->sync($datos['campus_ids'] ?? []);
        });

        return back()->with('exito', 'Docente actualizado.');
    }

    /**
     * Revisa un documento del expediente: aceptarlo o rechazarlo.
     *
     * Es la contraparte de la carga que hace el docente. Sin esta pantalla, lo
     * que sube se queda "pendiente" para siempre y el expediente no acredita
     * nada.
     */
    public function revisarDocumento(Request $request, Docente $docente, DocumentoDocente $documento): RedirectResponse
    {
        abort_unless($documento->persona_id === $docente->persona_id, 404);

        $datos = $request->validate([
            'estado_documento_id' => ['required', 'integer', Rule::exists('estados_documento', 'id')->whereNull('deleted_at')],
            'observaciones' => ['nullable', 'string', 'max:255'],
        ], [], ['estado_documento_id' => 'estado']);

        $estado = EstadoDocumento::find($datos['estado_documento_id']);

        // Rechazar sin decir por qué obliga al docente a adivinar qué corregir.
        if ($estado?->clave === 'rechazado' && trim((string) ($datos['observaciones'] ?? '')) === '') {
            return back()->withErrors(['observaciones' => 'Explica por qué se rechaza: el docente necesita saber qué corregir.']);
        }

        $documento->update([
            'estado_documento_id' => $datos['estado_documento_id'],
            'observaciones' => $datos['observaciones'] ?? null,
        ]);

        return back()->with('exito', 'Documento revisado.');
    }

    /** Descarga autenticada del documento. Nunca por URL directa. */
    public function descargarDocumento(Docente $docente, DocumentoDocente $documento): StreamedResponse
    {
        abort_unless($documento->persona_id === $docente->persona_id, 404);
        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download(
            $documento->url,
            sprintf('%s - %s', $documento->documento?->nombre ?? 'documento', $docente->persona?->nombreCompleto()),
        );
    }

    /**
     * Dar de baja NO borra: el docente firmó actas y su nombre aparece en el
     * kárdex de sus alumnos. Se cambia su situación.
     */
    public function destroy(Docente $docente): RedirectResponse
    {
        if ($docente->asignaturasGrupo()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene materias asignadas. Cambia su situación a baja.');
        }

        $docente->delete();

        return redirect()->route('tenant.escolar.docentes.index')->with('exito', 'Registro docente eliminado.');
    }

    /*
    |--------------------------------------------------------------------------
    | Apoyo
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Docente>  $query
     * @return Builder<Docente>
     */
    private function buscar(Builder $query, string $termino): Builder
    {
        $like = '%'.str_replace(' ', '%', $termino).'%';

        return $query->where(fn ($q) => $q
            ->where('clave_profesor', 'like', "%{$termino}%")
            ->orWhere('cedula_profesional', 'like', "%{$termino}%")
            ->orWhereHas('persona', fn ($p) => $p
                ->where('curp', 'like', "%{$termino}%")
                ->orWhereRaw("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ?", [$like])));
    }

    /** Cuántos documentos suyos esperan revisión. Se ve en el listado. */
    private function pendientesDe(int $personaId): int
    {
        return DocumentoDocente::query()
            ->where('persona_id', $personaId)
            ->whereHas('estado', fn ($q) => $q->where('clave', 'pendiente'))
            ->count();
    }

    private function personaExistente(?string $curp): ?Persona
    {
        return $curp === null || $curp === ''
            ? null
            : Persona::query()->where('curp', $curp)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Docente $docente = null): array
    {
        $personaId = $docente?->persona_id;

        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'size:18', Rule::unique('personas', 'curp')->ignore($personaId)->whereNull('deleted_at')],
            'rfc' => ['nullable', 'string', 'max:13'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'sexo_id' => ['required', 'integer'],
            'genero_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:150'],
            'correo_institucional' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],

            'clave_profesor' => ['nullable', 'string', 'max:50'],
            'cedula_profesional' => ['nullable', 'string', 'max:30'],
            'tipo_docente_id' => ['nullable', 'integer', Rule::exists('tipos_docente', 'id')->whereNull('deleted_at')],
            'situacion_id' => ['required', 'integer', Rule::exists('situaciones_docente', 'id')->whereNull('deleted_at')],
            'edicion_contenido' => ['required', 'integer', 'min:0', 'max:2'],
            'campus_ids' => ['present', 'array'],
            'campus_ids.*' => ['integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
        ], [
            'curp.size' => 'La CURP tiene 18 caracteres.',
            'curp.unique' => 'Esa CURP ya está registrada en otra persona.',
        ], [
            'sexo_id' => 'sexo',
            'genero_id' => 'género',
            'tipo_docente_id' => 'tipo de docente',
            'situacion_id' => 'situación',
            'campus_ids' => 'campus',
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosPersona(array $datos): array
    {
        return [
            'nombre' => $datos['nombre'],
            'primer_apellido' => $datos['primer_apellido'],
            'segundo_apellido' => $datos['segundo_apellido'] ?? null,
            'curp' => $datos['curp'] ?? null,
            'rfc' => $datos['rfc'] ?? null,
            'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
            'sexo_id' => $datos['sexo_id'],
            'genero_id' => $datos['genero_id'] ?? null,
            'email' => $datos['email'] ?? null,
            'correo_institucional' => $datos['correo_institucional'] ?? null,
            'celular' => $datos['celular'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosDocente(array $datos): array
    {
        return [
            'clave_profesor' => $datos['clave_profesor'] ?? null,
            'cedula_profesional' => $datos['cedula_profesional'] ?? null,
            'tipo_docente_id' => $datos['tipo_docente_id'] ?? null,
            'situacion_id' => $datos['situacion_id'],
            'edicion_contenido' => $datos['edicion_contenido'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'situaciones' => SituacionDocente::query()->orderBy('id')->get(['id', 'nombre']),
            'tipos' => TipoDocente::query()->orderBy('id')->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sexos' => Sexo::query()->orderBy('id')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
        ];
    }
}
