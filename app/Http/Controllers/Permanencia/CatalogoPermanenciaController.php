<?php

declare(strict_types=1);

namespace App\Http\Controllers\Permanencia;

use App\Http\Controllers\Controller;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\MotivoCierreCaso;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\TipoIntervencion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los cuatro catálogos de alertas y permanencia.
 *
 * Mismo molde que `CatalogoProcesosController` y `CatalogoConductaController`:
 * comparten forma (clave + nombre + N banderas) y reglas (clave única, borrado
 * bloqueado cuando algo los usa, apagado que no deja huérfanos), acotado a su
 * módulo y gateado por su permiso.
 *
 * ── Lo que NO se puede tocar desde aquí, y por qué ─────────────────────────
 * `categorias_senal.sensible` y `.permiso_detalle` se VEN y no se editan. No es
 * una limitación: es una decisión de seguridad disfrazada de casilla. Apagarle
 * la sensibilidad a la categoría financiera desde un catálogo abriría los
 * adeudos de todos los alumnos a cualquiera que tenga la bandeja de alertas, y
 * lo haría desde una pantalla de captura, sin contexto y sin que nada lo
 * distinga de renombrar una fila.
 *
 * Es la misma línea que `niveles_estudio.protegido`: se ve, se ordena y se
 * apaga; lo que no se toca es lo que otras partes del sistema dan por cierto.
 * Una escuela que necesite una categoría reservada propia la pide, y llega con
 * su permiso declarado en `CatalogoPermisos` — que es donde viven las llaves.
 *
 * ── `enUso` empieza diciendo que NO ────────────────────────────────────────
 * Las tablas que los consumirán —alertas, casos, intervenciones— llegan en las
 * fases 3 a 5. Cada una se conecta aquí el día que se crea, y hasta entonces
 * `Schema::hasColumn` decide. Escribirlo al revés reventaría la pantalla con
 * «table doesn't exist» en una escuela recién migrada — y comprobar la TABLA y
 * no la COLUMNA es el defecto que este proyecto ya pagó en la fase 4 del módulo
 * formativo.
 */
class CatalogoPermanenciaController extends Controller
{
    /**
     * @return array<string, array{
     *     modelo: class-string<Model>,
     *     etiqueta: string,
     *     singular: string,
     *     enUso: callable(int): bool,
     *     extras: array<int, array<string, mixed>>
     * }>
     */
    private function registro(): array
    {
        return [
            'categoria' => [
                'modelo' => CategoriaSenal::class,
                'etiqueta' => 'Categorías de señal',
                'singular' => 'categoría',
                'enUso' => fn (int $id) => $this->usadoEn('reglas_alerta', 'categoria_id', $id)
                    || $this->usadoEn('alertas', 'categoria_id', $id),
                'extras' => [[
                    'campo' => 'sensible',
                    'tipo' => 'bandera',
                    'etiqueta' => 'Reservada',
                    'ayuda' => 'Su detalle sólo lo abre quien tiene el permiso que declara. Se define al '
                        .'crear la categoría en el código, no desde aquí: es una decisión de seguridad.',
                    'insignia' => 'Reservada',
                    'editable' => false,
                ]],
            ],
            'intervencion' => [
                'modelo' => TipoIntervencion::class,
                'etiqueta' => 'Tipos de intervención',
                'singular' => 'tipo de intervención',
                'enUso' => fn (int $id) => $this->usadoEn('intervenciones', 'tipo_intervencion_id', $id),
                'extras' => [
                    [
                        'campo' => 'exige_evidencia',
                        'tipo' => 'bandera',
                        'etiqueta' => 'Pide un archivo',
                        'ayuda' => 'Enciéndela donde el papel ES la intervención: una canalización sin oficio '
                            .'es una intención. En una llamada sobra, y pedirlo la vuelve imposible de registrar.',
                        'insignia' => 'Con evidencia',
                    ],
                    [
                        'campo' => 'exige_acuerdos',
                        'tipo' => 'bandera',
                        'etiqueta' => 'Pide escribir los acuerdos',
                        'ayuda' => 'Un contacto sin acuerdos deja al siguiente que abra el caso sin saber qué se dijo.',
                        'insignia' => 'Con acuerdos',
                    ],
                    [
                        'campo' => 'exige_proxima_fecha',
                        'tipo' => 'bandera',
                        'etiqueta' => 'Pide la siguiente fecha',
                        'ayuda' => 'Lo que no tiene próxima fecha se queda esperando a que alguien se acuerde.',
                        'insignia' => 'Con seguimiento',
                    ],
                    [
                        'campo' => 'permite_reservada',
                        'tipo' => 'bandera',
                        'etiqueta' => 'Se puede marcar reservada',
                        'ayuda' => 'Sólo donde puede haber algo personal. En un seguimiento de asistencia esconde '
                            .'del propio equipo el dato que necesita, y no protege nada.',
                        'insignia' => 'Admite reserva',
                    ],
                ],
            ],
            'motivo-cierre' => [
                'modelo' => MotivoCierreCaso::class,
                'etiqueta' => 'Motivos de cierre',
                'singular' => 'motivo de cierre',
                'enUso' => fn (int $id) => $this->usadoEn('casos_permanencia', 'motivo_cierre_id', $id),
                'extras' => [[
                    'campo' => 'cuenta_como_exito',
                    'tipo' => 'bandera',
                    'etiqueta' => 'Cuenta como que sirvió',
                    'ayuda' => 'Alimenta el indicador de efectividad. Déjala apagada en los cierres que no '
                        .'salieron bien; un traslado o un caso abierto por error no son ni una cosa ni otra.',
                    'insignia' => 'Cuenta como logro',
                ]],
            ],
            'motivo-descarte' => [
                'modelo' => MotivoDescarte::class,
                'etiqueta' => 'Motivos de descarte',
                'singular' => 'motivo de descarte',
                'enUso' => fn (int $id) => $this->usadoEn('alertas', 'motivo_descarte_id', $id),
                'extras' => [[
                    'campo' => 'cuenta_como_falso_positivo',
                    'tipo' => 'bandera',
                    'etiqueta' => 'Acusa a la regla',
                    'ayuda' => 'Enciéndela sólo cuando el descarte diga que la REGLA se equivocó. «Ya se atendió '
                        .'por otra vía» no la acusa: ahí la señal era cierta, y contarla la haría parecer mal '
                        .'calibrada justo cuando funciona.',
                    'insignia' => 'Falso positivo',
                ]],
            ],
        ];
    }

