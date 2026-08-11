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
use App\Models\Emision\TipoCertificacion;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Models\Landlord\IdentidadFederativa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
                /*
                 * El nivel lo usan OCHO tablas, no sólo las carreras.
                 *
                 * Y dos de ellas sin llave foránea: `evento_destinos` apunta a
                 * catálogos distintos según su `tipo` —es lo que permite dirigir
                 * un aviso «por nivel» sin migrar—, y `emisor_asignaciones`
                 * igual. Preguntar sólo por `carreras` dejaría apagar un nivel
                 * que sostiene un aviso del calendario o la razón social con la
                 * que se factura ese nivel, y esas dos se romperían en silencio.
                 */
                'enUso' => fn (int $id) => $this->usadoEn($id, [
                    ['carreras', 'nivel_estudios_id'],
                    ['ciclo_nivel', 'nivel_estudios_id'],
                    ['grupos', 'nivel_estudios_id'],
                    ['credenciales_rol', 'nivel_estudios_id'],
                    ['disenos_historial', 'nivel_estudios_id'],
                    ['plan_cobro_carrera', 'nivel_estudios_id'],
                ]) || $this->usadoComoDestino('nivel', $id) || $this->usadoComoEmisor('nivel', $id),
                'apagable' => true,
            ],
            'tipoperiodo' => [
                'modelo' => TipoPeriodo::class,
                'etiqueta' => 'Tipos de periodo',
                'singular' => 'tipo de periodo',
                'grupo' => 'Plan de estudios',
                'enUso' => fn (int $id) => $this->usadoEn($id, [['planes_estudio', 'tipo_periodo_id']]),
                'apagable' => true,
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
            ],
            /*
             * Tipos de certificación (79 = Total, 80 = Parcial).
             *
             * Vivía en Certificación → Configuración, con su propio controlador
             * y su propia pantalla para hacer exactamente lo que hace este
             * registro: clave y nombre. Dos CRUD para el
             * mismo formulario es donde uno gana una validación que el otro no
             * tiene.
             *
             * ── Por qué `enUso` es siempre falso ──────────────────────────
             * Porque NADA lo referencia: ninguna tabla tiene
             * `tipo_certificacion_id`, y el XML del DEC escribe el 79 o el 80
             * como literal según el certificado sea total o parcial
             * (`ConstructorCertificadoXml`). El catálogo está para que la
             * escuela vea los valores oficiales y pueda agregar los suyos si
             * algún día el DEC los admite. Decir que «está en uso» sería
             * mentir, y bloquear el borrado por si acaso deja una lista que
             * nadie puede limpiar.
             */
            'tipocertificacion' => [
                'modelo' => TipoCertificacion::class,
                'etiqueta' => 'Tipos de certificación',
                'singular' => 'tipo de certificación',
                'grupo' => 'Certificación',
                'enUso' => fn (int $id) => false,
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

    /**
     * ¿Algún renglón vivo de estas tablas apunta a ese id?
     *
     * @param  array<int, array{0: string, 1: string}>  $columnas  tabla y columna
     */
    private function usadoEn(int $id, array $columnas): bool
    {
        foreach ($columnas as [$tabla, $columna]) {
            // Se comprueba la tabla porque el registro es único para todas las
            // escuelas y una puede ir una migración por detrás.
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
                continue;
            }

            $consulta = DB::table($tabla)->where($columna, $id);

            if (Schema::hasColumn($tabla, 'deleted_at')) {
                $consulta->whereNull('deleted_at');
            }

            if ($consulta->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un aviso del calendario dirigido a este ítem.
     *
     * `evento_destinos` no tiene foránea a propósito —apunta a tablas distintas
     * según su `tipo`, y es lo que permite agregar «por turno» mañana sin
     * migrar—, así que hay que preguntarle por las dos columnas.
     */
    private function usadoComoDestino(string $tipo, int $id): bool
    {
        return Schema::hasTable('evento_destinos')
            && DB::table('evento_destinos')->where('tipo', $tipo)->where('destino_id', $id)->exists();
    }

    /** Una razón social asignada a este ítem (precedencia carrera → nivel → global). */
    private function usadoComoEmisor(string $tipo, int $id): bool
    {
        return Schema::hasTable('emisor_asignaciones')
            && DB::table('emisor_asignaciones')
                ->whereNull('deleted_at')
                ->where('aplica_a_tipo', $tipo)
                ->where('aplica_a_id', $id)
                ->exists();
    }

    /**
     * Enciende o apaga un ítem del catálogo.
     *
     * ── Sólo se apaga lo que nadie usa ────────────────────────────────────
     * Es la regla que hace segura toda la función: si nada apunta a ese nivel,
     * apagarlo no puede dejar huérfano ningún dato ya guardado, y por eso el
     * resto del sistema puede filtrar los desplegables sin miedo. Encender, en
     * cambio, nunca se bloquea: devolver algo a la lista no rompe nada.
     *
     * Lo PROTEGIDO tampoco impide apagar. Protegido significa que su clave no
     * se toca —hay código y XML de la SEP que la conocen—, no que la escuela
     * esté obligada a ofrecer «Doctorado» en sus desplegables.
     */
    public function alternar(Request $request, string $catalogo, int $id): RedirectResponse
    {
        $def = $this->registro()[$catalogo] ?? null;

        abort_if($def === null || ($def['apagable'] ?? false) !== true, 404);

        /** @var Model $item */
        $item = $def['modelo']::query()->findOrFail($id);
        $encender = $request->boolean('activo');

        if (! $encender && ($def['enUso'])($id)) {
            throw ValidationException::withMessages([
                'activo' => "No se puede apagar: hay información que usa este {$def['singular']}. "
                    .'Retírala primero o déjalo encendido.',
            ]);
        }

        $item->update(['activo' => $encender]);

        return back(303)->with(
            'exito',
            $encender
                ? Str::ucfirst($def['singular']).' encendido: ya aparece en los desplegables.'
                : Str::ucfirst($def['singular']).' apagado: deja de ofrecerse en los desplegables.',
        );
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
            // Sólo los catálogos que declaran `apagable` traen la columna.
            $apagable = ($def['apagable'] ?? false) === true;
            $extras = $def['extras'] ?? [];

            return [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'singular' => $def['singular'],
                'grupo' => $def['grupo'],
                // Metadatos de los campos extra (p. ej. color) para que la UI
                // sepa qué input pintar; vacío para los catálogos simples.
                'extras' => $extras,
                'apagable' => $apagable,
                'items' => $modelo::query()->orderBy($ordenable ? 'orden' : 'nombre')
                    ->get(array_merge(['id', 'clave', 'nombre'], $protegible ? ['protegido'] : [], $apagable ? ['activo'] : [], $conClaveSat ? ['clave_sat'] : [], array_keys($extras)))
                    ->map(fn (Model $m) => array_merge([
                        'id' => $m->id,
                        'clave' => $m->clave,
                        'nombre' => $m->nombre,
                        'en_uso' => ($def['enUso'])($m->id),
                        'protegido' => $protegible ? (bool) $m->protegido : false,
                        // Los que no se pueden apagar viajan como encendidos:
                        // así la pantalla no tiene que distinguir dos casos para
                        // pintar el mismo renglón.
                        'activo' => $apagable ? (bool) $m->activo : true,
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
                [
                    'etiqueta' => 'Género',
                    // Los números son los que viajan en el XML como `idGenero`:
                    // cambiar uno aquí rompería el certificado de TODAS las
                    // escuelas, no sólo el de ésta. Por eso se consulta y no se
                    // edita.
                    'descripcion' => 'Lo que la SEP pide en el certificado y el título electrónicos (idGenero). Fijo para todas las escuelas.',
                    'items' => Genero::query()->orderBy('id')->get(['clave', 'nombre']),
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
