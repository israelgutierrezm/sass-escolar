<?php

/**
 * Cimientos, catálogos y reglas de alerta (fase 1). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-reglas.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Las tres prohibiciones duras del módulo**, que no pueden vivir sólo en
 *     la prosa: ningún eje de alcance es un atributo sensible, ningún texto
 *     sembrado lleva una etiqueta punitiva, y —cuando el motor exista— nada de
 *     esto escribe en el expediente del alumno.
 *  2. **Que las banderas se lean y no las claves.** Un catálogo cuyo código
 *     pregunta por `clave === 'financiera'` no es configurable: se prueba con
 *     una categoría inventada, con un nombre que ningún `match` conoce.
 *  3. **Que una regla mal capturada se REHÚSE.** Las cuatro guardas contra una
 *     regla que se guardaría, no mediría nada, y dejaría a quien la escribió
 *     creyendo que sí.
 *  4. **Que las versiones congelen.** Cambiar el umbral no puede reescribir con
 *     qué umbral se levantó una alerta vieja.
 *  5. **Que lo que no se puede tocar, no se toque**: la sensibilidad de una
 *     categoría no se edita desde el catálogo ni con una petición a mano.
 */

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\ExclusionReglaAlerta;
use App\Models\Permanencia\MotivoCierreCaso;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\Permanencia\TipoIntervencion;
use App\Models\Tenant;
use App\Permanencia\CatalogoMetricas;
use App\Support\CatalogoPermisos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

require __DIR__.'/apoyo-permanencia.php';

$db = DB::connection('tenant');

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;

    $verificaciones++;
    $ok || $fallidas++;

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function usuarioConRol(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Permanencia',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_perm_'.random_int(100000, 999999),
        'email' => 'prueba_perm_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);

    $cuenta->persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true, 'campus_id' => null]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/**
 * Una peticion para invocar al controlador, con su usuario resuelto.
 *
 * Las suites de este proyecto invocan a los CONTROLADORES y no reimplementan
 * la consulta: es la unica forma de cazar lo que revienta desde la pantalla y
 * no desde el servicio. Fue asi como se encontraron el `$fillable` que faltaba
 * en `Pago` y la columna `activa` que el trait no sabia leer.
 */
function peticionCon(array $datos, Usuario $como, string $metodo = 'POST'): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $como);

    return $p;
}

