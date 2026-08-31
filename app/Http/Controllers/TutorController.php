<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\EstadoDocumento;
use App\Models\Identidad\DocumentoTutor;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Services\ResolutorFormularios;
use App\Services\Suplantador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Directorio de padres y tutores: la vista de administración de las personas
 * vinculadas como padre/tutor a uno o más alumnos.
 *
 * El VÍNCULO en sí (agregar, quitar, permisos) se administra desde el
 * expediente de cada alumno —ahí está el contexto—; aquí se ve el panorama:
 * quién es tutor de quién, y se puede «Ver como» esa persona para dar soporte.
 */
class TutorController extends Controller
{
    public function index(Request $request): Response
    {
        $suplantador = app(Suplantador::class);

        $tutores = TutorAlumno::query()
            ->with([
                'tutor:id,nombre,primer_apellido,segundo_apellido,curp,email',
                'alumno:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->get()
            ->groupBy('tutor_persona_id')
            ->map(function ($vinculos) use ($request, $suplantador) {
                $persona = $vinculos->first()->tutor;

                return [
                    'persona_id' => $persona?->id,
                    'nombre' => $this->nombre($persona),
                    'curp' => $persona?->curp,
                    'email' => $persona?->email,
                    'total_alumnos' => $vinculos->count(),
                    'alumnos' => $vinculos->map(fn (TutorAlumno $v) => [
                        'nombre' => $this->nombre($v->alumno),
                        'parentesco' => $v->parentesco?->nombre,
                    ])->values(),
                    // «Ver como»: solo si esa persona tiene cuenta con la que entrar.
                    'suplantable' => $suplantador->datosPara($request, $persona),
                ];
            })
            ->sortBy('nombre')
            ->values();

        return Inertia::render('Padres/Index', [
            'tutores' => $tutores,
        ]);
    }

    /**
     * Expediente del padre o tutor: quién es, de quién es tutor y qué puede ver
     * de cada uno.
     *
     * Existía el directorio y el «ver como», pero no había dónde entrar: para
     * saber qué ve un padre que llama por teléfono había que suplantarlo. Aquí
     * está lo mismo sin tener que hacerlo —los permisos por hijo son el dato que
     * contesta la mayoría de esas llamadas—.
     *
     * Los vínculos NO se editan aquí, igual que en el directorio: se agregan y
     * se quitan desde el expediente del alumno, que es donde está el contexto de
     * a quién se le está dando acceso.
     */
    public function show(Request $request, Persona $tutor): Response
    {
        $vinculos = TutorAlumno::query()
            ->with(['alumno:id,nombre,primer_apellido,segundo_apellido,curp'])
            ->where('tutor_persona_id', $tutor->id)
            ->get();

        // Sin un solo vínculo no es tutor de nadie: la ficha no tendría de qué
        // hablar y el id vendría de una URL escrita a mano.
        abort_if($vinculos->isEmpty(), 404);

        $usuario = Usuario::query()->where('persona_id', $tutor->id)->first();

        return Inertia::render('Padres/Detalle', [
            'tutor' => [
                'persona_id' => $tutor->id,
                'nombre' => $this->nombre($tutor),
                /*
                 * Los datos de identidad, con la misma forma que en la ficha
                 * del alumno y la del docente.
                 *
                 * Antes viajaban tres sueltos —CURP, correo y celular— y la
                 * ficha se veía como otra pantalla del sistema. Toda persona
                 * tiene estos datos: el encabezado es el mismo se entre por
                 * donde se entre, y quien atiende una llamada no debe aprender
                 * a leer una ficha distinta según a quién esté buscando.
                 */
                'nombre_pila' => $tutor->nombre,
                'primer_apellido' => $tutor->primer_apellido,
                'segundo_apellido' => $tutor->segundo_apellido,
                'curp' => $tutor->curp,
                'rfc' => $tutor->rfc,
                'email' => $tutor->email,
                'correo_institucional' => $tutor->correo_institucional,
                'celular' => $tutor->celular,
                'telefono_local' => $tutor->telefono_local,
                'fecha_nacimiento' => $tutor->fecha_nacimiento?->toDateString(),
                'foto' => $tutor->urlFoto(),
                // Con qué entra, si es que tiene cuenta. Es lo primero que se
                // pregunta cuando alguien dice «no puedo entrar».
                'usuario' => $usuario?->usuario ?? $usuario?->email,
                'tiene_cuenta' => $usuario !== null,
            ],
            'hijos' => $vinculos->map(fn (TutorAlumno $v) => [
                'persona_id' => $v->alumno_persona_id,
                'nombre' => $this->nombre($v->alumno),
                'curp' => $v->alumno?->curp,
                'parentesco' => $v->parentesco?->nombre,
                'puede_ver_academico' => (bool) $v->puede_ver_academico,
                'puede_ver_finanzas' => (bool) $v->puede_ver_finanzas,
                // Al expediente del alumno se llega por su matrícula, no por su
                // persona: una misma persona puede tener dos programas académicos.
                'matriculas' => $v->alumno?->matriculas()
                    ->with(['oferta.programaAcademico:id,nombre'])
                    ->get()
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'matricula' => $m->matricula,
                        'programa_academico' => $m->oferta?->programaAcademico?->nombre,
                    ])->values() ?? [],
            ])->values(),
            // Lo que la escuela le pide a él —no a sus hijos—: un padre también
            // llena bloques de datos, y hasta ahora los formularios sólo sabían
            // de quien estudia.
            'formularios' => app(ResolutorFormularios::class)->para($tutor),
            /*
             * Los papeles que la escuela le pide A ÉL —no a sus hijos—, con qué
             * revisarlos. Los sube desde su portal y hasta ahora nadie tenía
             * dónde aceptarlos: se quedaban «pendientes» para siempre.
             */
            'documentos' => DocumentoTutor::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre'])
                ->where('persona_id', $tutor->id)
                ->get()
                ->map(fn (DocumentoTutor $d) => [
                    'id' => $d->id,
                    'documento_id' => $d->documento_id,
                    'documento' => $d->documento?->nombre,
                    'descripcion' => $d->descripcion,
                    'estado_id' => $d->estado_documento_id,
                    'estado' => $d->estado?->nombre,
                    'estado_clave' => $d->estado?->clave,
                    'vigencia' => $d->vigencia?->toDateString(),
                    'vencido' => $d->estaVencido(),
                    'observaciones' => $d->observaciones,
                    'subido' => $d->created_at?->format('d/m/Y'),
                ])->values(),
            'estadosDocumento' => EstadoDocumento::query()->orderBy('id')->get(['id', 'clave', 'nombre']),
            'puedeValidar' => $request->user()->can('validar-expediente'),
            'puedeEditar' => $request->user()->can('editar-tutores'),
            'suplantable' => app(Suplantador::class)->datosPara($request, $tutor),
        ]);
    }