    public function index(Request $peticion): Response
    {
        $catalogos = [];

        foreach ($this->registro() as $clave => $def) {
            $campos = array_column($def['extras'], 'campo');

            $catalogos[] = [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'singular' => $def['singular'],
                'extras' => $def['extras'],
                'items' => $def['modelo']::query()
                    ->orderBy('orden')->orderBy('nombre')
                    ->get(array_merge(['id', 'clave', 'nombre', 'descripcion', 'orden', 'activo'], $campos))
                    ->map(fn (Model $m) => array_merge($m->only(
                        array_merge(['id', 'clave', 'nombre', 'descripcion', 'orden', 'activo'], $campos),
                    ), ['en_uso' => $def['enUso']($m->getKey())])),
            ];
        }

        return Inertia::render('Permanencia/Catalogos', [
            'catalogos' => $catalogos,
            'puedeEditar' => $peticion->user()?->can('configurar-reglas-alerta') === true,
        ]);
    }

    public function store(Request $peticion, string $catalogo): RedirectResponse
    {
        $def = $this->definicion($catalogo);

        $def['modelo']::create($this->validar($peticion, $def));

        return back(303)->with('exito', 'Se agregó.');
    }

    public function update(Request $peticion, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $modelo = $def['modelo']::findOrFail($item);

        $modelo->update($this->validar($peticion, $def, $item));

        return back(303)->with('exito', 'Se guardó.');
    }

    /**
     * Borrar sólo lo que nadie usa.
     *
     * Y se dice POR QUÉ no se puede, en vez de un botón apagado sin explicación:
     * es la lección que este proyecto ya aplicó a los catálogos académicos.
     */
    public function destroy(string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);

        if ($def['enUso']($item)) {
            return back(303)->with('error',
                'No se puede eliminar: ya hay registros que lo usan. Apágalo para retirarlo de los '
                .'desplegables sin dejarlos colgando.');
        }

        $def['modelo']::findOrFail($item)->delete();

        return back(303)->with('exito', 'Se eliminó.');
    }

    public function alternar(Request $peticion, string $catalogo, int $item): RedirectResponse
    {
        $def = $this->definicion($catalogo);
        $modelo = $def['modelo']::findOrFail($item);

        $modelo->update(['activo' => $peticion->boolean('activo')]);

        return back(303)->with('exito', $modelo->activo ? 'Se encendió.' : 'Se apagó.');
    }

    /**
     * ¿Algo apunta a esta fila?
     *
     * Se pregunta por la COLUMNA y no por la tabla: las fases siguientes crean
     * tablas que todavía no tienen todas sus columnas, y comprobar sólo
     * `hasTable` revienta con «Unknown column». Es el defecto que la fase 4 del
     * módulo formativo ya pagó.
     */
    private function usadoEn(string $tabla, string $columna, int $id): bool
    {
        if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
            return false;
        }

        return \DB::table($tabla)->where($columna, $id)->exists();
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private function validar(Request $peticion, array $def, ?int $id = null): array
    {
        $tabla = (new $def['modelo'])->getTable();

        $reglas = [
            'clave' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique($tabla, 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];

        /*
         * Sólo las EDITABLES entran a la validación. Una no editable que llegue
         * en la petición se descarta aquí y no por casualidad: `$fillable` la
         * dejaría pasar, así que sin este filtro alguien podría apagarle la
         * sensibilidad a la categoría financiera con una petición a mano — y la
         * pantalla que no la ofrece no es una defensa.
         */
        foreach ($def['extras'] as $extra) {
            if (($extra['editable'] ?? true) === false) {
                continue;
            }

            $reglas[$extra['campo']] = $extra['tipo'] === 'entero'
                ? ['required', 'integer', 'min:0']
                : ['boolean'];
        }

        $datos = $peticion->validate($reglas, [], ['clave' => 'clave']);

        foreach ($def['extras'] as $extra) {
            if (($extra['editable'] ?? true) === false) {
                unset($datos[$extra['campo']]);

                continue;
            }

            /*
             * `validate` devuelve la casilla como llegó —«1», «0» o ausente— y
             * en PHP «0» es verdadero. Convertir aquí es lo que impide que
             * apagar una bandera no haga nada: es la trampa que ya se cobró el
             * motor de reportes y la autorización de becas.
             */
            if ($extra['tipo'] === 'bandera') {
                $datos[$extra['campo']] = $peticion->boolean($extra['campo']);
            }
        }

        return $datos;
    }

    /** @return array<string, mixed> */
    private function definicion(string $catalogo): array
    {
        $registro = $this->registro();

        if (! isset($registro[$catalogo])) {
            throw ValidationException::withMessages(['catalogo' => 'Ese catálogo no existe.']);
        }

        return $registro[$catalogo];
    }
}
