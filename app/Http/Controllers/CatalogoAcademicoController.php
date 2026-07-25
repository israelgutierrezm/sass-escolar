<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Area;
use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\Descriptor;
use App\Models\Academico\Modalidad;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\Turno;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración / Catálogos de Académico: una sola pantalla para administrar
 * los catálogos simples (clave + nombre) que alimentan los formularios del
 * módulo.
 *
 * Se hace GENÉRICO —un registro catálogo→modelo, no un controlador por cada
 * uno— porque todos comparten forma y reglas: alta con clave única, edición,
 * y borrado bloqueado cuando algo los usa. Multiplicar el mismo CRUD siete
 * veces solo multiplica los lugares donde arreglar el mismo error.
 *
 * Cada entrada del registro dice, además del modelo, CÓMO saber si un ítem está
 * en uso: es lo que distingue borrar un área que nadie asignó de borrar una que
 * sostiene veinte asignaturas.
 */
class CatalogoAcademicoController extends Controller
{
    /**
     * El registro de catálogos administrables. La clave de la URL → definición.
     *
     * @return array<string, array{modelo: class-string<Model>, etiqueta: string, singular: string, grupo: string, enUso: callable}>
     */
    private function registro(): array
    {
        return [
            'clasificacion' => [
                'modelo' => ClasificacionAsignatura::class,
                'etiqueta' => 'Clasificación',
                'singular' => 'clasificación',
                'grupo' => 'Asignaturas',
                'enUso' => fn (int $id) => DB::table('asignaturas')->whereNull('deleted_at')->where('clasificacion_id', $id)->exists(),
            ],
            'area' => [
                'modelo' => Area::class,
                'etiqueta' => 'Área',
                'singular' => 'área',
                'grupo' => 'Asignaturas',
                'enUso' => fn (int $id) => DB::table('asignaturas')->whereNull('deleted_at')->where('area_id', $id)->exists(),
            ],
            'descriptor' => [
                'modelo' => Descriptor::class,
                'etiqueta' => 'Descriptores',
                'singular' => 'descriptor',
                'grupo' => 'Asignaturas',
                'enUso' => fn (int $id) => DB::table('asignatura_descriptor')->where('descriptor_id', $id)->exists(),
            ],
            'autorizacion' => [
                'modelo' => AutorizacionReconocimiento::class,
                'etiqueta' => 'Autorización o Reconocimiento',
                'singular' => 'autorización',
                'grupo' => 'Plan de estudios',
                'enUso' => fn (int $id) => DB::table('planes_estudio')->whereNull('deleted_at')->where('autorizacion_reconocimiento_id', $id)->exists(),
            ],
            'nivel' => [
                'modelo' => NivelEstudio::class,
                'etiqueta' => 'Nivel de estudios',
                'singular' => 'nivel',
                'grupo' => 'Carreras',
                'enUso' => fn (int $id) => DB::table('carreras')->whereNull('deleted_at')->where('nivel_estudios_id', $id)->exists(),
            ],
            'turno' => [
                'modelo' => Turno::class,
                'etiqueta' => 'Turnos',
                'singular' => 'turno',
                'grupo' => 'Carreras',
                'enUso' => fn (int $id) => DB::table('oferta')->whereNull('deleted_at')->where('turno_id', $id)->exists()
                    || DB::table('grupos')->whereNull('deleted_at')->where('turno_id', $id)->exists(),
            ],
            'modalidad' => [
                'modelo' => Modalidad::class,
                'etiqueta' => 'Modalidades',
                'singular' => 'modalidad',
                'grupo' => 'Carreras',
                // La oferta guarda la modalidad como texto todavía (se liga en la
                // parte B); por ahora no hay FK que consultar.
                'enUso' => fn (int $id) => false,
            ],
        ];
    }

    public function index(Request $request): Response
    {
        $puedeEditar = $request->user()->can('editar-catalogo-academico');

        $catalogos = collect($this->registro())->map(function (array $def, string $clave) {
            /** @var class-string<Model> $modelo */
            $modelo = $def['modelo'];

            // Un catálogo con `orden` (los niveles) se lista por progresión, no
            // alfabético: bachillerato antes que licenciatura, no «Bachillerato»
            // por la B.
            $ordenable = Schema::hasColumn((new $modelo)->getTable(), 'orden');

            return [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'singular' => $def['singular'],
                'grupo' => $def['grupo'],
                'items' => $modelo::query()->orderBy($ordenable ? 'orden' : 'nombre')->get(['id', 'clave', 'nombre'])
                    ->map(fn (Model $m) => [
                        'id' => $m->id,
                        'clave' => $m->clave,
                        'nombre' => $m->nombre,
                        'en_uso' => ($def['enUso'])($m->id),
                    ]),
            ];
        })->values();

        return Inertia::render('Academico/Catalogos/Index', [
            'catalogos' => $catalogos,
            'puedeEditar' => $puedeEditar,
        ]);
    }

    public function store(Request $request, string $catalogo): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $datos = $this->validar($request, $def);

        $tabla = (new $def['modelo'])->getTable();

        // Un catálogo ordenable nace al final de la progresión: se le asigna el
        // siguiente `orden`, para que la escuela luego lo reacomode si quiere.
        if (Schema::hasColumn($tabla, 'orden')) {
            $datos['orden'] = (int) DB::table($tabla)->max('orden') + 1;
        }

        $def['modelo']::create($datos);

        return back()->with('exito', ucfirst($def['singular']).' agregada.');
    }

    public function update(Request $request, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        $registro->update($this->validar($request, $def, $item));

        return back()->with('exito', ucfirst($def['singular']).' actualizada.');
    }

    public function destroy(string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        // No se borra lo que algo usa: dejaría planes, asignaturas u ofertas
        // apuntando a un catálogo que ya no existe.
        if (($def['enUso'])($registro->id)) {
            return back()->with('error', "No se puede eliminar: hay registros que usan esta {$def['singular']}.");
        }

        $registro->delete();

        return back()->with('exito', ucfirst($def['singular']).' eliminada.');
    }

    /**
     * @param  array{modelo: class-string<Model>}  $def
     * @return array<string, mixed>
     */
    private function validar(Request $request, array $def, ?int $id = null): array
    {
        $tabla = (new $def['modelo'])->getTable();

        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique($tabla, 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
        ], [
            'clave.unique' => 'Ya existe un registro con esa clave en este catálogo.',
        ]);
    }

    /**
     * @return array{modelo: class-string<Model>, etiqueta: string, singular: string, grupo: string, enUso: callable}
     */
    private function definicion(string $catalogo): array
    {
        $registro = $this->registro();

        if (! isset($registro[$catalogo])) {
            // Una clave desconocida no es un 404 genérico: es un intento de
            // administrar un catálogo que no existe. Se rechaza como validación
            // para que quede claro qué pasó.
            throw ValidationException::withMessages(['catalogo' => 'Catálogo no reconocido.']);
        }

        return $registro[$catalogo];
    }
}
