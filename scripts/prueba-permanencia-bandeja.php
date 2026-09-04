<?php

/**
 * La bandeja de alertas y el triage (fase 3). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-bandeja.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Las cuatro capas de visibilidad, y las CUATRO se comprueban**: módulo,
 *     permiso, campus y categoría. El id viaja por la URL, así que filtrar la
 *     lista no es una defensa — lección que este proyecto ya pagó tres veces.
 *  2. **Lo ajeno responde 404 y no 403**: un 403 confirma que esa alerta existe,
 *     y con ids consecutivos eso deja enumerar quién tiene señales en los demás
 *     planteles.
 *  3. **Descartar EXIGE motivo**, y el motivo sale del catálogo: es lo que
 *     permite medir la tasa de falsos positivos por regla.
 *  4. **La carrera de dos revisores**: el segundo no puede borrar del acta al
 *     que decidió primero.
 *  5. **El resumen está acotado**: un total sin recortar filtraría la cifra de
 *     la escuela entera encima de una lista de un plantel.
 *  6. **El lenguaje**: ninguna cadena de la pantalla usa una etiqueta punitiva.
 */

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

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

/**
 * Un usuario con un rol PROPIO, para poder quitarle permisos sin tocar los del
 * demo. Con el rol compartido, quitarle uno se lo quitaría a todo el mundo.
 */
function usuarioCon(array $permisos, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Bandeja',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $faceta = Rol::where('name', 'administrativo')->firstOrFail();

    $rol = Rol::create([
        'name' => 'zzband_'.random_int(100000, 999999),
        'nombre' => 'Prueba de bandeja',
        'guard_name' => 'web',
        'rol_padre_id' => $faceta->id,
    ]);

    $rol->syncPermissions($permisos);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_band_'.random_int(100000, 999999),
        'email' => 'prueba_band_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $rol->id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $cuenta->fresh(['persona.asignacionesRol', 'rolActivo']);
}

/**
 * Qué codigo HTTP produciria esto de verdad.
 *
 * Invocando al controlador directamente, `findOrFail` lanza
 * `ModelNotFoundException` y NO llega a convertirse en 404: eso lo hace el
 * manejador de excepciones en la capa HTTP. Preguntarselo a el es lo unico que
 * comprueba lo que un usuario veria — y es lo que separa un 404 de un 403, que
 * es la distincion que aqui importa: un 403 confirmaria que esa alerta existe.
 */
function codigoDe(Throwable $e): int
{
    return app(Illuminate\Contracts\Debug\ExceptionHandler::class)
        ->render(Illuminate\Http\Request::create('/'), $e)
        ->getStatusCode();
}

function peticionCon(array $datos, Usuario $como, string $metodo = 'POST'): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $como);

    return $p;
}

