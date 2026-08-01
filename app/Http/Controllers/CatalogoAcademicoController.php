<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Area;
use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\Descriptor;
use App\Models\Academico\Modalidad;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\TipoAsignatura;
use App\Models\Academico\TipoPeriodo;
use App\Models\Academico\Turno;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\IdentidadFederativa;
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
                // Campos extra más allá de clave+nombre. El color pinta la
                // materia en la vista de cuadrícula de la malla.
                'extras' => [
                    'color' => ['tipo' => 'color', 'etiqueta' => 'Color'],
                ],
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
                // Identificador oficial (SEP) que viaja en el certificado electrónico.
                'extras' => ['identificador' => ['tipo' => 'texto', 'etiqueta' => 'Identificador']],
            ],
            'tipoperiodo' => [
                'modelo' => TipoPeriodo::class,
                'etiqueta' => 'Tipos de periodo',
                'singular' => 'tipo de periodo',
                'grupo' => 'Plan de estudios',
                'enUso' => fn (int $id) => DB::table('planes_estudio')->whereNull('deleted_at')->where('tipo_periodo_id', $id)->exists(),
                'extras' => ['identificador' => ['tipo' => 'texto', 'etiqueta' => 'Identificador']],
            ],
            'tipoasignatura' => [
                'modelo' => TipoAsignatura::class,
                'etiqueta' => 'Tipos de asignatura',
                'singular' => 'tipo de asignatura',
                'grupo' => 'Asignaturas',
                // Quien consume este catálogo es la ASIGNATURA
                // (`asignaturas.tipo_asignatura_id`, FK real), no la materia dentro
                // del plan. `plan_materias.tipo` se le parece pero es otra cosa: el
                // papel que la materia juega en ESE plan —incluye `tronco_comun`,
                // que no es un tipo SEP— y es texto libre sin FK a esta tabla.
                'enUso' => fn (int $id) => DB::table('asignaturas')->whereNull('deleted_at')->where('tipo_asignatura_id', $id)->exists(),
                'extras' => ['identificador' => ['tipo' => 'texto', 'etiqueta' => 'Identificador']],
            ],
            'turno' => [
                'modelo' => Turno::class,
                'etiqueta' => 'Turnos',
                'singular' => 'turno',
                'grupo' => 'Carreras',
                // El turno se eliminó de la oferta; hoy solo lo usan los grupos.
                'enUso' => fn (int $id) => DB::table('grupos')->whereNull('deleted_at')->where('turno_id', $id)->exists(),
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
            // Catálogos de valores oficiales (niveles, tipos de periodo) traen
            // `protegido`; el resto no tiene la columna.
            $protegible = Schema::hasColumn((new $modelo)->getTable(), 'protegido');
            // La clave SAT (hoy sólo en niveles) se muestra como informativa: es
            // dato oficial derivado del nivel, no editable desde aquí.
            $conClaveSat = Schema::hasColumn((new $modelo)->getTable(), 'clave_sat');
            $extras = $def['extras'] ?? [];

            return [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'singular' => $def['singular'],
                'grupo' => $def['grupo'],
                // Metadatos de los campos extra (p. ej. color) para que la UI
                // sepa qué input pintar; vacío para los catálogos simples.
                'extras' => $extras,
                'items' => $modelo::query()->orderBy($ordenable ? 'orden' : 'nombre')
                    ->get(array_merge(['id', 'clave', 'nombre'], $protegible ? ['protegido'] : [], $conClaveSat ? ['clave_sat'] : [], array_keys($extras)))
                    ->map(fn (Model $m) => array_merge([
                        'id' => $m->id,
                        'clave' => $m->clave,
                        'nombre' => $m->nombre,
                        'en_uso' => ($def['enUso'])($m->id),
                        'protegido' => $protegible ? (bool) $m->protegido : false,
                    ], $conClaveSat ? ['clave_sat' => $m->clave_sat] : [],
                        collect($extras)->keys()->mapWithKeys(fn (string $k) => [$k => $m->{$k}])->all())),
            ];
        })->values();

        return Inertia::render('Academico/Catalogos/Index', [
            'catalogos' => $catalogos,
            // Los globales (landlord) viajan como SOLO LECTURA: se muestran para
            // consulta, pero no se editan desde una escuela porque los comparten
            // todas. Su administración real vive fuera del ámbito de una escuela.
            'globales' => [
                [
                    'etiqueta' => 'Entidad Federativa',
                    'descripcion' => 'Para lugares (campus, institución). El 33 es «Extranjero».',
                    'items' => EntidadFederativa::query()->orderBy('id')->get(['clave', 'nombre']),
                ],
                [
                    'etiqueta' => 'Identidad Federativa',
                    'descripcion' => 'Para personas (lugar de nacimiento). El 33 es «Nacido en el extranjero».',
                    'items' => IdentidadFederativa::query()->orderBy('id')->get(['clave', 'nombre']),
                ],
            ],
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

        // Un color no asignado al alta nace en un tono pastel aleatorio: la
        // cuadrícula de la malla nunca queda sin color, y cada área se distingue.
        if (array_key_exists('color', $def['extras'] ?? []) && empty($datos['color'])) {
            $datos['color'] = $this->colorPastelAleatorio();
        }

        $def['modelo']::create($datos);

        return back()->with('exito', ucfirst($def['singular']).' agregada.');
    }

    public function update(Request $request, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        // Los valores oficiales (niveles, tipos de periodo) son fijos: no se
        // editan aunque llegue un POST armado a mano.
        if ($registro->protegido ?? false) {
            return back()->with('error', "Este valor de {$def['singular']} es oficial y no se puede modificar.");
        }

        $registro->update($this->validar($request, $def, $item));

        return back()->with('exito', ucfirst($def['singular']).' actualizada.');
    }

    public function destroy(string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        if ($registro->protegido ?? false) {
            return back()->with('error', "Este valor de {$def['singular']} es oficial y no se puede eliminar.");
        }

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

        $reglas = [
            'clave' => ['required', 'string', 'max:50', Rule::unique($tabla, 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
        ];

        // Los campos extra se validan según su tipo. Hoy sólo `color` (hex #RRGGBB).
        foreach ($def['extras'] ?? [] as $campo => $meta) {
            $reglas[$campo] = match ($meta['tipo']) {
                'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                default => ['nullable', 'string', 'max:255'],
            };
        }

        return $request->validate($reglas, [
            'clave.unique' => 'Ya existe un registro con esa clave en este catálogo.',
            'color.regex' => 'El color debe ser hexadecimal, por ejemplo #A3D9C7.',
        ]);
    }

    /**
     * Un color pastel aleatorio: cada canal se mezcla a medias con blanco, así
     * el tono siempre sale claro y suave (127–255 por canal), legible como fondo.
     */
    private function colorPastelAleatorio(): string
    {
        $canal = fn () => (int) round((random_int(0, 255) + 255) / 2);

        return sprintf('#%02X%02X%02X', $canal(), $canal(), $canal());
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