    /**
     * Revisa un documento del expediente DEL TUTOR: aceptarlo o rechazarlo.
     *
     * Gemelo del de alumno y del de docente. Sin aviso automático, y no por
     * descuido: `avisos_destinos` sabe señalar alumnos y extender a sus
     * familias, pero no tiene forma de dirigirse a UNA persona que es tutor y
     * nada más. El motivo lo lee en su propio expediente, que es donde subió el
     * papel; inventar aquí un destino nuevo para un caso sería agregar una
     * forma de segmentar que después habría que sostener en toda la pantalla de
     * avisos.
     */
    public function revisarDocumento(Request $request, Persona $tutor, DocumentoTutor $documento): RedirectResponse
    {
        abort_unless($documento->persona_id === $tutor->id, 404);

        $datos = $request->validate([
            'estado_documento_id' => ['required', 'integer', Rule::exists('estados_documento', 'id')->whereNull('deleted_at')],
            'observaciones' => ['nullable', 'string', 'max:255'],
        ], [], ['estado_documento_id' => 'estado']);

        $estado = EstadoDocumento::find($datos['estado_documento_id']);

        if ($estado?->clave === 'rechazado' && trim((string) ($datos['observaciones'] ?? '')) === '') {
            return back()->withErrors([
                'observaciones' => 'Explica por qué se rechaza: es lo único que el tutor va a leer.',
            ]);
        }

        $documento->update([
            'estado_documento_id' => $datos['estado_documento_id'],
            'observaciones' => $datos['observaciones'] ?? null,
        ]);

        return back(303)->with('exito', 'Documento revisado.');
    }

    /** Descarga autenticada del documento del tutor. Nunca por URL directa. */
    public function descargarDocumento(Persona $tutor, DocumentoTutor $documento): StreamedResponse
    {
        abort_unless($documento->persona_id === $tutor->id, 404);
        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download(
            $documento->url,
            sprintf('%s - %s', $documento->documento?->nombre ?? 'documento', $this->nombre($tutor)),
        );
    }

    private function nombre(?Persona $p): string
    {
        return trim(implode(' ', array_filter([$p?->nombre, $p?->primer_apellido, $p?->segundo_apellido])));
    }
}
