<?php

declare(strict_types=1);

namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use App\Models\Disciplina\TipoIncidencia;
use App\Models\Disciplina\TipoSancion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los catálogos de conducta: tipos de incidencia y tipos de sanción.
 *
 * ── Por qué son tabla y no un enum en el código ────────────────────────────
 * Regla del cliente: que la escuela configure todo antes de que existan
 * registros. Una escuela quiere tres niveles de gravedad y otra cinco; una
 * llama «Reporte» a lo que otra llama «Acta»; y qué sanción trae vigencia
 * (una suspensión sí, una amonestación no) lo decide cada quien. Cablearlo
 * dejaría a todas con los mismos cuatro casos de ejemplo.
 *
 * ── Las dos banderas que gobiernan las pantallas ───────────────────────────
 * `tipos_incidencia.nivel` pinta el color de gravedad en el listado;
 * `tipos_sancion.tiene_vigencia` hace que el formulario pida desde/hasta. No
 * se leen por su clave —así el catálogo es de verdad editable—, sino por esas
 * columnas.
 *
 * ── Un solo controlador para los dos ───────────────────────────────────────
 * Comparten forma (clave + nombre + una bandera) y reglas (alta con clave
 * única, borrado bloqueado cuando algo los usa, apagado que no deja huérfanos).
 * Se distinguen por el parámetro `{catalogo}` de la ruta. El mismo criterio que
 * `CatalogoAcademicoController`, pero acotado al módulo de disciplina y gateado
 * por su permiso: los catálogos de conducta no son catálogos de Académico.
 */
class CatalogoConductaController extends Controller
{
    /**
     * El registro de catálogos administrables.
     *
     * `extra` describe la bandera propia de cada uno, para que la validación y
     * la pantalla sepan qué pintar y cómo comprobarla.
     *
     * @return array<string, array{
     *     modelo: class-string<Model>,
     *     etiqueta: string,
     *     singular: string,
     *     enUso: callable(int): bool,
     *     extra: array{campo: string, tipo: string, etiqueta: string, ayuda: string, max?: int}
     * }>
     */
    private function registro(): array
    {
        return [
            'incidencia' => [
                'modelo' => TipoIncidencia::class,
                'etiqueta' => 'Tipos de incidencia',
                'singular' => 'tipo de incidencia',
                'enUso' => fn (int $id) => DB::table('incidencias')->whereNull('deleted_at')->where('tipo_incidencia_id', $id)->exists(),
                'extra' => [
                    'campo' => 'nivel',
                    'tipo' => 'entero',
                    'etiqueta' => 'Nivel de gravedad',
                    'ayuda' => '1 es leve; un número mayor es más grave. El listado lo pinta por color.',
                    'max' => 9,
                ],
            ],
            'sancion' => [
                'modelo' => TipoSancion::class,
                'etiqueta' => 'Tipos de sanción',
                'singular' => 'tipo de sanción',
                'enUso' => fn (int $id) => DB::table('sanciones')->whereNull('deleted_at')->where('tipo_sancion_id', $id)->exists(),
                'extra' => [
                    'campo' => 'tiene_vigencia',
                    'tipo' => 'bandera',
                    'etiqueta' => 'Tiene vigencia',
                    'ayuda' => 'Encendido, el formulario pide fecha de inicio y fin (una suspensión); apagado, es puntual (una amonestación).',
                ],
            ],
        ];
    }

