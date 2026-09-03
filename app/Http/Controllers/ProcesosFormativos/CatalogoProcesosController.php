<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Http\Controllers\Controller;
use App\Models\ProcesosFormativos\ModalidadProceso;
use App\Models\ProcesosFormativos\SectorOrganizacion;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoConvenioFormativo;
use App\Models\ProcesosFormativos\TipoInformeProceso;
use App\Models\ProcesosFormativos\TipoOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los catálogos de servicio social, prácticas y demás procesos formativos.
 *
 * ── Por qué son tabla y no un enum ─────────────────────────────────────────
 * Ninguna escuela pide lo mismo: un tecnológico exige residencia con 80 % de
 * créditos, una normal exige dos años de servicio social, una privada 480 horas
 * de prácticas desde séptimo. Y ni siquiera coinciden en CÓMO SE LLAMAN las
 * cosas. Cablearlo dejaría a todas con los mismos ocho casos de ejemplo.
 *
 * ── Y lo que el código consulta son las BANDERAS ───────────────────────────
 * `exige_organizacion`, `cuenta_horas`, `acepta_asignaciones`, `es_final`.
 * Nunca la clave: preguntar por `clave === 'servicio_social'` funciona hoy y
 * deja de funcionar EN SILENCIO el día que la escuela edite su catálogo. Es la
 * lección de `entra_a_nomina` y de `cuenta_como_egresado`.
 *
 * ── Un solo controlador para los siete ─────────────────────────────────────
 * Comparten forma (clave + nombre + N banderas) y reglas (clave única, borrado
 * bloqueado cuando algo los usa, apagado que no deja huérfanos). El mismo
 * criterio que `CatalogoAcademicoController` y `CatalogoConductaController`,
 * acotado a su módulo y gateado por su permiso: los catálogos de servicio
 * social no son catálogos de Académico.
 *
 * ── `enUso` empieza diciendo que NO ────────────────────────────────────────
 * Las tablas que los consumirán —expedientes, organizaciones, convenios— no
 * existen todavía: llegan en las fases 2 a 5. Cada una se conecta aquí el día
 * que se crea, y hasta entonces `Schema::hasTable` decide. Escribirlo al revés
 * —dar por hecho que la tabla está— reventaría la pantalla con «table doesn't
 * exist» en una escuela recién migrada.
 */
