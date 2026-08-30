<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bolsa;

use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Bolsa\Empresa;
use App\Models\Bolsa\Habilidad;
use App\Models\Bolsa\ModalidadTrabajo;
use App\Models\Bolsa\SituacionVacante;
use App\Models\Bolsa\TipoJornada;
use App\Models\Bolsa\Vacante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las vacantes que las empresas ofrecen a la escuela.
 *
 * ── Sólo publica quien puede publicar ─────────────────────────────────────
 * La empresa se elige de `publicables()`, o sea las que no están vetadas. Sin
 * eso, vetar a un empleador impediría verlo en el padrón pero no le impediría
 * seguir apareciendo con vacantes nuevas, que es lo único que el veto tenía que
 * lograr.
 */
class VacanteController extends Controller
{
    public function index(Request $peticion): Response
    {
        $filtros = [
            'busqueda' => trim((string) $peticion->query('busqueda', '')),
            'empresa_id' => $peticion->query('empresa_id'),
            'situacion_id' => $peticion->query('situacion_id'),
        ];

        $vacantes = Vacante::query()
            ->with(['empresa:id,razon_social', 'modalidad:id,nombre', 'jornada:id,nombre', 'situacion:id,clave,nombre', 'programas_academicos:id,nombre'])
            ->when($filtros['busqueda'] !== '', fn ($q) => $q->where('titulo', 'like', "%{$filtros['busqueda']}%"))
            ->when($filtros['empresa_id'], fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['situacion_id'], fn ($q, $v) => $q->where('situacion_id', $v))
            ->orderByDesc('fecha_publicacion')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Bolsa/Vacantes', [
            'vacantes' => $vacantes->through(fn (Vacante $v) => [
                'id' => $v->id,
                'titulo' => $v->titulo,
                'empresa' => $v->empresa?->razon_social,
                'modalidad' => $v->modalidad?->nombre,
                'jornada' => $v->jornada?->nombre,
                'situacion' => $v->situacion?->nombre,
                'situacion_clave' => $v->situacion?->clave,
                'vencida' => $v->estaVencida(),
                'fecha_cierre' => $v->fecha_cierre?->toDateString(),
                'vacantes_disponibles' => $v->vacantes_disponibles,
                // Vacía a propósito significa «para todos los programas académicos»; la
                // pantalla lo dice con palabras en vez de dejar un hueco.
                'programas_academicos' => $v->programasAcademicos->pluck('nombre'),
                'salario' => $this->salario($v),
            ]),
            'filtros' => $filtros,
            'catalogos' => $this->catalogos(),
        ]);
    }

    /**
     * La pantalla de alta: la MISMA que la de edición, con la vacante vacía.
     *
     * Dos pantallas casi iguales es como se llega a que el alta pida un campo
     * que la edición no ofrece —y entonces ese campo sólo se puede poner al
     * crear—.
     */
    public function crear(): Response
    {
        return Inertia::render('Bolsa/Vacante', [
            'vacante' => [
                'id' => null,
                'empresa_id' => null,
                'empresa' => null,
                'titulo' => '',
                'descripcion' => '',
                'modalidad_id' => null,
                'tipo_jornada_id' => null,
                'salario_min' => null,
                'salario_max' => null,
                'campus_id' => null,
                'vacantes_disponibles' => 1,
                'ubicacion' => null,
                'fecha_publicacion' => now()->toDateString(),
                'fecha_cierre' => null,
                'situacion_id' => SituacionVacante::query()->where('clave', 'abierta')->value('id'),
                'programas_academicos' => [],
                'habilidades' => [],
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function show(Vacante $vacante): Response
    {
        $vacante->load(['empresa:id,razon_social', 'programas_academicos:id', 'habilidades:id']);

        return Inertia::render('Bolsa/Vacante', [
            'vacante' => [
                'id' => $vacante->id,
                'empresa_id' => $vacante->empresa_id,
                'empresa' => $vacante->empresa?->razon_social,
                'titulo' => $vacante->titulo,
                'descripcion' => $vacante->descripcion,
                'modalidad_id' => $vacante->modalidad_id,
                'tipo_jornada_id' => $vacante->tipo_jornada_id,
                'salario_min' => $vacante->salario_min,
                'salario_max' => $vacante->salario_max,
                'campus_id' => $vacante->campus_id,
                'vacantes_disponibles' => $vacante->vacantes_disponibles,
                'ubicacion' => $vacante->ubicacion,
                'fecha_publicacion' => $vacante->fecha_publicacion?->toDateString(),
                'fecha_cierre' => $vacante->fecha_cierre?->toDateString(),
                'situacion_id' => $vacante->situacion_id,
                'programas_academicos' => $vacante->programasAcademicos->pluck('id'),
                'habilidades' => $vacante->habilidades->map(fn (Habilidad $h) => [
                    'id' => $h->id,
                    'indispensable' => (bool) $h->pivot->indispensable,
                ]),
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function guardar(Request $peticion, ?Vacante $vacante = null): RedirectResponse
    {
        $datos = $peticion->validate([
            /*
             * De las PUBLICABLES: vetar a una empresa tiene que impedirle
             * publicar vacantes nuevas, no sólo esconderla del padrón.
             */
            'empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->whereNull('deleted_at'),
                function (string $atributo, mixed $valor, callable $falla) {
                    if (! Empresa::query()->publicables()->whereKey($valor)->exists()) {
                        $falla('Esa empresa está vetada y no puede publicar vacantes.');
                    }
                },
            ],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['required', 'string', 'max:8000'],
            'modalidad_id' => ['nullable', Rule::exists('modalidades_trabajo', 'id')],
            'tipo_jornada_id' => ['nullable', Rule::exists('tipos_jornada', 'id')],
            'salario_min' => ['nullable', 'numeric', 'min:0'],
            // Un rango invertido no es un dato raro: es un error de captura que
            // deja la vacante sin poderse filtrar por sueldo.
            'salario_max' => ['nullable', 'numeric', 'min:0', 'gte:salario_min'],
            'campus_id' => ['nullable', Rule::exists('campus', 'id')],
            'vacantes_disponibles' => ['required', 'integer', 'min:1', 'max:9999'],
            'ubicacion' => ['nullable', 'string', 'max:200'],
            'fecha_publicacion' => ['required', 'date'],
            'fecha_cierre' => ['nullable', 'date', 'after_or_equal:fecha_publicacion'],
            'situacion_id' => ['required', Rule::exists('situaciones_vacante', 'id')],
            'programas_academicos' => ['array'],
            'programas_academicos.*' => ['integer', Rule::exists('programas_academicos', 'id')],
            'habilidades' => ['array'],
            'habilidades.*.id' => ['required', 'integer', Rule::exists('habilidades', 'id')],
            'habilidades.*.indispensable' => ['boolean'],
        ], [
            'salario_max.gte' => 'El sueldo máximo no puede ser menor que el mínimo.',
            'fecha_cierre.after_or_equal' => 'La vacante no puede cerrar antes de publicarse.',
        ]);

        DB::transaction(function () use ($datos, &$vacante) {
            $programasAcademicos = $datos['programas_academicos'] ?? [];
            $habilidades = collect($datos['habilidades'] ?? [])
                ->mapWithKeys(fn (array $h) => [$h['id'] => ['indispensable' => $h['indispensable'] ?? false]])
                ->all();

            unset($datos['programas_academicos'], $datos['habilidades']);

            $vacante === null || ! $vacante->exists
                ? $vacante = Vacante::create($datos)
                : $vacante->update($datos);

            $vacante->programasAcademicos()->sync($programasAcademicos);
            $vacante->habilidades()->sync($habilidades);
        });

        return back(303)->with('exito', 'Vacante guardada.');
    }

    /** El rango en palabras, o null si el empleador no lo publicó. */
    private function salario(Vacante $vacante): ?string
    {
        $min = $vacante->salario_min;
        $max = $vacante->salario_max;

        return match (true) {
            $min !== null && $max !== null => '$'.number_format((float) $min, 0).' – $'.number_format((float) $max, 0),
            $min !== null => 'desde $'.number_format((float) $min, 0),
            $max !== null => 'hasta $'.number_format((float) $max, 0),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function catalogos(): array
    {
        return [
            'empresas' => Empresa::query()->publicables()->orderBy('razon_social')->get(['id', 'razon_social']),
            'modalidades' => ModalidadTrabajo::query()->activos()->get(['id', 'nombre']),
            'jornadas' => TipoJornada::query()->activos()->get(['id', 'nombre']),
            'situaciones' => SituacionVacante::query()->activos()->get(['id', 'nombre']),
            'habilidades' => Habilidad::query()->activos()->get(['id', 'nombre']),
            'programas_academicos' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
