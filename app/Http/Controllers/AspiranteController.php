<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GuardarAspiranteRequest;
use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Identidad\Persona;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use App\Services\ConvertidorAspirante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Alta y seguimiento de aspirantes (el embudo de admisión).
 *
 * Una pantalla captura persona + aspirante, pero se guardan por separado: la
 * identidad vive en `personas` y no se duplica. Si llega alguien cuya CURP ya
 * está registrada —fue aspirante antes, o es familiar de un alumno, o ya es
 * egresado que vuelve por un posgrado— se REUTILIZA esa persona y solo se le
 * crea el aspirante. Es el principio de cero recaptura de la spec.
 */
class AspiranteController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'situacion_id' => $request->query('situacion_id'),
        ];

        $aspirantes = Aspirante::query()
            ->with(['persona', 'situacion', 'campus', 'ofertaInteres.carrera'])
            ->when($filtros['busqueda'] !== '', function ($query) use ($filtros) {
                $termino = "%{$filtros['busqueda']}%";

                $query->whereHas('persona', fn ($q) => $q
                    ->where('nombre', 'like', $termino)
                    ->orWhere('primer_apellido', 'like', $termino)
                    ->orWhere('segundo_apellido', 'like', $termino)
                    ->orWhere('curp', 'like', $termino));
            })
            ->when($filtros['situacion_id'], fn ($q, $situacion) => $q->where('situacion_id', $situacion))
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Aspirante $aspirante) => [
                'id' => $aspirante->id,
                'nombre_completo' => $aspirante->persona?->nombreCompleto(),
                'curp' => $aspirante->persona?->curp,
                'email' => $aspirante->persona?->email,
                'situacion' => $aspirante->situacion?->nombre,
                'campus' => $aspirante->campus?->nombre,
                'oferta' => $aspirante->ofertaInteres?->carrera?->nombre,
                'origen' => $aspirante->origen,
                'paso' => $aspirante->paso,
                'validado_admin' => $aspirante->validado_admin,
            ]);

        return Inertia::render('Aspirantes/Index', [
            'aspirantes' => $aspirantes,
            'filtros' => $filtros,
            'situaciones' => SituacionAspirante::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeCrear' => $request->user()->can('crear-aspirantes'),
            'puedeEditar' => $request->user()->can('editar-aspirantes'),
        ]);
    }

    /**
     * Ficha del aspirante: identidad, avance del proceso, expediente documental
     * y —si procede— la conversión a alumno.
     */
    public function show(Request $request, Aspirante $aspirante, ConvertidorAspirante $convertidor): Response
    {
        $aspirante->load([
            'persona.sexo',
            'persona.entidadNacimiento',
            'situacion',
            'campus',
            'ofertaInteres.carrera',
            'ofertaInteres.plan',
            'expedienteDocumentos.documento',
            'expedienteDocumentos.estado',
        ]);

        $matricula = MatriculaOferta::query()
            ->with('oferta.carrera')
            ->where('persona_id', $aspirante->persona_id)
            ->latest('id')
            ->first();

        return Inertia::render('Aspirantes/Detalle', [
            'aspirante' => [
                'id' => $aspirante->id,
                'nombre_completo' => $aspirante->persona->nombreCompleto(),
                'curp' => $aspirante->persona->curp,
                'email' => $aspirante->persona->email,
                'celular' => $aspirante->persona->celular,
                'fecha_nacimiento' => $aspirante->persona->fecha_nacimiento?->toDateString(),
                'sexo' => $aspirante->persona->sexo?->nombre,
                'entidad_nacimiento' => $aspirante->persona->entidadNacimiento?->nombre,
                'situacion' => $aspirante->situacion?->nombre,
                'campus' => $aspirante->campus?->nombre,
                'oferta' => $aspirante->ofertaInteres === null ? null : sprintf(
                    '%s — %s',
                    $aspirante->ofertaInteres->carrera?->nombre ?? 'Sin carrera',
                    $aspirante->ofertaInteres->plan?->nombre ?? 'Sin plan',
                ),
                'origen' => $aspirante->origen,
                'paso' => $aspirante->paso,
                'acepto_terminos' => $aspirante->acepto_terminos,
                'info_personal_completa' => $aspirante->info_personal_completa,
                'cleaver_completo' => $aspirante->cleaver_completo,
                'validado_admin' => $aspirante->validado_admin,
            ],
            'expediente' => $this->expediente($aspirante),
            'estadosDocumento' => EstadoDocumento::query()->orderBy('id')->get(['id', 'nombre']),
            'matricula' => $matricula === null ? null : [
                'matricula' => $matricula->matricula,
                'oferta' => $matricula->oferta?->carrera?->nombre,
                'fecha_ingreso' => $matricula->fecha_ingreso?->toDateString(),
            ],
            'impedimentosConversion' => $convertidor->impedimentos($aspirante),
            'permisos' => [
                'editar' => $request->user()->can('editar-aspirantes'),
                'validarExpediente' => $request->user()->can('validar-expediente'),
                'convertir' => $request->user()->can('convertir-aspirante'),
            ],
        ]);
    }

    /**
     * Convierte al aspirante en alumno. Aquí —y solo aquí— nace la matrícula.
     */
    public function convertir(Request $request, Aspirante $aspirante, ConvertidorAspirante $convertidor): RedirectResponse
    {
        $datos = $request->validate([
            'generacion' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $matricula = $convertidor->convertir($aspirante, $datos['generacion'] ?? null);
        } catch (RuntimeException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('exito', "Convertido en alumno. Matrícula asignada: {$matricula->matricula}.");
    }

    /**
     * Documentos que la carrera exige, cruzados con lo que el aspirante ya
     * entregó. Si no hay carrera definida se listan todos los del catálogo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function expediente(Aspirante $aspirante): array
    {
        $carreraId = $aspirante->ofertaInteres?->carrera_id;

        $requeridos = DocumentoRequerido::query()
            ->when($carreraId !== null, fn ($q) => $q->whereHas(
                'carreras',
                fn ($sub) => $sub->where('carreras.id', $carreraId),
            ))
            ->orderByDesc('obligatorio')
            ->orderBy('nombre')
            ->get();

        $entregados = $aspirante->expedienteDocumentos->keyBy('documento_id');

        return $requeridos->map(function (DocumentoRequerido $documento) use ($entregados) {
            $entrega = $entregados->get($documento->id);

            return [
                'documento_id' => $documento->id,
                'nombre' => $documento->nombre,
                'descripcion' => $documento->descripcion,
                'obligatorio' => $documento->obligatorio,
                'entrega' => $entrega === null ? null : [
                    'id' => $entrega->id,
                    'estado' => $entrega->estado?->nombre,
                    'estado_id' => $entrega->estado_documento_id,
                    'copia_certificada' => $entrega->copia_certificada,
                    'documento_fisico' => $entrega->documento_fisico,
                ],
            ];
        })->all();
    }

    public function create(): Response
    {
        return Inertia::render('Aspirantes/Formulario', [
            'aspirante' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(GuardarAspiranteRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $aspirante = DB::transaction(function () use ($datos) {
            $persona = $this->personaExistente($datos['curp'] ?? null);

            if ($persona === null) {
                $persona = Persona::create($this->datosPersona($datos));
            } else {
                // Ya existía: se completan los datos que vengan, sin pisar con vacíos.
                $persona->fill(array_filter(
                    $this->datosPersona($datos),
                    fn ($valor) => $valor !== null && $valor !== '',
                ))->save();
            }

            return Aspirante::create([
                ...$this->datosAspirante($datos),
                'persona_id' => $persona->id,
            ]);
        });

        return redirect()
            ->route('tenant.aspirantes.index')
            ->with('exito', "Aspirante {$aspirante->persona->nombreCompleto()} registrado.");
    }

    public function edit(Aspirante $aspirante): Response
    {
        $aspirante->load('persona');

        return Inertia::render('Aspirantes/Formulario', [
            'aspirante' => [
                'id' => $aspirante->id,
                'nombre' => $aspirante->persona->nombre,
                'primer_apellido' => $aspirante->persona->primer_apellido,
                'segundo_apellido' => $aspirante->persona->segundo_apellido,
                'curp' => $aspirante->persona->curp,
                'fecha_nacimiento' => $aspirante->persona->fecha_nacimiento?->toDateString(),
                'sexo_id' => $aspirante->persona->sexo_id,
                'genero_id' => $aspirante->persona->genero_id,
                'entidad_nacimiento_id' => $aspirante->persona->entidad_nacimiento_id,
                'email' => $aspirante->persona->email,
                'celular' => $aspirante->persona->celular,
                'oferta_interes_id' => $aspirante->oferta_interes_id,
                'campus_id' => $aspirante->campus_id,
                'situacion_id' => $aspirante->situacion_id,
                'origen' => $aspirante->origen,
                'acepto_terminos' => $aspirante->acepto_terminos,
            ],
            ...$this->catalogos(),
        ]);
    }

    public function update(GuardarAspiranteRequest $request, Aspirante $aspirante): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos, $aspirante) {
            $aspirante->persona->update($this->datosPersona($datos));
            $aspirante->update($this->datosAspirante($datos));
        });

        return redirect()
            ->route('tenant.aspirantes.index')
            ->with('exito', 'Aspirante actualizado.');
    }

    /**
     * Persona ya registrada con esa CURP, si la hay. La CURP es la llave
     * natural: si coincide, es la misma persona.
     */
    private function personaExistente(?string $curp): ?Persona
    {
        if ($curp === null || $curp === '') {
            return null;
        }

        return Persona::query()->where('curp', $curp)->first();
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
            'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
            'sexo_id' => $datos['sexo_id'],
            'genero_id' => $datos['genero_id'] ?? null,
            'entidad_nacimiento_id' => $datos['entidad_nacimiento_id'] ?? null,
            'email' => $datos['email'] ?? null,
            'celular' => $datos['celular'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosAspirante(array $datos): array
    {
        return [
            'oferta_interes_id' => $datos['oferta_interes_id'] ?? null,
            'campus_id' => $datos['campus_id'] ?? null,
            'situacion_id' => $datos['situacion_id'],
            'origen' => $datos['origen'] ?? null,
            'acepto_terminos' => $datos['acepto_terminos'] ?? false,
        ];
    }

    /**
     * Catálogos del formulario. Sexos, géneros y entidades viven en la BD
     * central (landlord) y se resuelven cross-database.
     *
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'sexos' => Sexo::query()->orderBy('id')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
            'entidades' => EntidadFederativa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'situaciones' => SituacionAspirante::query()->orderBy('id')->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'ofertas' => Oferta::query()
                ->with(['carrera:id,nombre', 'campus:id,nombre'])
                ->where('estatus', 'abierta')
                ->get()
                ->map(fn (Oferta $oferta) => [
                    'id' => $oferta->id,
                    'etiqueta' => trim(sprintf(
                        '%s — %s (%s)',
                        $oferta->carrera?->nombre ?? 'Sin carrera',
                        ucfirst($oferta->modalidad),
                        $oferta->campus?->nombre ?? 'Sin campus',
                    )),
                ]),
        ];
    }
}