class CatalogoProcesosController extends Controller
{
    /**
     * El registro de catálogos administrables.
     *
     * @return array<string, array{
     *     modelo: class-string<Model>,
     *     etiqueta: string,
     *     singular: string,
     *     enUso: callable(int): bool,
     *     extras: array<int, array{campo: string, tipo: string, etiqueta: string, ayuda: string, insignia?: string, max?: int}>
     * }>
     */
    private function registro(): array
    {
        return [
            'tipo-proceso' => [
                'modelo' => TipoProcesoFormativo::class,
                'etiqueta' => 'Tipos de proceso',
                'singular' => 'tipo de proceso',
                'enUso' => fn (int $id) => $this->usadoEn('expedientes_proceso', 'tipo_proceso_id', $id),
                'extras' => [
                    [
                        'campo' => 'exige_organizacion',
                        'tipo' => 'bandera',
                        'etiqueta' => 'Exige organización receptora',
                        'ayuda' => 'Apagado para lo que organiza la propia escuela —un proyecto comunitario—: obligarla convertiría en trámite falso capturarse a sí misma como si fuera un tercero.',
                        'insignia' => 'Con organización',
                    ],
                    [
                        'campo' => 'exige_plaza',
                        'tipo' => 'bandera',
                        'etiqueta' => 'Exige elegir una plaza publicada',
                        'ayuda' => 'Encendido donde la escuela coloca (residencia, internado); apagado donde el alumno llega con su carta.',
                        'insignia' => 'Con plaza',
                    ],
                    [
                        'campo' => 'permite_organizacion_propuesta',
                        'tipo' => 'bandera',
                        'etiqueta' => 'El alumno puede proponer organización',
                        'ayuda' => 'Lo que proponga entra «en revisión» y no recibe a nadie hasta que se autoriza.',
                        'insignia' => 'Admite propuestas',
                    ],
                    [
                        'campo' => 'cuenta_horas',
                        'tipo' => 'bandera',
                        'etiqueta' => 'Lleva bitácora de horas',
                        'ayuda' => 'Apagado para lo que se acredita con una constancia —una experiencia profesional—: pedirle bitácora dejaría el expediente esperando algo que nadie va a capturar.',
                        'insignia' => 'Con horas',
                    ],
                ],
            ],

            'sector' => [
                'modelo' => SectorOrganizacion::class,
                'etiqueta' => 'Sectores',
                'singular' => 'sector',
                'enUso' => fn (int $id) => $this->usadoEn('organizaciones_receptoras', 'sector_id', $id),
                'extras' => [],
            ],

            'tipo-organizacion' => [
                'modelo' => TipoOrganizacion::class,
                'etiqueta' => 'Tipos de organización',
                'singular' => 'tipo de organización',
                'enUso' => fn (int $id) => $this->usadoEn('organizaciones_receptoras', 'tipo_id', $id),
                'extras' => [],
            ],

            'situacion-organizacion' => [
                'modelo' => SituacionOrganizacion::class,
                'etiqueta' => 'Situaciones de la organización',
                'singular' => 'situación',
                'enUso' => fn (int $id) => $this->usadoEn('organizaciones_receptoras', 'situacion_id', $id),
                'extras' => [[
                    'campo' => 'acepta_asignaciones',
                    'tipo' => 'bandera',
                    'etiqueta' => 'Puede recibir alumnos',
                    'ayuda' => 'Es la bandera que decide si se le manda a alguien. Una organización «en revisión» existe en el padrón y todavía no recibe.',
                    'insignia' => 'Recibe alumnos',
                ]],
            ],

            'tipo-convenio' => [
                'modelo' => TipoConvenioFormativo::class,
                'etiqueta' => 'Tipos de convenio',
                'singular' => 'tipo de convenio',
                'enUso' => fn (int $id) => $this->usadoEn('convenios_formativos', 'tipo_convenio_id', $id),
                'extras' => [],
            ],

            'modalidad' => [
                'modelo' => ModalidadProceso::class,
                'etiqueta' => 'Modalidades',
                'singular' => 'modalidad',
                'enUso' => fn (int $id) => $this->usadoEn('expedientes_proceso', 'modalidad_id', $id),
                'extras' => [[
                    'campo' => 'es_a_distancia',
                    'tipo' => 'bandera',
                    'etiqueta' => 'Ocurre a distancia',
                    'ayuda' => 'La bitácora de horas lo consulta: a quien trabaja en remoto no tiene sentido pedirle dónde está.',
                    'insignia' => 'A distancia',
                ]],
            ],

            'tipo-informe' => [
                'modelo' => TipoInformeProceso::class,
                'etiqueta' => 'Tipos de informe',
                'singular' => 'tipo de informe',
                'enUso' => fn (int $id) => $this->usadoEn('informes_proceso', 'tipo_informe_id', $id),
                'extras' => [[
                    'campo' => 'es_final',
                    'tipo' => 'bandera',
                    'etiqueta' => 'Es el informe que cierra el proceso',
                    'ayuda' => 'La liberación lo exige. Cuántos parciales pide cada programa lo dice su regla, no este catálogo.',
                    'insignia' => 'Final',
                ]],
            ],
        ];
    }

    public function index(Request $peticion): Response
    {
        $catalogos = collect($this->registro())->map(function (array $def, string $clave) {
            $modelo = $def['modelo'];
            $campos = array_column($def['extras'], 'campo');

            return [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'singular' => $def['singular'],
                'extras' => $def['extras'],
                'items' => $modelo::query()
                    ->orderBy('orden')
                    ->orderBy('nombre')
                    ->get(array_merge(['id', 'clave', 'nombre', 'descripcion', 'orden', 'activo'], $campos))
                    ->map(fn (Model $m) => array_merge([
                        'id' => $m->id,
                        'clave' => $m->clave,
                        'nombre' => $m->nombre,
                        'descripcion' => $m->descripcion,
                        'activo' => (bool) $m->activo,
                        'en_uso' => ($def['enUso'])($m->id),
                    ], collect($campos)->mapWithKeys(fn (string $c) => [$c => $m->{$c}])->all())),
            ];
        })->values();

        return Inertia::render('Procesos/Catalogos', [
            'catalogos' => $catalogos,
            // Se entra con `ver-procesos-formativos` y sólo se toca con el de
            // configurar: dirección y auditoría leen sin poder editar.
            'puedeEditar' => $peticion->user()->can('configurar-procesos-formativos'),
        ]);
    }

