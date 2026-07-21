<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GuardarAspiranteRequest;
use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Identidad\Persona;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

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
