<?php

/**
 * La BITÁCORA de reportes: su pantalla, su permiso y su purga. Con rollback.
 *
 * Se corre con `php scripts/prueba-bitacora-reportes.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El permiso es PROPIO.** `auditar-reportes` no es `ver-reportes`: quien
 *     saca reportes todos los días no tiene por qué ver lo que sacan los demás.
 *     Se comprueba que sin él la puerta responda 403 y con él 200.
 *  2. **Los filtros mueven las DOS cifras.** El resumen de arriba y la tabla de
 *     abajo tienen que hablar del mismo conjunto. La primera versión dejaba el
 *     filtro de persona fuera del resumen y la pantalla decía «ninguna
 *     ejecución» encima de un «119» — dos universos pegados, que es el defecto
 *     que este proyecto ya se cobró en el tablero de la bolsa.
 *  3. **La purga BORRA DE VERDAD.** `EjecucionReporte` usa `TieneAuditoria`, o
 *     sea borrado lógico: un `->delete()` informa «borradas 400» y no quita ni
 *     una fila de la tabla. Se comprueba contra la tabla FÍSICA, no contra el
 *     modelo, porque el modelo no vería la diferencia.
 *  4. **Y respeta la retención**: lo de dentro sigue ahí. Esta mitad sola NO
 *     prueba nada —se cumple igual con la purga rota—, que es exactamente por
 *     lo que hacen falta las dos.
 *  5. **La pantalla no filtra datos.** Se comprueba que los renglones no traen
 *     las filas de ningún reporte: la bitácora guarda lo que se PIDIÓ.
 */