    public function store(Request $peticion, string $catalogo): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $datos = $this->validar($peticion, $def);

        // Nace al final del orden; la escuela lo reacomoda después si quiere.
        $tabla = (new $def['modelo'])->getTable();
        $datos['orden'] = (int) DB::table($tabla)->max('orden') + 1;
        $datos['activo'] = true;

        $def['modelo']::create($datos);

        return back(303)->with('exito', ucfirst($def['singular']).' agregado.');
    }

    public function update(Request $peticion, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        $registro->update($this->validar($peticion, $def, $item));

        return back(303)->with('exito', ucfirst($def['singular']).' actualizado.');
    }

    public function destroy(string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);

        // No se borra lo que algo usa: dejaría expedientes apuntando a un tipo
        // que ya no existe. Para retirarlo de los desplegables se APAGA.
        if (($def['enUso'])($registro->id)) {
            return back(303)->with('error', "No se puede eliminar: hay registros que usan este {$def['singular']}. Apágalo para retirarlo de los desplegables.");
        }

        $registro->delete();

        return back(303)->with('exito', ucfirst($def['singular']).' eliminado.');
    }

    /**
     * Enciende o apaga.
     *
     * Apagar sólo se permite si nadie lo usa: así el resto del sistema puede
     * filtrar los desplegables con `->activos()` sin dejar huérfano nada ya
     * capturado. Encender nunca se bloquea.
     */
    public function alternar(Request $peticion, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $registro = $def['modelo']::query()->findOrFail($item);
        $encender = $peticion->boolean('activo');

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
     * ¿Algo usa esta fila?
     *
     * La tabla que la consume puede no existir todavía —el módulo se construye
     * por fases—, así que se comprueba antes de consultarla. Sin eso, la
     * pantalla de catálogos reventaría con «table doesn't exist» hasta que
     * llegara la fase que crea esa tabla.
     */
    private function usadoEn(string $tabla, string $columna, int $id): bool
    {
        if (! DB::getSchemaBuilder()->hasTable($tabla)) {
            return false;
        }

        return DB::table($tabla)->whereNull('deleted_at')->where($columna, $id)->exists();
    }

    /**
     * @param  array{modelo: class-string<Model>, extras: array<int, array<string, mixed>>}  $def
     * @return array<string, mixed>
     */
    private function validar(Request $peticion, array $def, ?int $id = null): array
    {
        $tabla = (new $def['modelo'])->getTable();

        $reglas = [
            'clave' => ['required', 'string', 'max:50', Rule::unique($tabla, 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ];

        foreach ($def['extras'] as $extra) {
            $reglas[$extra['campo']] = match ($extra['tipo']) {
                'entero' => ['required', 'integer', 'min:1', 'max:'.($extra['max'] ?? 9)],
                'bandera' => ['required', 'boolean'],
                default => ['nullable'],
            };
        }

        $datos = $peticion->validate($reglas, [
            'clave.unique' => 'Ya existe un registro con esa clave en este catálogo.',
        ]);

        /*
         * Validar NO es convertir: la regla `boolean` ACEPTA la cadena «1» —lo
         * que manda una casilla— y devuelve el valor tal cual. Aquí lo salva el
         * `casts()` del modelo, pero se convierte igual para que lo que llega a
         * `create()` sea del tipo que dice ser.
         */
        foreach ($def['extras'] as $extra) {
            if ($extra['tipo'] === 'bandera') {
                $datos[$extra['campo']] = filter_var($datos[$extra['campo']], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $datos;
    }

    /**
     * @return array{modelo: class-string<Model>, etiqueta: string, singular: string, enUso: callable, extras: array<int, array<string, mixed>>}
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