/** Las props que el controlador manda a Inertia. */
function props(object $controlador, string $metodo, Usuario $como, array $query = []): array
{
    $peticion = Illuminate\Http\Request::create('/', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    return $controlador->{$metodo}($peticion)->toResponse($peticion)->getData(true)['props'] ?? [];
}

const PREFIJO = 'ZZPERM-';

$db->beginTransaction();

try {
    $global = usuarioConRol('director_general');
    auth()->login($global);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $controlador = app(App\Http\Controllers\Permanencia\ReglaAlertaController::class);
    $catalogos = app(App\Http\Controllers\Permanencia\CatalogoPermanenciaController::class);

    echo '1. El módulo, los permisos y los catálogos existen'.PHP_EOL;

    verificar('El módulo «permanencia» está en el catálogo',
        DB::table('modulos')->where('clave', 'permanencia')->exists());

    verificar('Y encendido',
        DB::table('modulos_activos')
            ->whereIn('modulo_id', DB::table('modulos')->where('clave', 'permanencia')->pluck('id'))
            ->where('activo', true)->exists());

    verificar('El permiso de configurar reglas está declarado',
        CatalogoPermisos::existe('configurar-reglas-alerta'));

    verificar('Y es de faceta administrativa, no de alumno ni de docente',
        CatalogoPermisos::correspondeA('configurar-reglas-alerta', CatalogoPermisos::ADMINISTRATIVO)
        && ! CatalogoPermisos::correspondeA('configurar-reglas-alerta', CatalogoPermisos::ALUMNO)
        && ! CatalogoPermisos::correspondeA('configurar-reglas-alerta', CatalogoPermisos::DOCENTE));

    verificar('Dirección general lo hereda de su faceta',
        Rol::where('name', 'director_general')->firstOrFail()->concede('configurar-reglas-alerta'));

    foreach ([
        'categorias_senal' => CategoriaSenal::class,
        'tipos_intervencion' => TipoIntervencion::class,
        'motivos_cierre_caso' => MotivoCierreCaso::class,
        'motivos_descarte' => MotivoDescarte::class,
    ] as $tabla => $modelo) {
        verificar('El catálogo «'.$tabla.'» está sembrado', $modelo::query()->count() > 0,
            (string) $modelo::query()->count());
    }

    echo PHP_EOL.'2. LA PROHIBICIÓN DURA: ningún eje es un atributo sensible'.PHP_EOL;

    /*
     * Esto no puede vivir sólo en el docblock. Acotar una regla por «tiene
     * beca» o por sexo convertiría una política de equidad en una marca, y
     * subiría el riesgo de quien recibe apoyo por el hecho de recibirlo.
     *
     * Se comprueba sobre las COLUMNAS de la tabla, no sobre una lista escrita
     * en el código: así el día que alguien agregue la columna, esto se cae.
     */
    $prohibidos = ['sexo', 'genero', 'beca', 'nacionalidad', 'religion', 'estado_civil',
        'discapacidad', 'ingreso', 'socioeconomic', 'etnia', 'salud'];

    $columnas = \Illuminate\Support\Facades\Schema::getColumnListing('reglas_alerta');

    $sospechosas = array_values(array_filter(
        $columnas,
        fn (string $c) => (bool) array_filter($prohibidos, fn (string $p) => str_contains($c, $p)),
    ));

    verificar('`reglas_alerta` no tiene ninguna columna de atributo sensible',
        $sospechosas === [], implode(', ', $sospechosas) ?: implode(', ', $columnas));

    echo PHP_EOL.'3. LA PROHIBICIÓN DURA: ninguna etiqueta punitiva'.PHP_EOL;

    /*
     * El pedido lo dice con esas palabras: nada de «alumno problemático»,
     * «moroso» ni «desertor probable». Una regla que sólo vive en la prosa se
     * rompe en el tercer commit, así que se barre lo SEMBRADO —que es lo que
     * toda escuela va a leer— contra una lista negra.
     */
    $negras = ['moroso', 'desertor', 'problemátic', 'problematic', 'conflictiv', 'reprobado ',
        'fracasad', 'peligros', 'vago'];

    $textos = collect([
        CategoriaSenal::class, TipoIntervencion::class, MotivoCierreCaso::class,
        MotivoDescarte::class, ReglaAlerta::class,
    ])
        ->flatMap(fn (string $modelo) => $modelo::query()->get()
            ->flatMap(fn ($fila) => [$fila->nombre, $fila->descripcion, $fila->notas ?? null]))
        ->merge(ReglaAlertaVersion::query()->pluck('notas'))
        ->filter(fn ($t) => is_string($t) && $t !== '')
        ->map(fn (string $t) => mb_strtolower($t))
        ->values();

    $encontradas = [];

    foreach ($textos as $texto) {
        foreach ($negras as $palabra) {
            str_contains($texto, $palabra) && $encontradas[] = $palabra.' → '.mb_substr($texto, 0, 40);
        }
    }

    verificar('Nada de lo sembrado usa una etiqueta punitiva',
        $encontradas === [], implode(' | ', array_slice($encontradas, 0, 3)));

    verificar('Y hay textos que revisar: el barrido no pasó por vacío',
        $textos->count() > 40, (string) $textos->count());

    echo PHP_EOL.'4. Las BANDERAS, no las claves'.PHP_EOL;

    /*
     * El caso que separa las dos formas: una categoría que la escuela inventa,
     * con un nombre que ningún `match` del código conoce. Si algo preguntara por
     * `clave === 'financiera'`, ésta se comportaría distinto.
     */
    $inventada = CategoriaSenal::create([
        'clave' => 'zzperm_reservada',
        'nombre' => PREFIJO.'Categoría de la escuela',
        'sensible' => true,
        'permiso_detalle' => 'ver-alertas-financieras',
        'orden' => 900,
    ]);

    $sinPermiso = usuarioConRol('docente');

    /*
     * Una categoria inventada por la escuela se comporta EXACTAMENTE como las de
     * fabrica: quien tiene su permiso la alcanza y quien no, no.
     *
     * Esta comprobacion decia antes «el permiso todavia no existe, asi que nadie
     * la alcanza» —cierto en la fase 1— y se cayo EN ROJO en cuanto la fase 3
     * declaro `ver-alertas-financieras`. Eso es lo correcto: una afirmacion
     * atada a un estado temporal tiene que fallar ruidosamente cuando el estado
     * cambia, no apagarse sola.
     */
    verificar('Una categoría inventada y reservada la alcanza quien tiene su permiso',
        $inventada->alcanzaElDetalle($global) === true,
        'dirección general tiene ver-alertas-financieras');

    verificar('Y NO quien no lo tiene',
        $inventada->alcanzaElDetalle($sinPermiso) === false);

    $noSensible = CategoriaSenal::create([
        'clave' => 'zzperm_abierta',
        'nombre' => PREFIJO.'Abierta',
        'sensible' => false,
        'orden' => 901,
    ]);

    verificar('Una NO reservada la alcanza cualquiera que llegue a la alerta',
        $noSensible->alcanzaElDetalle($sinPermiso) === true);

    verificar('Y una reservada sin permiso declarado NO la alcanza nadie: falla cerrado',
        (new CategoriaSenal(['sensible' => true, 'permiso_detalle' => null]))->alcanzaElDetalle($global) === false);

    echo PHP_EOL.'5. La sensibilidad NO se edita desde el catálogo'.PHP_EOL;

    /*
     * Y no basta con que la pantalla no la ofrezca: `$fillable` la dejaría
     * pasar, así que una petición a mano podría apagarle la sensibilidad a la
     * categoría financiera y abrir los adeudos de todos los alumnos a
     * cualquiera con la bandeja.
     */
    $peticion = peticionCon([
        'clave' => $inventada->clave,
        'nombre' => $inventada->nombre,
        'sensible' => false,
    ], $global, 'PUT');

    $catalogos->update($peticion, 'categoria', $inventada->id);

    verificar('Mandar `sensible => false` a mano NO la apaga',
        $inventada->fresh()->sensible === true);

    echo PHP_EOL.'6. Las métricas están declaradas y son coherentes'.PHP_EOL;

    verificar('Hay métricas declaradas', count(CatalogoMetricas::claves()) >= 10,
        (string) count(CatalogoMetricas::claves()));

    $malDeclaradas = [];

    foreach (CatalogoMetricas::todas() as $clave => $m) {
        foreach (['proveedor', 'etiqueta', 'descripcion', 'unidad', 'direccion', 'cobertura'] as $campo) {
            empty($m[$campo]) && $malDeclaradas[] = $clave.' sin '.$campo;
        }

        in_array($m['direccion'], [CatalogoMetricas::SUBE, CatalogoMetricas::BAJA], true)
            || $malDeclaradas[] = $clave.' con dirección inválida';
    }

    verificar('Todas declaran proveedor, etiqueta, descripción, unidad, dirección y cobertura',
        $malDeclaradas === [], implode(', ', array_slice($malDeclaradas, 0, 3)));

    verificar('El comparador sugerido sigue a la dirección',
        CatalogoMetricas::comparadorSugerido('asistencia.porcentaje') === '<'
        && CatalogoMetricas::comparadorSugerido('asistencia.faltas_consecutivas') === '>=');

    verificar('Y sabe decir cuándo el comparador mira al lado contrario',
        CatalogoMetricas::apuntaAlProblema('asistencia.porcentaje', '<') === true
        && CatalogoMetricas::apuntaAlProblema('asistencia.porcentaje', '>=') === false);

    echo PHP_EOL.'7. Las ocho reglas de ejemplo llegan APAGADAS'.PHP_EOL;

    /*
     * El seeder se CORRE, no se le cree a lo que ya esta en la base.
     *
     * Leyendo lo sembrado, cambiar el seeder para que las reglas nazcan
     * encendidas no tumbaba nada: esta escuela ya las tenia apagadas y el
     * defecto aparecia en la SIGUIENTE que se migrara, con las alertas ya
     * saliendo. Lo destapo el barrido de mutaciones.
     */
    /*
     * Antes de tirar las reglas hay que tirar lo que cuelga de ellas: una
     * ALERTA apunta a la versión con la que se levantó, así que borrar las
     * versiones revienta con 1451 en cuanto la escuela ha evaluado alguna vez.
     * Sólo se nota donde HAY alertas —o sea, no en un demo recién migrado y sí
     * en la del cliente—. El orden lo pone el apoyo compartido.
     */
    limpiarPermanencia(conReglas: false);

    ReglaAlertaVersion::query()->whereIn(
        'regla_id',
        ReglaAlerta::query()->whereNotLike('nombre', PREFIJO.'%')->pluck('id'),
    )->forceDelete();
    ReglaAlerta::query()->whereNotLike('nombre', PREFIJO.'%')->forceDelete();

    (new Database\Seeders\Tenant\ReglasAlertaEjemploSeeder)->run();

    $ejemplo = ReglaAlerta::query()->whereNotLike('nombre', PREFIJO.'%')->get();

    verificar('Están sembradas', $ejemplo->count() >= 8, (string) $ejemplo->count());

    verificar('Y NINGUNA está encendida',
        $ejemplo->every(fn (ReglaAlerta $r) => ! $r->activa),
        $ejemplo->where('activa', true)->pluck('nombre')->implode(', ') ?: 'ninguna');

    verificar('Ninguna avisa a nadie todavía',
        ReglaAlertaVersion::query()
            ->where(fn ($q) => $q->where('avisa_al_alumno', true)->orWhere('avisa_a_la_escuela', true))
            ->count() === 0);

    $sembrados = $ejemplo->flatMap(fn (ReglaAlerta $r) => [$r->nombre, $r->descripcion])
        ->merge(ReglaAlertaVersion::query()->pluck('notas'))
        ->filter(fn ($t) => is_string($t) && $t !== '')
        ->map(fn (string $t) => mb_strtolower($t));

    $sucias = [];

    foreach ($sembrados as $texto) {
        foreach ($negras as $palabra) {
            str_contains($texto, $palabra) && $sucias[] = $palabra.' → '.mb_substr($texto, 0, 40);
        }
    }

    verificar('Y ninguna usa una etiqueta punitiva en su nombre ni en su descripción',
        $sucias === [], implode(' | ', array_slice($sucias, 0, 3)));

    verificar('Todas usan una métrica que el sistema sabe calcular',
        ReglaAlertaVersion::query()->get()
            ->every(fn (ReglaAlertaVersion $v) => CatalogoMetricas::existe($v->metrica)),
        ReglaAlertaVersion::query()->get()
            ->reject(fn ($v) => CatalogoMetricas::existe($v->metrica))->pluck('metrica')->implode(', ') ?: 'todas');

    echo PHP_EOL.'8. El ALCANCE: lo que se deja en null no acota'.PHP_EOL;

    $matricula = App\Models\Admisiones\MatriculaOferta::query()
        ->whereHas('oferta')->with('oferta')->firstOrFail();

    $general = ReglaAlerta::create([
        'nombre' => PREFIJO.'General',
        'categoria_id' => $noSensible->id,
        'proveedor' => 'asistencia',
    ]);

    verificar('Una regla sin ningún eje alcanza a cualquiera',
        $general->alcanzaA($matricula));

    $otroCampus = App\Models\Academico\Campus::query()
        ->whereKeyNot($matricula->oferta->campus_id)->firstOrFail();

    $acotada = ReglaAlerta::create([
        'nombre' => PREFIJO.'De otro campus',
        'categoria_id' => $noSensible->id,
        'proveedor' => 'asistencia',
        'campus_id' => $otroCampus->id,
    ]);

    verificar('Una acotada a otro campus NO alcanza', ! $acotada->alcanzaA($matricula));

    $suCampus = ReglaAlerta::create([
        'nombre' => PREFIJO.'De su campus',
        'categoria_id' => $noSensible->id,
        'proveedor' => 'asistencia',
        'campus_id' => $matricula->oferta->campus_id,
    ]);

    verificar('Y una acotada al suyo, sí', $suCampus->alcanzaA($matricula));

    /*
     * Sin generación capturada, una regla que la acote NO alcanza. Darla por
     * buena dejaría entrar a quien no sabemos de qué generación es, que es
     * justo lo que el rango existe para separar.
     */
    $porGeneracion = ReglaAlerta::create([
        'nombre' => PREFIJO.'Por generación',
        'categoria_id' => $noSensible->id,
        'proveedor' => 'asistencia',
        'generacion_desde' => 2020,
        'generacion_hasta' => 2024,
    ]);

    verificar('Dentro del rango alcanza', $porGeneracion->cubreLaGeneracion('2022'));
    verificar('Fuera del rango no', ! $porGeneracion->cubreLaGeneracion('2019'));
    verificar('Con sufijo se sitúa igual', $porGeneracion->cubreLaGeneracion('2021-B'));
    verificar('Y SIN generación capturada NO alcanza: no se da por buena',
        ! $porGeneracion->cubreLaGeneracion(null) && ! $porGeneracion->cubreLaGeneracion(''));

    /*
     * Y el caso que separa este alcance del RESOLUTOR de servicio social: aquí
     * TODAS las que alcanzan evalúan. Con un resolutor ganaría la más
     * específica y las demás señales desaparecerían sin un solo error.
     */
    $queAlcanzan = ReglaAlerta::query()
        ->where('nombre', 'like', PREFIJO.'%')
        ->get()
        ->filter(fn (ReglaAlerta $r) => $r->alcanzaA($matricula));

    verificar('Varias reglas alcanzan a la MISMA matrícula a la vez',
        $queAlcanzan->count() >= 2, (string) $queAlcanzan->count());

    echo PHP_EOL.'9. Las guardas contra una regla que no mediría nada'.PHP_EOL;

    $base = [
        'nombre' => PREFIJO.'Guardas',
        'categoria_id' => $noSensible->id,
        'metrica' => 'asistencia.porcentaje',
        'vigente_desde' => now()->toDateString(),
        'comparador' => '<',
        'umbral' => 80,
        'umbral_fuente' => 'fijo',
        'ventana_tipo' => 'ciclo',
        'cobertura_minima' => 6,
        'severidad' => 'alto',
        'peso' => 3,
        'frecuencia' => 'diaria',
        'cooldown_dias' => 14,
    ];

    $rechaza = function (array $cambios, string $espera) use ($controlador, $global, $base): array {
        $datos = array_merge($base, $cambios);

        try {
            $controlador->store(peticionCon($datos, $global));

            return [false, 'la aceptó'];
        } catch (App\Exceptions\AvisoParaElUsuario $e) {
            return [str_contains(mb_strtolower($e->getMessage()), $espera), $e->getMessage()];
        } catch (ValidationException $e) {
            return [str_contains(mb_strtolower(json_encode($e->errors(), JSON_UNESCAPED_UNICODE)), $espera),
                json_encode($e->errors(), JSON_UNESCAPED_UNICODE)];
        }
    };

    [$ok, $msg] = $rechaza(['umbral' => null], 'umbral');
    verificar('Sin umbral y con fuente fija se rehúsa', $ok, mb_substr($msg, 0, 70));

    [$ok, $msg] = $rechaza(['ventana_tipo' => 'ultimos_dias', 'ventana_valor' => null], 'días');
    verificar('Una ventana de N días sin el número se rehúsa', $ok, mb_substr($msg, 0, 70));

    [$ok, $msg] = $rechaza(['umbral_fuente' => 'plan'], 'plan');
    verificar('El umbral del plan sobre una métrica NO académica se rehúsa', $ok, mb_substr($msg, 0, 70));

    [$ok, $msg] = $rechaza(['metrica' => 'no.existe.esta'], 'métrica');
    verificar('Una métrica inventada se rehúsa', $ok, mb_substr($msg, 0, 70));

    [$ok, $msg] = $rechaza(['generacion_desde' => 2024, 'generacion_hasta' => 2020], 'generación');
    verificar('Un rango de generaciones al revés se rehúsa', $ok, mb_substr($msg, 0, 70));

    echo PHP_EOL.'10. Una regla nace APAGADA aunque se pida encendida'.PHP_EOL;

    $controlador->store(peticionCon(
        array_merge($base, ['nombre' => PREFIJO.'Nace apagada', 'activa' => true]), $global));

    $nacida = ReglaAlerta::query()->where('nombre', PREFIJO.'Nace apagada')->firstOrFail();

    verificar('Nace apagada', ! $nacida->activa);
    verificar('Y con su versión 1, para que pueda medir algo',
        $nacida->versiones()->count() === 1);

    verificar('El proveedor se DERIVA de la métrica, no se captura',
        $nacida->proveedor === CatalogoMetricas::de('asistencia.porcentaje')['proveedor'],
        $nacida->proveedor);

    /*
     * Y con una metrica de OTRO proveedor, que es lo que separa «derivado» de
     * «cableado a asistencia». Sin este caso, una mutacion que escribiera
     * 'asistencia' a secas sobrevivia: el escenario no tenia otra familia.
     */
    $controlador->store(peticionCon(array_merge($base, [
        'nombre' => PREFIJO.'Academica',
        'metrica' => 'academico.reprobadas_ciclo',
        'comparador' => '>=',
        'umbral' => 2,
    ]), $global));

    $academica = ReglaAlerta::query()->where('nombre', PREFIJO.'Academica')->firstOrFail();

    verificar('Una regla con métrica académica queda con el proveedor académico',
        $academica->proveedor === 'academico', $academica->proveedor);

    echo PHP_EOL.'11. Encender exige una versión vigente'.PHP_EOL;

    $sinVersion = ReglaAlerta::create([
        'nombre' => PREFIJO.'Sin versión',
        'categoria_id' => $noSensible->id,
        'proveedor' => 'asistencia',
    ]);

    $motivo = null;

    try {
        $controlador->alternar(peticionCon(['activa' => true], $global), $sinVersion);
    } catch (App\Exceptions\AvisoParaElUsuario $e) {
        $motivo = $e->getMessage();
    }

    verificar('Una regla sin versión vigente no se puede encender',
        $motivo !== null && str_contains($motivo, 'versión'), (string) $motivo);

    verificar('Y sigue apagada', ! $sinVersion->fresh()->activa);

    echo PHP_EOL.'12. Las VERSIONES congelan'.PHP_EOL;

    $controlador->versionar(
        peticionCon(array_merge($base, [
            'vigente_desde' => now()->addDay()->toDateString(),
            'umbral' => 70,
        ]), $global),
        $nacida,
    );

    $nacida = $nacida->fresh(['versiones']);

    verificar('Se emitió la versión 2', $nacida->versiones()->count() === 2);

    verificar('La versión 1 conserva su umbral: no se reescribió',
        (float) $nacida->versiones->firstWhere('version', 1)->umbral === 80.0,
        (string) $nacida->versiones->firstWhere('version', 1)->umbral);

    verificar('Y se cerró el día ANTES de que empiece la nueva: dos no rigen a la vez',
        $nacida->versiones->firstWhere('version', 1)->vigente_hasta?->toDateString()
            === now()->toDateString());

    verificar('Hoy rige la 1', $nacida->versionVigente()?->version === 1);

    verificar('Y mañana, la 2',
        $nacida->versionVigente(now()->addDay()->toDateString())?->version === 2);

    echo PHP_EOL.'13. La condición se LEE, y con los mismos campos que deciden'.PHP_EOL;

    $v = $nacida->versiones->firstWhere('version', 1);

    verificar('`comoSeLee` nombra la métrica, el comparador y el umbral',
        str_contains($v->comoSeLee(), 'asistencia.porcentaje')
        && str_contains($v->comoSeLee(), '<')
        && str_contains($v->comoSeLee(), '80'),
        $v->comoSeLee());

    verificar('Y `cruza` decide con esos mismos', $v->cruza(75.0) && ! $v->cruza(85.0));

    verificar('Un valor ausente NO cruza: sin dato no se molesta a nadie', ! $v->cruza(null));

    verificar('Y un umbral ausente tampoco',
        ! (new ReglaAlertaVersion(['comparador' => '<', 'umbral' => null]))->cruza(10.0));

    verificar('El umbral del plan se dice con palabras, no con un número inventado',
        str_contains(
            (new ReglaAlertaVersion([
                'metrica' => 'academico.promedio', 'comparador' => '<',
                'umbral_fuente' => 'plan', 'ventana_tipo' => 'ciclo',
            ]))->comoSeLee(),
            'del plan',
        ));

    echo PHP_EOL.'14. Las EXCLUSIONES'.PHP_EOL;

    $exclusion = ExclusionReglaAlerta::create([
        'matricula_oferta_id' => $matricula->id,
        'motivo' => 'Licencia médica autorizada por la dirección.',
        'vigente_hasta' => now()->addMonth()->toDateString(),
    ]);

    verificar('Una exclusión sin regla vale para TODAS', $exclusion->regla_id === null);

    verificar('Y hoy está vigente',
        ExclusionReglaAlerta::query()->vigentes()->whereKey($exclusion->id)->exists());

    verificar('El día mismo de su fin TODAVÍA excluye',
        ExclusionReglaAlerta::query()
            ->vigentes(now()->addMonth()->toDateString())
            ->whereKey($exclusion->id)->exists());

    verificar('Y al día siguiente ya no',
        ! ExclusionReglaAlerta::query()
            ->vigentes(now()->addMonth()->addDay()->toDateString())
            ->whereKey($exclusion->id)->exists());

    verificar('Sin fecha no caduca',
        ExclusionReglaAlerta::query()
            ->vigentes(now()->addYears(5)->toDateString())
            ->whereKey(ExclusionReglaAlerta::create([
                'matricula_oferta_id' => $matricula->id,
                'motivo' => 'Permanente.',
            ])->id)->exists());

    echo PHP_EOL.'15. La pantalla dice cuántas están encendidas'.PHP_EOL;

    $props = props($controlador, 'index', $global);

    verificar('Devuelve las reglas y su conteo de encendidas',
        isset($props['reglas'], $props['encendidas']),
        implode(', ', array_keys($props)));

    verificar('Y el conteo cuadra con las que de verdad lo están',
        $props['encendidas'] === ReglaAlerta::query()->where('activa', true)->count(),
        $props['encendidas'].' contra '.ReglaAlerta::query()->where('activa', true)->count());

    verificar('Cada regla dice si le falta versión vigente',
        collect($props['reglas'])->every(fn ($r) => array_key_exists('sin_version_vigente', $r)));

    verificar('Y la que no tiene versión lo dice',
        collect($props['reglas'])->firstWhere('id', $sinVersion->id)['sin_version_vigente'] === true);

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