/** Las props que el controlador manda a Inertia. */
function props(object $controlador, string $metodo, Usuario $como, array $query = [], array $extra = []): array
{
    $peticion = Illuminate\Http\Request::create('/', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    return $controlador->{$metodo}($peticion, ...$extra)
        ->toResponse($peticion)->getData(true)['props'] ?? [];
}

const PREFIJO = 'ZZBAN-';

$db->beginTransaction();

try {
    $controlador = app(App\Http\Controllers\Permanencia\AlertaController::class);

    echo '1. El escenario: dos alertas en dos campus distintos'.PHP_EOL;

    // Se parte de cero: lo que se prueba es aritmética de bandeja.
    /*
     * El ORDEN importa: desde la fase 5 hay casos colgando de las señales, y
     * `caso_alerta` tiene foránea contra `alertas`. Borrar las señales primero
     * revienta con 1451. Es la misma lección que dejó `riesgo_matricula` en la
     * suite del motor: una tabla nueva rompe la limpieza de las suites viejas, y
     * lo hace sólo cuando la escuela TIENE datos de esa tabla —o sea, no aquí y
     * sí en la del cliente—.
     */
    DB::table('accesos_caso')->delete();
    DB::table('transiciones_caso')->delete();
    DB::table('tareas_caso')->delete();
    DB::table('intervenciones')->delete();
    DB::table('caso_equipo')->delete();
    DB::table('caso_alerta')->delete();
    // La foránea de `caso_origen_id` apunta a la MISMA tabla, así que un
    // `DELETE` pelado revienta contra sí mismo: se suelta primero.
    DB::table('casos_permanencia')->update(['caso_origen_id' => null]);
    DB::table('casos_permanencia')->delete();
    Alerta::query()->forceDelete();
    ReglaAlertaVersion::query()->forceDelete();
    ReglaAlerta::query()->forceDelete();

    $academica = CategoriaSenal::query()->where('clave', 'academica')->firstOrFail();
    $financiera = CategoriaSenal::query()->where('clave', 'financiera')->firstOrFail();

    verificar('La categoría financiera está marcada como reservada',
        $financiera->sensible === true && $financiera->permiso_detalle === 'ver-alertas-financieras');

    $regla = ReglaAlerta::create([
        'nombre' => PREFIJO.'Regla',
        'categoria_id' => $academica->id,
        'proveedor' => 'academico',
        'activa' => true,
    ]);

    $version = $regla->versiones()->create([
        'version' => 1,
        'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
        'metrica' => 'academico.promedio',
        'comparador' => '<',
        'umbral' => 7,
        'ventana_tipo' => 'desde_inicio',
        'cobertura_minima' => 1,
        'severidad' => 'alto',
        'peso' => 3,
        'frecuencia' => 'diaria',
        'cooldown_dias' => 14,
    ]);

    /*
     * DOS matrículas de campus DISTINTOS. Sin las dos, el alcance por campus no
     * se ejercita: con una sola, filtrar y no filtrar dan lo mismo.
     */
    $porCampus = MatriculaOferta::query()
        ->whereHas('oferta')
        ->with('oferta')
        ->get()
        ->groupBy(fn (MatriculaOferta $m) => $m->oferta->campus_id);

    verificar('Hay matrículas en al menos DOS campus', $porCampus->count() >= 2,
        (string) $porCampus->count());

    $campusA = $porCampus->keys()->first();
    $campusB = $porCampus->keys()->skip(1)->first();

    $deA = $porCampus[$campusA]->first();
    $deB = $porCampus[$campusB]->first();

    $crearAlerta = function (MatriculaOferta $matricula, CategoriaSenal $categoria) use ($regla, $version) {
        return Alerta::create([
            'matricula_oferta_id' => $matricula->id,
            'regla_id' => $regla->id,
            'regla_version_id' => $version->id,
            'categoria_id' => $categoria->id,
            'severidad' => 'alto',
            'estado_senal' => Alerta::ACTIVA,
            'estado_triage' => Alerta::NUEVA,
            'valor_observado' => 5.5,
            'umbral' => 7,
            'cobertura' => 12,
            'evidencia' => ['promedio' => 5.5, 'materias_asentadas' => 12, 'fuente' => 'historial'],
            'primera_vez_en' => now(),
            'ultima_evaluacion_en' => now(),
        ]);
    };

    $alertaA = $crearAlerta($deA, $academica);
    $alertaB = $crearAlerta($deB, $academica);
    $reservadaA = $crearAlerta(
        $porCampus[$campusA]->skip(1)->first() ?? $deA,
        $financiera,
    );

    echo PHP_EOL.'2. El ALCANCE por campus, en las tres puertas'.PHP_EOL;

    $global = usuarioCon(['ver-alertas', 'ver-alertas-financieras', 'validar-alertas']);
    $soloA = usuarioCon(['ver-alertas', 'ver-alertas-financieras', 'validar-alertas'], $campusA);

    verificar('El acotado lo está de verdad',
        $soloA->campusVisibles() === [$campusA], json_encode($soloA->campusVisibles()));

    $delGlobal = props($controlador, 'index', $global);
    $delAcotado = props($controlador, 'index', $soloA);

    $ids = fn (array $p) => collect($p['alertas']['data'] ?? [])->pluck('id')->all();

    verificar('El global ve las de los dos campus',
        in_array($alertaA->id, $ids($delGlobal), true) && in_array($alertaB->id, $ids($delGlobal), true));

    verificar('El acotado ve la suya',
        in_array($alertaA->id, $ids($delAcotado), true));

    verificar('Y NO la del otro campus',
        ! in_array($alertaB->id, $ids($delAcotado), true), implode(', ', $ids($delAcotado)));

    // El DETALLE: el id viaja por la URL, así que filtrar la lista no basta.
    $codigo = null;

    try {
        props($controlador, 'show', $soloA, [], [$alertaB->id]);
    } catch (Throwable $e) {
        $codigo = codigoDe($e);
    }

    verificar('Abrir la de otro campus responde 404, NO 403',
        $codigo === 404, (string) $codigo);

    // La ACCIÓN: tampoco por ahí.
    $codigoAccion = null;

    try {
        $controlador->validar(peticionCon([], $soloA), $alertaB->id);
    } catch (Throwable $e) {
        $codigoAccion = codigoDe($e);
    }

    verificar('Validar la de otro campus también responde 404',
        $codigoAccion === 404, (string) $codigoAccion);

    verificar('Y no se movió', $alertaB->fresh()->estado_triage === Alerta::NUEVA);

    echo PHP_EOL.'3. El RESUMEN también está acotado'.PHP_EOL;

    /*
     * Un total sin recortar filtraría la cifra de la escuela entera encima de
     * una lista de un plantel — el defecto que el motor de reportes documentó
     * con los totales, y que aquí sería el número más visible de la pantalla.
     */
    verificar('El resumen del acotado es MENOR que el del global',
        $delAcotado['resumen']['por_revisar'] < $delGlobal['resumen']['por_revisar'],
        $delAcotado['resumen']['por_revisar'].' contra '.$delGlobal['resumen']['por_revisar']);

    verificar('Y el del global cuenta las tres',
        $delGlobal['resumen']['por_revisar'] === 3,
        (string) $delGlobal['resumen']['por_revisar']);

    echo PHP_EOL.'4. La CATEGORÍA reservada no filtra su detalle'.PHP_EOL;

    $sinFinanzas = usuarioCon(['ver-alertas', 'validar-alertas']);

    $suya = collect(props($controlador, 'index', $sinFinanzas)['alertas']['data'] ?? [])
        ->firstWhere('id', $reservadaA->id);

    verificar('Quien no alcanza la categoría SÍ ve que la señal existe',
        $suya !== null && ($suya['reservada'] ?? null) === true);

    verificar('Pero NO recibe el valor ni la evidencia',
        $suya !== null && ! array_key_exists('valor_observado', $suya)
        && ! array_key_exists('evidencia', $suya),
        implode(', ', array_keys($suya ?? [])));

    $conFinanzas = collect(props($controlador, 'index', $global)['alertas']['data'] ?? [])
        ->firstWhere('id', $reservadaA->id);

    verificar('Y quien sí la alcanza recibe el detalle',
        ($conFinanzas['valor_observado'] ?? null) !== null
        && ($conFinanzas['reservada'] ?? null) === false);

    // Y en la FICHA, que es otra puerta.
    $ficha = props($controlador, 'show', $sinFinanzas, [], [$reservadaA->id]);

    verificar('En la ficha tampoco viaja el detalle reservado',
        ! array_key_exists('valor_observado', $ficha['alerta'] ?? []),
        implode(', ', array_keys($ficha['alerta'] ?? [])));

    echo PHP_EOL.'5. Ver NO es validar'.PHP_EOL;

    $soloLectura = usuarioCon(['ver-alertas']);

    $motivo = null;

    try {
        $controlador->validar(peticionCon([], $soloLectura), $alertaA->id);
    } catch (App\Exceptions\AvisoParaElUsuario $e) {
        $motivo = $e->getStatusCode().': '.$e->getMessage();
    }

    verificar('Quien sólo ve no puede validar', str_starts_with((string) $motivo, '403'),
        (string) $motivo);

    verificar('Y la pantalla se lo dice',
        (props($controlador, 'index', $soloLectura)['puedeValidar'] ?? true) === false);

    verificar('La alerta no se movió', $alertaA->fresh()->estado_triage === Alerta::NUEVA);

    echo PHP_EOL.'6. Descartar EXIGE motivo, y del catálogo'.PHP_EOL;

    $errores = null;

    try {
        $controlador->descartar(peticionCon([], $global), $alertaA->id);
    } catch (ValidationException $e) {
        $errores = json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    verificar('Sin motivo se rehúsa', $errores !== null && str_contains($errores, 'motivo'),
        (string) $errores);

    $inventado = null;

    try {
        $controlador->descartar(
            peticionCon(['motivo_descarte_id' => 999999], $global),
            $alertaA->id,
        );
    } catch (ValidationException $e) {
        $inventado = json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    verificar('Y un motivo inventado también', $inventado !== null, (string) $inventado);

    verificar('La alerta sigue sin revisar', $alertaA->fresh()->estado_triage === Alerta::NUEVA);

    $falsoPositivo = MotivoDescarte::query()->where('cuenta_como_falso_positivo', true)->firstOrFail();

    $controlador->descartar(
        peticionCon(['motivo_descarte_id' => $falsoPositivo->id, 'nota' => 'La captura estaba mal.'], $global),
        $alertaA->id,
    );

    $descartada = $alertaA->fresh();

    verificar('Con motivo del catálogo sí se descarta',
        $descartada->estado_triage === Alerta::DESCARTADA);

    verificar('Y queda quién la revisó y cuándo',
        $descartada->revisada_por === $global->id && $descartada->revisada_en !== null);

    verificar('Con su motivo y su nota',
        $descartada->motivo_descarte_id === $falsoPositivo->id
        && $descartada->nota_triage === 'La captura estaba mal.');

    verificar('La SEÑAL sigue activa: descartarla no la hace falsa',
        $descartada->estado_senal === Alerta::ACTIVA,
        (string) $descartada->estado_senal);

    echo PHP_EOL.'7. La CARRERA de dos revisores'.PHP_EOL;

    /*
     * Dos personas con la pantalla abierta pulsan las dos. Sin la guarda, la
     * segunda borraría del acta a quien decidió primero — el mismo criterio que
     * la firma de las becas.
     */
    $segundo = null;

    try {
        $controlador->validar(peticionCon([], $global), $alertaA->id);
    } catch (App\Exceptions\AvisoParaElUsuario $e) {
        $segundo = $e->getStatusCode().': '.$e->getMessage();
    }

    verificar('El segundo revisor se rehúsa con 422 y su razón',
        str_starts_with((string) $segundo, '422') && str_contains((string) $segundo, 'descartó'),
        (string) $segundo);

    verificar('Y NO se pisa a quien decidió primero',
        $alertaA->fresh()->estado_triage === Alerta::DESCARTADA
        && $alertaA->fresh()->motivo_descarte_id === $falsoPositivo->id);

    echo PHP_EOL.'8. Validar deja la señal lista para seguimiento'.PHP_EOL;

    $controlador->validar(peticionCon(['nota' => 'Se le va a contactar.'], $global), $reservadaA->id);

    $validada = $reservadaA->fresh();

    verificar('Queda validada', $validada->estado_triage === Alerta::VALIDADA);
    verificar('Con su nota', $validada->nota_triage === 'Se le va a contactar.');
    verificar('Y sin motivo de descarte', $validada->motivo_descarte_id === null);

    echo PHP_EOL.'9. La acción MASIVA respeta el campus'.PHP_EOL;

    // Se rehacen dos por revisar, una en cada campus.
    Alerta::query()->forceDelete();
    $enA = $crearAlerta($deA, $academica);
    $enB = $crearAlerta($deB, $academica);

    $controlador->descartarVarias(peticionCon([
        'alertas' => [$enA->id, $enB->id],
        'motivo_descarte_id' => $falsoPositivo->id,
    ], $soloA));

    verificar('El acotado descarta la de SU campus',
        $enA->fresh()->estado_triage === Alerta::DESCARTADA);

    verificar('Y NO la del otro, aunque su id viajara en la petición',
        $enB->fresh()->estado_triage === Alerta::NUEVA,
        (string) $enB->fresh()->estado_triage);

    /*
     * Y quien SÓLO VE no puede descartar en masa. Es otra puerta: sin
     * comprobarlo, la accion masiva seria la forma de saltarse el permiso que la
     * individual si exige.
     */
    $rechazoMasivo = null;

    try {
        $controlador->descartarVarias(peticionCon([
            'alertas' => [$enB->id],
            'motivo_descarte_id' => $falsoPositivo->id,
        ], $soloLectura));
    } catch (App\Exceptions\AvisoParaElUsuario $e) {
        $rechazoMasivo = $e->getStatusCode();
    }

    verificar('Quien sólo ve tampoco puede descartar en masa',
        $rechazoMasivo === 403, (string) $rechazoMasivo);

    verificar('Y la del otro campus sigue sin revisar',
        $enB->fresh()->estado_triage === Alerta::NUEVA);

    // Y el aviso lo DICE: en silencio, quien pulsa creería que descartó las dos.
    $sesion = session()->get('exito');

    verificar('Y el aviso dice cuántas quedaron fuera',
        is_string($sesion) && str_contains($sesion, 'no se pudieron'),
        (string) $sesion);

    echo PHP_EOL.'10. Los FILTROS'.PHP_EOL;

    Alerta::query()->forceDelete();
    $unaA = $crearAlerta($deA, $academica);
    $unaFin = $crearAlerta($porCampus[$campusA]->skip(1)->first() ?? $deA, $financiera);

    $porCategoria = props($controlador, 'index', $global, ['categoria_id' => $financiera->id]);

    verificar('Filtrar por categoría deja sólo las suyas',
        $ids($porCategoria) === [$unaFin->id], implode(', ', $ids($porCategoria)));

    $porCampusFiltro = props($controlador, 'index', $global, ['campus_id' => $campusB]);

    verificar('Filtrar por campus deja fuera las de otro',
        ! in_array($unaA->id, $ids($porCampusFiltro), true));

    $porTexto = props($controlador, 'index', $global, ['busqueda' => (string) $deA->matricula]);

    verificar('Buscar por matrícula la encuentra',
        in_array($unaA->id, $ids($porTexto), true), implode(', ', $ids($porTexto)));

    /*
     * Y por omisión sólo lo ABIERTO y POR REVISAR: una cola de trabajo abierta
     * con todo el histórico dentro hace que lo de hoy se pierda.
     */
    $unaA->update(['estado_triage' => Alerta::DESCARTADA]);

    verificar('Por omisión no salen las ya revisadas',
        ! in_array($unaA->id, $ids(props($controlador, 'index', $global)), true));

    verificar('Pero con el filtro puesto, sí',
        in_array($unaA->id,
            $ids(props($controlador, 'index', $global, ['estado_triage' => 'descartada'])), true));

    echo PHP_EOL.'11. La bandeja dice CUÁNDO corrió el motor'.PHP_EOL;

    /*
     * Sin ese dato, una cola vacía se lee como ausencia de riesgo. Es la peor
     * lectura que este módulo puede inducir, así que el dato viaja siempre.
     */
    DB::table('corridas_evaluacion')->delete();

    $sinCorrida = props($controlador, 'index', $global);

    verificar('Sin ninguna corrida, la pantalla manda null y no omite la clave',
        array_key_exists('ultimaCorrida', $sinCorrida) && $sinCorrida['ultimaCorrida'] === null,
        json_encode($sinCorrida['ultimaCorrida'] ?? 'la clave no viene'));

    DB::table('corridas_evaluacion')->insert([
        'iniciada_en' => CarbonImmutable::now()->subDays(9),
        'terminada_en' => CarbonImmutable::now()->subDays(9),
        'disparo' => 'programada',
        'matriculas_evaluadas' => 32,
        'reglas_evaluadas' => 2,
        'sin_datos' => 40,
        'milisegundos' => 300,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $corrida = props($controlador, 'index', $global)['ultimaCorrida'];

    verificar('Con una corrida vieja, dice cuántos días lleva',
        ($corrida['hace_dias'] ?? 0) === 9, json_encode($corrida['hace_dias'] ?? null));

    verificar('Y cuántas mediciones se quedaron sin datos',
        ($corrida['sin_datos'] ?? null) === 40);

    echo PHP_EOL.'12. LENGUAJE: ninguna etiqueta punitiva en la pantalla'.PHP_EOL;

    /*
     * El pedido lo dice con esas palabras. Una regla que sólo vive en la prosa
     * se rompe en el tercer commit, así que se barren los archivos del módulo.
     */
    /*
     * En SINGULAR y en PLURAL, y con las dos palabras que este proyecto usa para
     * nombrar a un estudiante. Con «alumno en riesgo» a secas, una mutación que
     * escribiera «alumnos en riesgo» sobrevivía: la lista negra tiene que cubrir
     * la frase, no una de sus formas.
     *
     * Ojo: «riesgo académico» SÍ se permite —el pedido lo nombra— porque
     * describe la SITUACIÓN. Lo que se prohíbe es describir a la persona.
     */
    $negras = ['moroso', 'desertor', 'problemátic', 'problematic', 'conflictiv',
        'fracasad', 'peligros', 'vago',
        'alumno en riesgo', 'alumnos en riesgo',
        'estudiante en riesgo', 'estudiantes en riesgo'];

    $archivos = array_merge(
        glob(__DIR__.'/../resources/js/Pages/Permanencia/*.vue') ?: [],
        glob(__DIR__.'/../resources/js/Pages/Permanencia/*/*.vue') ?: [],
        glob(__DIR__.'/../app/Http/Controllers/Permanencia/*.php') ?: [],
        glob(__DIR__.'/../app/Permanencia/*.php') ?: [],
        glob(__DIR__.'/../app/Permanencia/Proveedores/*.php') ?: [],
        glob(__DIR__.'/../app/Services/Permanencia/*.php') ?: [],
    );

    $sucios = [];

    foreach ($archivos as $archivo) {
        $texto = mb_strtolower((string) file_get_contents($archivo));

        foreach ($negras as $palabra) {
            str_contains($texto, $palabra) && $sucios[] = basename($archivo).': '.$palabra;
        }
    }

    verificar('Ninguna etiqueta punitiva en el módulo',
        $sucios === [], implode(' | ', array_slice($sucios, 0, 3)));

    verificar('Y hay archivos que barrer: el barrido no pasó por vacío',
        count($archivos) >= 10, (string) count($archivos));

    echo PHP_EOL.'13. La ficha EXPLICA'.PHP_EOL;

    $detalle = props($controlador, 'show', $global, [], [$unaFin->id]);

    verificar('Trae la evidencia congelada',
        isset($detalle['alerta']['evidencia']['promedio']),
        implode(', ', array_keys($detalle['alerta']['evidencia'] ?? [])));

    verificar('La condición en palabras',
        str_contains((string) ($detalle['alerta']['condicion'] ?? ''), 'academico.promedio'),
        (string) ($detalle['alerta']['condicion'] ?? ''));

    verificar('Y la CALIDAD de la fuente, que es cómo hay que leer el número',
        str_contains(mb_strtolower((string) ($detalle['alerta']['calidad'] ?? '')), 'asentado'),
        mb_substr((string) ($detalle['alerta']['calidad'] ?? ''), 0, 60));

    verificar('Los motivos de descarte para poder decidir',
        count($detalle['motivos'] ?? []) > 0, (string) count($detalle['motivos'] ?? []));

    /*
     * Y las OTRAS señales del mismo alumno, acotadas por la misma consulta base:
     * sin eso, la ficha sería una puerta lateral a las señales de otro campus.
     */
    /*
     * De OTRA regla: el único de la base impide dos alertas abiertas de la misma
     * regla y la misma matrícula —que es justo lo que ese único existe para
     * impedir—, así que el caso de «varias señales de la misma persona» necesita
     * dos reglas distintas.
     */
    $otraRegla = ReglaAlerta::create([
        'nombre' => PREFIJO.'Otra regla',
        'categoria_id' => $academica->id,
        'proveedor' => 'asistencia',
        'activa' => true,
    ]);

    $otraVersion = $otraRegla->versiones()->create([
        'version' => 1,
        'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
        'metrica' => 'asistencia.porcentaje',
        'comparador' => '<', 'umbral' => 80,
        'ventana_tipo' => 'ciclo', 'cobertura_minima' => 1,
        'severidad' => 'medio', 'peso' => 2, 'frecuencia' => 'diaria', 'cooldown_dias' => 14,
    ]);

    $otraDelMismo = Alerta::create([
        'matricula_oferta_id' => $unaFin->matricula_oferta_id,
        'regla_id' => $otraRegla->id,
        'regla_version_id' => $otraVersion->id,
        'categoria_id' => $academica->id,
        'severidad' => 'medio',
        'estado_senal' => Alerta::ACTIVA,
        'estado_triage' => Alerta::NUEVA,
        'valor_observado' => 62,
        'umbral' => 80,
        'cobertura' => 10,
        'evidencia' => ['porcentaje' => 62, 'sesiones_registradas' => 10, 'fuente' => 'asistencia_clase'],
        'primera_vez_en' => now(),
        'ultima_evaluacion_en' => now(),
    ]);

    $conOtras = props($controlador, 'show', $global, [], [$unaFin->id]);

    verificar('Enseña las otras señales abiertas de esa persona',
        collect($conOtras['otras'] ?? [])->pluck('id')->contains($otraDelMismo->id),
        json_encode(collect($conOtras['otras'] ?? [])->pluck('id')->all()));

    /*
     * Y el caso que hace MEDIBLE el recorte: la misma persona con una segunda
     * matrícula en OTRO campus. Sin él, todas sus señales caían en el mismo
     * plantel y filtrar o no filtrar daba lo mismo — la mutación sobrevivía.
     */
    $ofertaDeB = MatriculaOferta::query()->whereKey($deB->id)->value('oferta_id');

    $segundaMatricula = MatriculaOferta::create([
        'persona_id' => $unaFin->matricula->persona_id,
        'oferta_id' => $ofertaDeB,
        'matricula' => PREFIJO.random_int(10000, 99999),
        'situacion_id' => $deB->situacion_id,
        'generacion' => $deB->generacion,
        'fecha_ingreso' => $deB->fecha_ingreso,
        'estatus' => $deB->estatus,
    ]);

    $enElOtroCampus = Alerta::create([
        'matricula_oferta_id' => $segundaMatricula->id,
        'regla_id' => $otraRegla->id,
        'regla_version_id' => $otraVersion->id,
        'categoria_id' => $academica->id,
        'severidad' => 'bajo',
        'estado_senal' => Alerta::ACTIVA,
        'estado_triage' => Alerta::NUEVA,
        'valor_observado' => 55,
        'umbral' => 80,
        'cobertura' => 9,
        'evidencia' => ['porcentaje' => 55, 'fuente' => 'asistencia_clase'],
        'primera_vez_en' => now(),
        'ultima_evaluacion_en' => now(),
    ]);

    $paraElGlobal = props($controlador, 'show', $global, [], [$unaFin->id]);

    verificar('El global ve también la señal de su OTRO programa',
        collect($paraElGlobal['otras'] ?? [])->pluck('id')->contains($enElOtroCampus->id),
        json_encode(collect($paraElGlobal['otras'] ?? [])->pluck('id')->all()));

    $deOtroCampusDelMismo = props($controlador, 'show', $soloA, [], [$unaFin->id]);

    verificar('Y el acotado NO: esa lista también respeta el campus',
        ! collect($deOtroCampusDelMismo['otras'] ?? [])->pluck('id')->contains($enElOtroCampus->id),
        json_encode(collect($deOtroCampusDelMismo['otras'] ?? [])->pluck('id')->all()));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
