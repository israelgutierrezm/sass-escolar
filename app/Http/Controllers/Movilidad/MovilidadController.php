<?php

declare(strict_types=1);

namespace App\Http\Controllers\Movilidad;

use App\Http\Controllers\Controller;
use App\Models\Academico\Carrera;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Landlord\Pais;
use App\Models\Movilidad\Convenio;
use App\Models\Movilidad\ConvocatoriaMovilidad;
use App\Models\Movilidad\Estancia;
use App\Models\Movilidad\EtapaMovilidad;
use App\Models\Movilidad\InstitucionAliada;
use App\Models\Movilidad\PostulacionMovilidad;
use App\Models\Movilidad\SituacionConvenio;
use App\Models\Movilidad\TipoConvenio;
use App\Models\Movilidad\TipoInstitucion;
use App\Services\Movilidad\RegistroMovilidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Convenios, convocatorias y postulaciones de movilidad.
 *
 * La revalidación —lo que escribe en el historial académico— vive aparte, en su
 * propio controlador: es el gesto delicado del módulo y no se mezcla con el
 * papeleo.
 */
class MovilidadController extends Controller
{
    public function __construct(private readonly RegistroMovilidad $registro) {}

    // ── Instituciones y convenios ────────────────────────────────────────

    public function convenios(Request $peticion): Response
    {
        $convenios = Convenio::query()
            ->with(['institucion:id,nombre,pais_id,ciudad', 'tipo:id,nombre', 'situacion:id,nombre', 'carreras:id,nombre'])
            ->withCount('convocatorias')
            ->when($peticion->filled('institucion_id'), fn (Builder $q) => $q
                ->where('institucion_aliada_id', $peticion->integer('institucion_id')))
            ->orderByDesc('vigente_desde')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Movilidad/Convenios', [
            'convenios' => $convenios->through(fn (Convenio $c) => [
                'id' => $c->id,
                'folio' => $c->folio,
                'institucion' => $c->institucion?->nombre,
                'ciudad' => $c->institucion?->ciudad,
                'tipo' => $c->tipo?->nombre,
                'situacion' => $c->situacion?->nombre,
                'desde' => $c->vigente_desde?->toDateString(),
                'hasta' => $c->vigente_hasta?->toDateString(),
                // Vencido no es lo mismo que suspendido: el color habla del
                // estado REAL y la etiqueta lo dice.
                'vencido' => $c->estaVencido(),
                'vigente' => Convenio::query()->vigentes()->whereKey($c->id)->exists(),
                'carreras' => $c->carreras->pluck('nombre'),
                'convocatorias' => $c->convocatorias_count,
            ]),
            'filtros' => ['institucion_id' => $peticion->integer('institucion_id') ?: null],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function guardarInstitucion(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:255'],
            // Sin `exists`: `paises` vive en la base CENTRAL y la regla del
            // proyecto es no cruzar foráneas tenant → landlord.
            'pais_id' => ['nullable', 'integer'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'tipo_id' => ['required', Rule::exists('tipos_institucion', 'id')],
            'sitio_web' => ['nullable', 'url:http,https', 'max:255'],
        ]);

        InstitucionAliada::create($datos + ['activa' => true]);

        return back(303)->with('exito', 'Institución registrada.');
    }

