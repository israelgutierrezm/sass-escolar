<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlantillaComponente;
use App\Models\Academico\PlantillaEvaluacion;
use App\Services\AplicadorPlantillaEvaluacion;
use App\Services\RepartidorPorcentajes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Plantillas de evaluación: el criterio de calificación, definido una vez.
 *
 * Evita repetir los mismos porcentajes en las 50 materias de un plan. Se arma
 * la plantilla, se aplica al plan, y sus componentes se materializan en el
 * `esquema_evaluacion` de cada materia.
 *
 * Editar una plantilla re-propaga a las materias que la siguen —esa es su razón
 * de existir— salvo a las que ya tienen calificaciones capturadas, que se
 * reportan como bloqueadas.
 */
class PlantillaEvaluacionController extends Controller
{
    public function __construct(
        private readonly AplicadorPlantillaEvaluacion $aplicador,
        private readonly RepartidorPorcentajes $repartidor,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Academico/Plantillas/Index', [
            'plantillas' => PlantillaEvaluacion::query()
                ->with('componentes')
                ->withCount(['materias', 'planes'])
                ->orderBy('nombre')
                ->get()
                ->map(fn (PlantillaEvaluacion $p) => [
                    'id' => $p->id,
                    'clave' => $p->clave,
                    'nombre' => $p->nombre,
                    'descripcion' => $p->descripcion,
                    'activa' => $p->activa,
                    'componentes' => $p->componentes->count(),
                    'parciales' => $p->numeroDeParciales(),
                    'suma' => round((float) $p->componentes->sum('porcentaje'), 2),
                    'completa' => $p->estaCompleta(),
                    'materias_count' => $p->materias_count,
                    'planes_count' => $p->planes_count,
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function show(Request $request, PlantillaEvaluacion $plantilla): Response
    {
        $plantilla->load('componentes');

        return Inertia::render('Academico/Plantillas/Detalle', [
            'plantilla' => [
                'id' => $plantilla->id,
                'clave' => $plantilla->clave,
                'nombre' => $plantilla->nombre,
                'descripcion' => $plantilla->descripcion,
                'activa' => $plantilla->activa,
            ],
            'componentes' => $plantilla->componentes->map(fn (PlantillaComponente $c) => [
                'id' => $c->id,
                'componente' => $c->componente,
                'parcial' => $c->parcial,
                'porcentaje' => (float) $c->porcentaje,
                'orden' => $c->orden,
            ])->values(),
            'suma' => round((float) $plantilla->componentes->sum('porcentaje'), 2),
            'completa' => $plantilla->estaCompleta(),
            // Se advierte ANTES de guardar, no después: quien edita el criterio
            // necesita saber a cuántas materias va a alcanzar y a cuáles no.
            'bloqueadas' => $this->aplicador->materiasBloqueadas($plantilla),
            'materiasQueLaSiguen' => $plantilla->materias()->count(),
            'planes' => PlanEstudio::query()
                ->with('carrera:id,nombre')
                ->orderBy('nombre')
                ->get()
                ->map(fn (PlanEstudio $p) => [
                    'id' => $p->id,
                    'etiqueta' => trim(($p->carrera?->nombre ?? '').' · '.$p->nombre),
                    'usa_esta' => $p->plantilla_evaluacion_id === $plantilla->id,
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plantilla = PlantillaEvaluacion::create($this->validar($request));

        return redirect()
            ->route('tenant.academico.plantillas.show', $plantilla)
            ->with('exito', 'Plantilla creada. Agrégale sus rubros.');
    }

    public function update(Request $request, PlantillaEvaluacion $plantilla): RedirectResponse
    {
        $plantilla->update($this->validar($request, $plantilla->id));

        return back()->with('exito', 'Plantilla actualizada.');
    }

    public function destroy(PlantillaEvaluacion $plantilla): RedirectResponse
    {
        // Borrarla dejaría las materias con su esquema materializado pero sin
        // saber de dónde salió. Se desactiva en su lugar.
        if ($plantilla->materias()->exists() || $plantilla->planes()->exists()) {
            return back()->with('error', 'No se puede eliminar: hay planes o materias usándola. Desactívala en su lugar.');
        }

        $plantilla->delete();

        return redirect()->route('tenant.academico.plantillas.index')->with('exito', 'Plantilla eliminada.');
    }

    /*
    |--------------------------------------------------------------------------
    | Rubros de la plantilla
    |--------------------------------------------------------------------------
    */

    public function agregarComponente(Request $request, PlantillaEvaluacion $plantilla): RedirectResponse
    {
        $datos = $this->validarComponente($request);

        $this->validarSuma($plantilla, (float) $datos['porcentaje']);

        PlantillaComponente::create([
            ...$datos,
            'plantilla_id' => $plantilla->id,
            'orden' => $datos['orden'] ?? ((int) $plantilla->componentes()->max('orden') + 1),
        ]);

        return back()->with('exito', 'Rubro agregado.');
    }

    public function actualizarComponente(Request $request, PlantillaEvaluacion $plantilla, PlantillaComponente $componente): RedirectResponse
    {
        abort_unless($componente->plantilla_id === $plantilla->id, 404);

        $datos = $this->validarComponente($request);

        $this->validarSuma($plantilla, (float) $datos['porcentaje'], $componente->id);

        $componente->update($datos);

        return back()->with('exito', 'Rubro actualizado.');
    }

    public function eliminarComponente(PlantillaEvaluacion $plantilla, PlantillaComponente $componente): RedirectResponse
    {
        abort_unless($componente->plantilla_id === $plantilla->id, 404);

        $componente->delete();

        return back()->with('exito', 'Rubro eliminado.');
    }

    /** Reparte 100% en partes iguales entre los rubros existentes. */
    public function repartirEquitativo(PlantillaEvaluacion $plantilla): RedirectResponse
    {
        if ($plantilla->componentes()->doesntExist()) {
            return back()->with('error', 'Agrega rubros antes de repartir.');
        }

        $this->aplicador->repartirEquitativo($plantilla);

        return back()->with('exito', 'Porcentajes repartidos en partes iguales.');
    }

    /*
    |--------------------------------------------------------------------------
    | Aplicación
    |--------------------------------------------------------------------------
    */

    public function aplicarAPlan(Request $request, PlantillaEvaluacion $plantilla): RedirectResponse
    {
        $datos = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'respetar_personalizadas' => ['boolean'],
        ], [], ['plan_id' => 'plan']);

        $plan = PlanEstudio::findOrFail($datos['plan_id']);

        try {
            $resultado = $this->aplicador->aplicarAPlan(
                $plantilla,
                $plan,
                $datos['respetar_personalizadas'] ?? true,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['plan_id' => $e->getMessage()]);
        }

        return back()->with(...$this->mensajeDeResultado($resultado));
    }

    /** Re-aplica la plantilla a todas las materias que la siguen. */
    public function repropagar(PlantillaEvaluacion $plantilla): RedirectResponse
    {
        try {
            $resultado = $this->aplicador->repropagar($plantilla);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['plantilla' => $e->getMessage()]);
        }

        return back()->with(...$this->mensajeDeResultado($resultado));
    }

    /*
    |--------------------------------------------------------------------------
    | Apoyo
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{aplicadas: int, bloqueadas: array<int, string>, omitidas: int}  $resultado
     * @return array{0: string, 1: string}
     */
    private function mensajeDeResultado(array $resultado): array
    {
        $aplicadas = $resultado['aplicadas'];
        $partes = [$aplicadas === 1 ? '1 materia actualizada' : "{$aplicadas} materias actualizadas"];

        if ($resultado['omitidas'] > 0) {
            $partes[] = $resultado['omitidas'] === 1
                ? '1 con esquema propio se respetó'
                : "{$resultado['omitidas']} con esquema propio se respetaron";
        }

        if ($resultado['bloqueadas'] !== []) {
            $cuantas = count($resultado['bloqueadas']);
            $lista = implode(', ', array_slice($resultado['bloqueadas'], 0, 3));
            $resto = $cuantas > 3 ? ' y '.($cuantas - 3).' más' : '';

            $aviso = $cuantas === 1
                ? '1 no se tocó porque ya tiene calificaciones capturadas'
                : "{$cuantas} no se tocaron porque ya tienen calificaciones capturadas";

            return ['advertencia', "{$partes[0]}. {$aviso}: {$lista}{$resto}."];
        }

        return ['exito', implode('; ', $partes).'.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'clave' => [
                'required', 'string', 'max:50',
                Rule::unique('plantillas_evaluacion', 'clave')->ignore($id)->whereNull('deleted_at'),
            ],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activa' => ['boolean'],
        ], [
            'clave.unique' => 'Ya existe una plantilla con esa clave.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validarComponente(Request $request): array
    {
        return $request->validate([
            'componente' => ['required', 'string', 'max:60'],
            // NULL = el rubro va directo al curso, sin pertenecer a un corte.
            'parcial' => ['nullable', 'integer', 'min:1', 'max:10'],
            // Se admite 0 para poder soltar los rubros primero y repartir el
            // 100% después con un clic, que es como se arma en la práctica.
            'porcentaje' => ['required', 'numeric', 'min:0', 'max:100'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ], [], ['porcentaje' => 'porcentaje']);
    }

    /**
     * Quedarse corto se permite —la plantilla se arma por partes— pero pasarse
     * de 100 nunca: ese esquema no podría aplicarse a ninguna materia.
     */
    private function validarSuma(PlantillaEvaluacion $plantilla, float $porcentaje, ?int $ignorar = null): void
    {
        $acumulado = (float) $plantilla->componentes()
            ->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))
            ->sum('porcentaje');

        if ($acumulado + $porcentaje > 100.0 + 0.001) {
            throw ValidationException::withMessages([
                'porcentaje' => sprintf(
                    'Con ese porcentaje la plantilla sumaría %s%%. Disponible: %s%%.',
                    round($acumulado + $porcentaje, 2),
                    $this->repartidor->disponible($acumulado),
                ),
            ]);
        }
    }
}