    public function index(): Response
    {
        $catalogos = collect($this->registro())->map(function (array $def, string $clave) {
            $modelo = $def['modelo'];
            $extra = $def['extra'];

            return [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'singular' => $def['singular'],
                'extra' => [
                    'campo' => $extra['campo'],
                    'tipo' => $extra['tipo'],
                    'etiqueta' => $extra['etiqueta'],
                    'ayuda' => $extra['ayuda'],
                ],
                'items' => $modelo::query()
                    ->orderBy('orden')
                    ->orderBy('nombre')
                    ->get(['id', 'clave', 'nombre', 'descripcion', 'orden', 'activo', $extra['campo']])
                    ->map(fn (Model $m) => [
                        'id' => $m->id,
                        'clave' => $m->clave,
                        'nombre' => $m->nombre,
                        'descripcion' => $m->descripcion,
                        'activo' => (bool) $m->activo,
                        'en_uso' => ($def['enUso'])($m->id),
                        $extra['campo'] => $m->{$extra['campo']},
                    ]),
            ];
        })->values();

        return Inertia::render('Escolar/CatalogosConducta', [
            'catalogos' => $catalogos,
        ]);
    }

    public function store(Request $request, string $catalogo): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $datos = $this->validar($request, $def);

        // Nace al final del orden; la escuela lo reacomoda después si quiere.
        $tabla = (new $def['modelo'])->getTable();
        $datos['orden'] = (int) DB::table($tabla)->max('orden') + 1;
        $datos['activo'] = true;

        $def['modelo']::create($datos);

        return back(303)->with('exito', ucfirst($def['singular']).' agregado.');
    }

    public function update(Request $request, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        $registro->update($this->validar($request, $def, $item));

        return back(303)->with('exito', ucfirst($def['singular']).' actualizado.');
    }

    public function destroy(string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        // No se borra lo que algo usa: dejaría incidencias o sanciones apuntando
        // a un tipo que ya no existe. Para retirarlo de los desplegables sin
        // romper lo capturado, se APAGA.
        if (($def['enUso'])($registro->id)) {
            return back(303)->with('error', "No se puede eliminar: hay registros que usan este {$def['singular']}. Apágalo para retirarlo de los desplegables.");
        }

        $registro->delete();

        return back(303)->with('exito', ucfirst($def['singular']).' eliminado.');
    }

    /**
     * Enciende o apaga un tipo.
     *
     * Apagar sólo se permite si nadie lo usa —igual que en Académico—: así el
     * resto del sistema puede filtrar los desplegables con `->activos()` sin
     * dejar huérfano nada ya capturado. Encender nunca se bloquea.
     */
    public function alternar(Request $request, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);
        $encender = $request->boolean('activo');

        if (! $encender && ($def['enUso'])($registro->id)) {
            throw ValidationException::withMessages([
                'activo' => "No se puede apagar: hay registros que usan este {$def['singular']}.",
            ]);
        }

        $registro->update(['activo' => $encender]);

        return back(303)->with(
            'exito',
            $encender
                ? ucfirst($def['singular']).' encendido: ya aparece en los desplegables.'
                : ucfirst($def['singular']).' apagado: deja de ofrecerse.',
        );
    }

    /**
     * @param  array{modelo: class-string<Model>, extra: array<string, mixed>}  $def
     * @return array<string, mixed>
     */
    private function validar(Request $request, array $def, ?int $id = null): array
    {
        $tabla = (new $def['modelo'])->getTable();
        $extra = $def['extra'];

        $reglas = [
            'clave' => ['required', 'string', 'max:50', Rule::unique($tabla, 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ];

        $reglas[$extra['campo']] = match ($extra['tipo']) {
            'entero' => ['required', 'integer', 'min:1', 'max:'.($extra['max'] ?? 9)],
            'bandera' => ['required', 'boolean'],
            default => ['nullable'],
        };

        return $request->validate($reglas, [
            'clave.unique' => 'Ya existe un registro con esa clave en este catálogo.',
        ]);
    }

    /**
     * @return array{modelo: class-string<Model>, etiqueta: string, singular: string, enUso: callable, extra: array<string, mixed>}
     */
    private function definicion(string $catalogo): array
    {
        $registro = $this->registro();

        if (! isset($registro[$catalogo])) {
            throw ValidationException::withMessages(['catalogo' => 'Catálogo no reconocido.']);
        }

        return $registro[$catalogo];
    }
}