    public function guardarConvenio(Request $peticion, ?Convenio $convenio = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'institucion_aliada_id' => ['required', Rule::exists('instituciones_aliadas', 'id')],
            'tipo_convenio_id' => ['required', Rule::exists('tipos_convenio', 'id')],
            'folio' => [
                'required', 'string', 'max:50',
                Rule::unique('convenios', 'folio')->ignore($convenio?->id)->whereNull('deleted_at'),
            ],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'situacion_id' => ['required', Rule::exists('situaciones_convenio', 'id')],
            'notas' => ['nullable', 'string', 'max:2000'],
            'carreras' => ['array'],
            'carreras.*' => ['integer', Rule::exists('carreras', 'id')],
        ], [
            'vigente_hasta.after_or_equal' => 'El convenio no puede vencer antes de empezar.',
            'folio.unique' => 'Ya hay un convenio con ese folio.',
        ]);

        $carreras = $datos['carreras'] ?? [];
        unset($datos['carreras']);

        $convenio === null
            ? $convenio = Convenio::create($datos)
            : $convenio->update($datos);

        // Vacío = TODAS. Se sincroniza igual, para poder volver a «todas».
        $convenio->carreras()->sync($carreras);

        return back(303)->with('exito', 'Convenio guardado.');
    }

    // ── Convocatorias ────────────────────────────────────────────────────

    public function convocatorias(Request $peticion): Response
    {
        $convocatorias = ConvocatoriaMovilidad::query()
            ->with(['convenio:id,folio,institucion_aliada_id', 'convenio.institucion:id,nombre'])
            ->withCount('postulaciones')
            ->when($peticion->filled('direccion'), fn (Builder $q) => $q->where('direccion', $peticion->query('direccion')))
            ->orderByDesc('fecha_apertura')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Movilidad/Convocatorias', [
            'convocatorias' => $convocatorias->through(fn (ConvocatoriaMovilidad $c) => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'institucion' => $c->convenio?->institucion?->nombre,
                'direccion' => $c->direccion,
                'periodo' => $c->periodo,
                'cupo' => $c->cupo,
                'libres' => $c->lugaresLibres(),
                'promedio_minimo' => $c->promedio_minimo,
                'apertura' => $c->fecha_apertura?->toDateString(),
                'cierre' => $c->fecha_cierre?->toDateString(),
                'abierta' => ConvocatoriaMovilidad::query()->abiertas()->whereKey($c->id)->exists(),
                'postulaciones' => $c->postulaciones_count,
            ]),
            'filtros' => ['direccion' => $peticion->query('direccion')],
            'convenios' => Convenio::query()->vigentes()->with('institucion:id,nombre')
                ->get(['id', 'folio', 'institucion_aliada_id'])
                ->map(fn (Convenio $c) => [
                    'id' => $c->id,
                    'nombre' => ($c->institucion?->nombre ?? '—').' · '.$c->folio,
                ]),
            'requisitos' => DocumentoRequerido::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function guardarConvocatoria(Request $peticion, ?ConvocatoriaMovilidad $convocatoria = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'convenio_id' => ['required', Rule::exists('convenios', 'id')],
            'titulo' => ['required', 'string', 'max:200'],
            'direccion' => ['required', Rule::in([ConvocatoriaMovilidad::SALIENTE, ConvocatoriaMovilidad::ENTRANTE])],
            'periodo' => ['required', 'string', 'max:50'],
            'cupo' => ['required', 'integer', 'min:1', 'max:9999'],
            'promedio_minimo' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fecha_apertura' => ['required', 'date'],
            'fecha_cierre' => ['required', 'date', 'after_or_equal:fecha_apertura'],
            'descripcion' => ['nullable', 'string', 'max:4000'],
            'requisitos' => ['array'],
            'requisitos.*' => ['integer', Rule::exists('documentos_requeridos', 'id')],
        ], [
            'fecha_cierre.after_or_equal' => 'La convocatoria no puede cerrar antes de abrir.',
            'cupo.min' => 'Una convocatoria sin lugares no convoca a nadie.',
        ]);

        $requisitos = $datos['requisitos'] ?? [];
        unset($datos['requisitos']);

        $convocatoria === null
            ? $convocatoria = ConvocatoriaMovilidad::create($datos)
            : $convocatoria->update($datos);

        $convocatoria->requisitos()->sync($requisitos);

        return back(303)->with('exito', 'Convocatoria guardada.');
    }

    // ── Postulaciones ────────────────────────────────────────────────────

    public function postulaciones(ConvocatoriaMovilidad $convocatoria): Response
    {
        $convocatoria->load(['convenio.institucion:id,nombre', 'requisitos:id,nombre']);

        $postulaciones = $convocatoria->postulaciones()
            ->with([
                'matricula:id,persona_id,matricula,oferta_id',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'personaExterna:id,nombre,primer_apellido,segundo_apellido',
                'etapa:id,nombre,acepta,es_final',
                'estancia',
            ])
            ->orderByDesc('fecha_postulacion')
            ->get();

        return Inertia::render('Movilidad/Postulaciones', [
            'convocatoria' => [
                'id' => $convocatoria->id,
                'titulo' => $convocatoria->titulo,
                'institucion' => $convocatoria->convenio?->institucion?->nombre,
                'direccion' => $convocatoria->direccion,
                'es_saliente' => $convocatoria->esSaliente(),
                'periodo' => $convocatoria->periodo,
                'cupo' => $convocatoria->cupo,
                'libres' => $convocatoria->lugaresLibres(),
                'promedio_minimo' => $convocatoria->promedio_minimo,
                'abierta' => ConvocatoriaMovilidad::query()->abiertas()->whereKey($convocatoria->id)->exists(),
                'requisitos' => $convocatoria->requisitos->pluck('nombre'),
            ],
            'postulaciones' => $postulaciones->map(fn (PostulacionMovilidad $p) => [
                'id' => $p->id,
                'quien' => $p->quien(),
                'matricula' => $p->matricula?->matricula,
                'etapa_id' => $p->etapa_id,
                'etapa' => $p->etapa?->nombre,
                'acepta' => (bool) $p->etapa?->acepta,
                // Congelado al postularse: el de hoy no es con el que se le
                // evaluó.
                'promedio' => $p->promedio_acreditado,
                'fecha' => $p->fecha_postulacion?->toDateString(),
                'estancia' => $p->estancia === null ? null : [
                    'id' => $p->estancia->id,
                    'desde' => $p->estancia->fecha_inicio?->toDateString(),
                    'hasta' => $p->estancia->fecha_fin?->toDateString(),
                    'concluida' => $p->estancia->estaConcluida(),
                ],
            ]),
            'etapas' => EtapaMovilidad::query()->activos()->get(['id', 'nombre', 'acepta']),
        ]);
    }

    public function postular(Request $peticion, ConvocatoriaMovilidad $convocatoria): RedirectResponse
    {
        $datos = $peticion->validate([
            'matricula_oferta_id' => ['nullable', 'integer'],
            'persona_id' => ['nullable', 'integer', Rule::exists('personas', 'id')->whereNull('deleted_at')],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($convocatoria->esSaliente()) {
                $matricula = MatriculaOferta::query()
                    ->with('oferta.plan')
                    ->findOrFail($datos['matricula_oferta_id'] ?? 0);

                $this->registro->postularSaliente($convocatoria, $matricula, $datos['notas'] ?? null);
            } else {
                $this->registro->postularEntrante(
                    $convocatoria,
                    (int) ($datos['persona_id'] ?? 0),
                    $datos['notas'] ?? null,
                );
            }
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Postulación registrada.');
    }

    public function mover(Request $peticion, ConvocatoriaMovilidad $convocatoria, PostulacionMovilidad $postulacion): RedirectResponse
    {
        abort_unless($postulacion->convocatoria_id === $convocatoria->id, 404);

        $datos = $peticion->validate(['etapa_id' => ['required', Rule::exists('etapas_movilidad', 'id')]]);

        try {
            $this->registro->mover($postulacion, (int) $datos['etapa_id']);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Postulación movida.');
    }

    public function abrirEstancia(Request $peticion, ConvocatoriaMovilidad $convocatoria, PostulacionMovilidad $postulacion): RedirectResponse
    {
        abort_unless($postulacion->convocatoria_id === $convocatoria->id, 404);

        $datos = $peticion->validate([
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date'],
        ]);

        try {
            $this->registro->abrirEstancia($postulacion, $datos['fecha_inicio'], $datos['fecha_fin'] ?? null);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Estancia abierta.');
    }

    public function concluirEstancia(Request $peticion, ConvocatoriaMovilidad $convocatoria, Estancia $estancia): RedirectResponse
    {
        abort_unless($estancia->postulacion?->convocatoria_id === $convocatoria->id, 404);

        $datos = $peticion->validate(['concluida_en' => ['required', 'date']]);

        try {
            $this->registro->concluirEstancia($estancia, $datos['concluida_en']);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Estancia concluida. Ya se le pueden revalidar materias.');
    }

    /** Alumnos que podrían postularse, con su promedio real. */
    public function candidatos(Request $peticion, ConvocatoriaMovilidad $convocatoria)
    {
        $texto = trim((string) $peticion->query('q', ''));

        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }

        $matriculas = MatriculaOferta::query()
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.plan', 'oferta.carrera:id,nombre'])
            ->where(fn (Builder $q) => $q
                ->where('matricula', 'like', "%{$texto}%")
                ->orWhereHas('persona', fn (Builder $p) => $p->whereRaw(
                    "TRIM(CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)) LIKE ?",
                    ["%{$texto}%"],
                )))
            ->limit(15)
            ->get();

        return response()->json($matriculas->map(function (MatriculaOferta $m) use ($convocatoria) {
            $promedio = $this->registro->promedioDe($m);
            $alcanza = $convocatoria->promedio_minimo === null
                || ($promedio !== null && $promedio >= (float) $convocatoria->promedio_minimo);

            return [
                'id' => $m->id,
                'nombre' => $m->persona?->nombreCompleto(),
                'matricula' => $m->matricula,
                // El promedio se ENSEÑA al elegir, no después de rechazarlo:
                // quien captura tiene que ver por qué no alcanza.
                'carrera' => ($m->oferta?->carrera?->nombre ?? '—')
                    .' · promedio '.($promedio === null ? 'sin calcular' : number_format($promedio, 2))
                    .($alcanza ? '' : ' (no alcanza el mínimo)'),
            ];
        }));
    }

    /** @return array<string, mixed> */
    private function catalogos(): array
    {
        return [
            'instituciones' => InstitucionAliada::query()->activas()->orderBy('nombre')->get(['id', 'nombre']),
            'tipos_institucion' => TipoInstitucion::query()->activos()->get(['id', 'nombre']),
            'tipos_convenio' => TipoConvenio::query()->activos()->get(['id', 'nombre']),
            'situaciones' => SituacionConvenio::query()->activos()->get(['id', 'nombre']),
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'paises' => Pais::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