use App\Http\Controllers\Reportes\BitacoraReportesController;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\EjecucionReporte;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Una cuenta con el rol que se pida. */
function cuentaCon(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Bitacora',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_bit_'.random_int(100000, 999999),
        'email' => 'prueba_bit_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Cuántas filas hay FÍSICAMENTE, saltándose el borrado lógico del modelo. */
function filasFisicas(): int
{
    return (int) DB::table('ejecuciones_reporte')->count();
}

DB::beginTransaction();

try {
    $controlador = app(BitacoraReportesController::class);

    echo PHP_EOL.'1. El permiso existe y es PROPIO'.PHP_EOL;

    verificar('`auditar-reportes` está en el catálogo',
        App\Support\CatalogoPermisos::existe('auditar-reportes'));

    verificar('Y sembrado en la base',
        DB::table('permissions')->where('name', 'auditar-reportes')->exists());

    $auditor = cuentaCon('director_general');
    auth()->login($auditor);

    verificar('Dirección general lo tiene, derivado de su faceta',
        $auditor->can('auditar-reportes'));

    /*
     * Y un rol que NO lo tiene. Se construye: los roles funcionales del demo son
     * borrables por diseño, así que no se busca ninguno por nombre.
     */
    $rolPelado = Rol::create([
        'name' => 'prueba_sin_auditar_'.random_int(1000, 9999),
        'nombre' => 'Rol de prueba sin auditar',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rolPelado->givePermissionTo('ver-reportes');

    $mirón = cuentaCon('director_general');
    DB::table('persona_rol')->where('persona_id', $mirón->persona_id)->update(['rol_id' => $rolPelado->id]);
    Usuario::where('id', $mirón->id)->update(['rol_activo_id' => $rolPelado->id]);
    $mirón = Usuario::find($mirón->id);
    auth()->login($mirón);

    verificar('Quien sólo ve reportes NO puede auditar',
        $mirón->can('ver-reportes') && ! $mirón->can('auditar-reportes'),
        'ver-reportes: '.($mirón->can('ver-reportes') ? 'sí' : 'no')
            .', auditar: '.($mirón->can('auditar-reportes') ? 'sí' : 'no'));

    echo PHP_EOL.'2. Los filtros mueven las DOS cifras'.PHP_EOL;

    auth()->login($auditor);

    /*
     * Se SIEMBRA el escenario: dos personas distintas con ejecuciones propias.
     * El demo tiene 119 filas y TODAS de la misma persona, así que un filtro por
     * nombre no distinguiría nada y la comprobación pasaría sin comprobar.
     */
    $otra = cuentaCon('director_general');

    foreach ([['xlsx', 900], ['pantalla', 12]] as [$formato, $filas]) {
        EjecucionReporte::create([
            'reporte' => 'alumnos-inscritos',
            'persona_id' => $otra->persona_id,
            'formato' => $formato,
            'filas' => $filas,
            'milisegundos' => 42,
            'filtros' => ['campus_id' => [32]],
            'columnas' => ['matricula', 'alumno'],
            'columnas_omitidas' => ['curp'],
        ]);
    }

    $pedir = function (array $parametros) use ($controlador, $auditor): array {
        $peticion = Request::create('/reportes/bitacora', 'GET', $parametros);
        $peticion->setUserResolver(fn () => $auditor);
        auth()->login($auditor);

        return $controlador->index($peticion)->toResponse($peticion)->getOriginalContent()['page']['props'];
    };

    $todo = $pedir([]);
    $suyas = $pedir(['persona' => 'Bitacora']);
    $ninguna = $pedir(['persona' => 'ZZNoExisteNadieAsi']);

    verificar('Sin filtro, tabla y resumen coinciden',
        $todo['ejecuciones']['total'] === $todo['resumen']['ejecuciones'],
        $todo['ejecuciones']['total'].' / '.$todo['resumen']['ejecuciones']);

    verificar('Filtrando por un nombre que SÍ existe, coinciden y son menos',
        $suyas['ejecuciones']['total'] === $suyas['resumen']['ejecuciones']
            && $suyas['ejecuciones']['total'] > 0
            && $suyas['ejecuciones']['total'] < $todo['ejecuciones']['total'],
        $suyas['ejecuciones']['total'].' de '.$todo['ejecuciones']['total']);

    /*
     * Ésta es la que cazó el defecto al mirar la pantalla: la tabla decía
     * «ninguna» y el resumen seguía diciendo 119.
     */
    verificar('Y con un nombre que NO existe, el resumen también se va a cero',
        $ninguna['ejecuciones']['total'] === 0 && $ninguna['resumen']['ejecuciones'] === 0,
        'tabla '.$ninguna['ejecuciones']['total'].', resumen '.$ninguna['resumen']['ejecuciones']);

    $soloXlsx = $pedir(['formato' => 'xlsx']);

    verificar('El filtro de formato también mueve las dos',
        $soloXlsx['ejecuciones']['total'] === $soloXlsx['resumen']['ejecuciones'],
        $soloXlsx['ejecuciones']['total'].' / '.$soloXlsx['resumen']['ejecuciones']);

    verificar('Y en un corte de sólo descargas, descargas == ejecuciones',
        $soloXlsx['resumen']['descargas'] === $soloXlsx['resumen']['ejecuciones']);

    echo PHP_EOL.'3. Lo que la pantalla enseña, y lo que no'.PHP_EOL;

    $renglon = collect($suyas['ejecuciones']['data'])
        ->firstWhere('reporte', 'alumnos-inscritos');

    verificar('El renglón trae el TÍTULO del reporte, no sólo su clave',
        $renglon !== null && $renglon['titulo'] !== null,
        $renglon['titulo'] ?? 'sin renglón');

    verificar('Los filtros salen con su ETIQUETA, no con la clave de la columna',
        $renglon !== null
            && $renglon['filtros'] !== []
            && $renglon['filtros'][0]['etiqueta'] !== 'campus_id',
        $renglon === null ? '—' : ($renglon['filtros'][0]['etiqueta'] ?? '—'));

    verificar('Las columnas omitidas salen con su etiqueta',
        $renglon !== null && $renglon['omitidas'] !== [] && $renglon['omitidas'][0] !== 'curp',
        $renglon === null ? '—' : implode(', ', $renglon['omitidas']));

    /*
     * La bitácora guarda lo que se PIDIÓ, nunca lo que salió. Si un renglón
     * trajera datos de personas, `auditar-reportes` sería una puerta trasera a
     * la información de todos los reportes.
     */
    verificar('Un renglón NO trae ninguna fila del reporte',
        $renglon !== null && ! array_intersect(
            array_keys($renglon),
            ['datos', 'filas_datos', 'resultado', 'contenido'],
        ),
        'claves: '.implode(', ', array_keys($renglon ?? [])));

    echo PHP_EOL.'4. Un reporte RETIRADO deja sus ejecuciones legibles'.PHP_EOL;

    EjecucionReporte::create([
        'reporte' => 'reporte-que-ya-no-existe',
        'persona_id' => $otra->persona_id,
        'formato' => 'csv',
        'filas' => 3,
        'milisegundos' => 5,
        'filtros' => ['algo' => 1],
        'columnas' => ['x'],
        'columnas_omitidas' => [],
    ]);

    $conRetirado = collect($pedir(['persona' => 'Bitacora'])['ejecuciones']['data'])
        ->firstWhere('reporte', 'reporte-que-ya-no-existe');

    verificar('Sale, con su clave, en vez de reventar o desaparecer',
        $conRetirado !== null && $conRetirado['titulo'] === null,
        $conRetirado === null ? 'no salió' : 'clave: '.$conRetirado['reporte']);

    verificar('Y su desplegable lo marca como retirado',
        collect($pedir([])['reportes'])
            ->contains(fn (array $r) => $r['clave'] === 'reporte-que-ya-no-existe'
                && str_contains($r['titulo'], 'retirado')));

    echo PHP_EOL.'5. La purga BORRA de verdad, y respeta la retención'.PHP_EOL;

    $antesDeSembrar = filasFisicas();

    // Dentro de la retención de 365 días.
    $dentro = EjecucionReporte::create([
        'reporte' => 'alumnos-inscritos', 'persona_id' => $otra->persona_id,
        'formato' => 'pantalla', 'filas' => 1, 'milisegundos' => 1,
        'filtros' => [], 'columnas' => [], 'columnas_omitidas' => [],
    ]);
    DB::table('ejecuciones_reporte')->where('id', $dentro->id)
        ->update(['created_at' => now()->subDays(200)]);

    // Fuera de la retención.
    $fuera = [];

    foreach (range(1, 3) as $i) {
        $e = EjecucionReporte::create([
            'reporte' => 'alumnos-inscritos', 'persona_id' => $otra->persona_id,
            'formato' => 'pantalla', 'filas' => 1, 'milisegundos' => 1,
            'filtros' => [], 'columnas' => [], 'columnas_omitidas' => [],
        ]);
        DB::table('ejecuciones_reporte')->where('id', $e->id)
            ->update(['created_at' => now()->subDays(400 + $i)]);
        $fuera[] = $e->id;
    }

    /*
     * Y una que YA estaba dada de baja lógica y además es vieja. Si la purga
     * mirara sin `withTrashed()`, ésta no se borraría NUNCA — invisible para el
     * modelo y presente en la tabla para siempre.
     */
    $viejaYDeBaja = EjecucionReporte::create([
        'reporte' => 'alumnos-inscritos', 'persona_id' => $otra->persona_id,
        'formato' => 'pantalla', 'filas' => 1, 'milisegundos' => 1,
        'filtros' => [], 'columnas' => [], 'columnas_omitidas' => [],
    ]);
    DB::table('ejecuciones_reporte')->where('id', $viejaYDeBaja->id)
        ->update(['created_at' => now()->subDays(500), 'deleted_at' => now()]);

    $sembradas = filasFisicas() - $antesDeSembrar;

    verificar('Se sembraron las cinco del escenario', $sembradas === 5, (string) $sembradas);

    $fisicasAntes = filasFisicas();

    // El comando, tal cual lo corre el scheduler.
    $codigo = Illuminate\Support\Facades\Artisan::call('reportes:purgar-ejecuciones', [
        '--dias' => 365,
        '--tenant' => ['demo'],
    ]);

    verificar('El comando termina bien', $codigo === 0, 'código '.$codigo);

    /*
     * Se miden SUS PROPIAS filas, por id, y no un delta global.
     *
     * Decía `$fisicasAntes - $fisicasDespues === 4`, o sea que daba por hecho
     * que la bitácora no tenía NINGUNA otra fila pasada de la retención. Se cayó
     * en cuanto la tuvo: una revisión dejó cincuenta mil corridas viejas y la
     * suite reportó «120367 → 70447 (esperado -4)» — roja sin que nada estuviera
     * roto.
     *
     * Es la misma lección que este proyecto ya pagó dos veces en un solo día:
     * **una suite se mide POR DIFERENCIA sobre lo suyo, no contra cero.** Y aquí
     * ni siquiera hace falta la diferencia: los ids se conocen.
     *
     * Contra la tabla FÍSICA y no contra el modelo: con `->delete()` el modelo
     * diría que borró y la tabla no habría cambiado.
     */
    $sembradasViejas = array_merge($fuera, [$viejaYDeBaja->id]);

    verificar('Las cuatro viejas DESAPARECIERON de la tabla física',
        DB::table('ejecuciones_reporte')->whereIn('id', $sembradasViejas)->count() === 0,
        count($sembradasViejas).' sembradas, quedan '
            .DB::table('ejecuciones_reporte')->whereIn('id', $sembradasViejas)->count());

    verificar('Y la purga se llevó al menos esas cuatro',
        $fisicasAntes - filasFisicas() >= 4,
        $fisicasAntes.' → '.filasFisicas());

    /*
     * Y la que YA estaba dada de baja lógica también se fue. Sin `withTrashed()`
     * en el conteo del comando, ésa no se contaría al informar —ver el docblock
     * de `viejas()`—, aunque el `forceDelete` se la lleve igual.
     */
    verificar('Incluida la que ya estaba dada de baja lógica',
        DB::table('ejecuciones_reporte')->where('id', $viejaYDeBaja->id)->count() === 0);

    verificar('La de DENTRO de la retención sigue ahí',
        DB::table('ejecuciones_reporte')->where('id', $dentro->id)->count() === 1);

    verificar('Y sigue viva, no dada de baja',
        EjecucionReporte::whereKey($dentro->id)->exists());

    echo PHP_EOL.'6. El modo SECO no borra'.PHP_EOL;

    $viejaOtraVez = EjecucionReporte::create([
        'reporte' => 'alumnos-inscritos', 'persona_id' => $otra->persona_id,
        'formato' => 'pantalla', 'filas' => 1, 'milisegundos' => 1,
        'filtros' => [], 'columnas' => [], 'columnas_omitidas' => [],
    ]);
    DB::table('ejecuciones_reporte')->where('id', $viejaOtraVez->id)
        ->update(['created_at' => now()->subDays(800)]);

    $antesSeco = filasFisicas();
    Illuminate\Support\Facades\Artisan::call('reportes:purgar-ejecuciones', [
        '--dias' => 365, '--tenant' => ['demo'], '--seco' => true,
    ]);

    verificar('En seco no desaparece nada', filasFisicas() === $antesSeco,
        $antesSeco.' → '.filasFisicas());

    $salidaSeca = Illuminate\Support\Facades\Artisan::output();

    verificar('Pero lo REPORTA', str_contains($salidaSeca, 'Se borrarían'));

    /*
     * Y la CUENTA del modo seco tiene que incluir lo dado de baja lógica.
     *
     * Es lo único que `withTrashed()` cambia de verdad —comprobado que
     * `forceDelete()` se salta el scope y borra lo de baja igual—, así que sin
     * esta comprobación esa llamada sería una salvaguarda que no salva nada.
     * Y el número importa: es el que lee quien decide si purga.
     */
    $deBajaYVieja = EjecucionReporte::create([
        'reporte' => 'alumnos-inscritos', 'persona_id' => $otra->persona_id,
        'formato' => 'pantalla', 'filas' => 1, 'milisegundos' => 1,
        'filtros' => [], 'columnas' => [], 'columnas_omitidas' => [],
    ]);
    DB::table('ejecuciones_reporte')->where('id', $deBajaYVieja->id)
        ->update(['created_at' => now()->subDays(900), 'deleted_at' => now()]);

    Illuminate\Support\Facades\Artisan::call('reportes:purgar-ejecuciones', [
        '--dias' => 365, '--tenant' => ['demo'], '--seco' => true,
    ]);
    $conBaja = Illuminate\Support\Facades\Artisan::output();
    preg_match('/\|\s*demo\s*\|\s*(\d+)\s*\|/', $conBaja, $m);
    $cuentaSeca = (int) ($m[1] ?? -1);

    verificar('La cuenta en seco CUENTA también lo dado de baja lógica',
        $cuentaSeca >= 2,
        'dice que borraría '.$cuentaSeca.' (las dos viejas: la viva y la de baja)');

    echo PHP_EOL.'7. La retención tiene PISO'.PHP_EOL;

    /*
     * `--dias=0` borraría la bitácora entera, y un cero se teclea sin querer.
     * El piso de 30 días es el mismo que el precedente de tutorías.
     */
    $recientes = fn () => (int) DB::table('ejecuciones_reporte')
        ->where('created_at', '>=', now()->subDays(30))
        ->count();

    $recientesAntes = $recientes();
    $antesPiso = filasFisicas();

    Illuminate\Support\Facades\Artisan::call('reportes:purgar-ejecuciones', [
        '--dias' => 0, '--tenant' => ['demo'],
    ]);

    /*
     * Lo que el piso PROMETE no es «no borra nada»: es que la retención nunca
     * baja de 30 días. Así que lo de los últimos 30 tiene que seguir intacto
     * aunque se teclee un cero.
     */
    verificar('Con --dias=0 la retención cae al PISO de 30 días, no a cero',
        $recientes() === $recientesAntes && $recientesAntes > 0,
        $recientesAntes.' recientes antes, '.$recientes().' después');

    verificar('Y aun así se lleva lo que pasa de 30 días',
        filasFisicas() < $antesPiso,
        $antesPiso.' → '.filasFisicas());

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
