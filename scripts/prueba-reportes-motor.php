<?php

/**
 * El motor de reportes. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-motor.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. Los FILTROS FIJOS del reporte ganan siempre. Es lo que separa un reporte
 *     de un listado: si se pudieran aflojar desde la petición, el «reporte de
 *     inscritos» acabaría incluyendo bajas en una junta de consejo.
 *  2. El RECORTE por campus lo aplica el MOTOR, así que ninguna fuente puede
 *     olvidarlo. Un rol acotado no ve las matrículas de otro plantel.
 *  3. Las columnas SENSIBLES se omiten para quien no tiene el permiso extra,
 *     y se ANOTAN: ni se aborta el reporte ni se calla.
 *  4. Una columna inventada se descarta en silencio (una vista vieja no debe
 *     reventar) y nunca queda un reporte sin columnas.
 *  5. Un filtro de lista se valida contra el catálogo VIVO: escribir a mano un
 *     id que no está en las opciones NO ensancha la consulta.
 *  6. La bitácora anota cada corrida, con los filtros EFECTIVOS.
 *  7. Los tres presets sobre la MISMA fuente dan tres resultados distintos —es
 *     lo que evita cuarenta clases de consulta casi iguales— y sus situaciones
 *     salen del catálogo por clave o bandera, no de ids cableados.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\EjecucionReporte;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Reportes\Salida\ExportadorXlsx;
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

/** Un usuario propio con su rol activo: nunca se toca el de nadie más. */
function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Reportes',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_rep_'.random_int(100000, 999999),
        'email' => 'prueba_rep_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

$db->beginTransaction();

try {
    $ejecutor = app(Ejecutor::class);
    $registro = app(RegistroReportes::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo '1. Tres presets sobre la MISMA fuente dan tres respuestas'.PHP_EOL;

    $inscritos = $ejecutor->ejecutar($global, 'alumnos-inscritos');
    $bajas = $ejecutor->ejecutar($global, 'bajas-de-alumnos');
    $egresados = $ejecutor->ejecutar($global, 'egresados-por-generacion');

    verificar('Los tres salen de la fuente de matrículas',
        $inscritos->fuente->clave() === 'matriculas'
        && $bajas->fuente->clave() === 'matriculas'
        && $egresados->fuente->clave() === 'matriculas');

    verificar('Y dan totales distintos',
        $inscritos->total() !== $egresados->total(),
        "inscritos {$inscritos->total()}, egresados {$egresados->total()}");

    // Las situaciones salen del CATÁLOGO, no de ids cableados: un id fijo
    // funciona hoy y deja de funcionar en silencio si alguien resiembra.
    $activo = (int) SituacionAlumno::query()->where('clave', 'activo')->value('id');
    verificar('El preset de inscritos fija la situación por su CLAVE',
        $inscritos->filtros['situacion_id'] === [$activo],
        json_encode($inscritos->filtros['situacion_id']));

    $conBandera = SituacionAlumno::query()->where('cuenta_como_egresado', true)->pluck('id')->all();
    verificar('El de egresados usa la BANDERA del catálogo',
        $egresados->filtros['situacion_id'] == $conBandera,
        json_encode($egresados->filtros['situacion_id']));

    echo PHP_EOL.'2. Los filtros fijos del reporte NO se pueden aflojar'.PHP_EOL;

    $todas = SituacionAlumno::query()->pluck('id')->all();

    $intento = $ejecutor->ejecutar($global, 'alumnos-inscritos', [
        'filtros' => ['situacion_id' => $todas],
    ]);

    verificar('Pedir todas las situaciones no ensancha el reporte',
        $intento->total() === $inscritos->total(),
        "con intento {$intento->total()}, sin él {$inscritos->total()}");

    verificar('Y el filtro efectivo sigue siendo el del reporte',
        $intento->filtros['situacion_id'] === [$activo]);

    echo PHP_EOL.'3. El recorte por campus lo aplica el MOTOR'.PHP_EOL;

    // Un campus con matrículas y otro rol acotado a él.
    $campusId = (int) $db->table('oferta')
        ->join('matricula_oferta as m', 'm.oferta_id', '=', 'oferta.id')
        ->whereNull('m.deleted_at')
        ->value('oferta.campus_id');

    $acotado = usuarioConRol('director_general', $campusId);
    auth()->login($acotado);

    $suyo = $ejecutor->ejecutar($acotado, 'alumnos-inscritos');

    verificar('El rol acotado ve menos o igual que el global',
        $suyo->total() <= $inscritos->total(),
        "acotado {$suyo->total()} de {$inscritos->total()}");

    verificar('Y su alcance es de UN campus',
        $acotado->campusVisibles() === [$campusId],
        json_encode($acotado->campusVisibles()));

    // Todas sus filas son de su campus: no basta con que sean menos.
    $campusDeLasFilas = collect($suyo->filas)->pluck('campus')->unique()->filter()->values();
    $nombreCampus = $db->table('campus')->where('id', $campusId)->value('nombre');

    verificar('Todas las filas que ve son de SU campus',
        $campusDeLasFilas->count() <= 1 && ($campusDeLasFilas->first() ?? $nombreCampus) === $nombreCampus,
        $campusDeLasFilas->implode(', '));

    /*
     * Las OPCIONES del filtro también se acotan.
     *
     * No basta con que el recorte deje la consulta en cero: si el desplegable
     * enumera los demás planteles, ya filtró el nombre de todos los campus de
     * la escuela a quien sólo administra uno. Faltaba, y la mutación lo destapó.
     */
    $opcionesDelAcotado = app(RegistroReportes::class)
        ->fuente('matriculas')
        ->filtros()['campus_id']
        ->opcionesPara($acotado);

    $todosLosCampus = $db->table('campus')->count();

    verificar('El desplegable de campus sólo ofrece los suyos',
        array_keys($opcionesDelAcotado) === [$campusId],
        'ofrece '.count($opcionesDelAcotado).' de '.$todosLosCampus);

    $opcionesDelGlobal = app(RegistroReportes::class)
        ->fuente('matriculas')
        ->filtros()['campus_id']
        ->opcionesPara($global);

    verificar('Y a quien ve toda la escuela se los ofrece todos',
        count($opcionesDelGlobal) === $todosLosCampus,
        count($opcionesDelGlobal).' de '.$todosLosCampus);

    echo PHP_EOL.'4. Las columnas sensibles se omiten y se DICEN'.PHP_EOL;

    auth()->login($global);

    // La CURP exige `editar-alumnos`. Se pide explícitamente.
    $conCurp = $ejecutor->ejecutar($global, 'alumnos-inscritos', [
        'columnas' => ['matricula', 'alumno', 'curp'],
    ]);

    $sinPermiso = usuarioConRol('administrativo');
    auth()->login($sinPermiso);

    verificar('El rol de prueba NO tiene el permiso de la columna sensible',
        ! $sinPermiso->can('editar-alumnos'));

    // Puede que ese rol tampoco pueda ver el reporte; se comprueba antes.
    if ($sinPermiso->can('ver-alumnos')) {
        $recortado = $ejecutor->ejecutar($sinPermiso, 'alumnos-inscritos', [
            'columnas' => ['matricula', 'alumno', 'curp'],
        ]);

        verificar('La CURP no sale para quien no la alcanza',
            ! in_array('curp', array_map(fn ($c) => $c->clave, $recortado->columnas), true));

        verificar('Y se ANOTA que se omitió, en vez de callarlo',
            in_array('curp', $recortado->columnasOmitidas, true),
            implode(',', $recortado->etiquetasOmitidas()));

        /*
         * Y la BITÁCORA lo guarda.
         *
         * Es lo que contesta «¿por qué mi Excel no trae la CURP?» sin abrir el
         * código. Faltaba: al mutar el ejecutor para que anotara siempre un
         * arreglo vacío, la prueba seguía en verde.
         */
        $anotada = EjecucionReporte::latest('id')->first();

        verificar('La bitácora guarda qué columnas se omitieron',
            in_array('curp', $anotada->columnas_omitidas ?? [], true),
            json_encode($anotada->columnas_omitidas));
    } else {
        echo '  (el rol administrativo no ve alumnos en esta escuela; se omite el caso)'.PHP_EOL;
    }

    auth()->login($global);

    verificar('Con el permiso, la CURP sí sale',
        in_array('curp', array_map(fn ($c) => $c->clave, $conCurp->columnas), true));

    echo PHP_EOL.'5. Saneado: lo inventado se descarta sin reventar'.PHP_EOL;

    $inventadas = $ejecutor->ejecutar($global, 'alumnos-inscritos', [
        'columnas' => ['matricula', 'columna-que-no-existe'],
    ]);

    verificar('Una columna inventada se descarta en silencio',
        array_map(fn ($c) => $c->clave, $inventadas->columnas) === ['matricula']);

    $vacias = $ejecutor->ejecutar($global, 'alumnos-inscritos', ['columnas' => ['nada', 'tampoco']]);

    verificar('Con todo inválido queda al menos una columna',
        count($vacias->columnas) >= 1,
        'quedaron '.count($vacias->columnas));

    $filtroRaro = $ejecutor->ejecutar($global, 'alumnos-inscritos', [
        'filtros' => ['filtro-que-no-existe' => 'x'],
    ]);

    verificar('Un filtro inventado se ignora',
        ! array_key_exists('filtro-que-no-existe', $filtroRaro->filtros));

    echo PHP_EOL.'6. Un valor fuera del catálogo se RECHAZA'.PHP_EOL;

    $rechazado = false;

    try {
        // `campus_id` es lista: sus opciones salen del alcance del usuario, así
        // que un id inventado no puede pasar.
        $ejecutor->ejecutar($global, 'alumnos-inscritos', [
            'filtros' => ['campus_id' => [999999]],
        ]);
    } catch (ValidationException $e) {
        $rechazado = true;
    }

    // La lista múltiple filtra los que no están; lo que importa es que NO
    // consulte con un id ajeno.
    $conAjeno = $ejecutor->ejecutar($global, 'alumnos-inscritos', [
        'filtros' => ['campus_id' => [999999]],
    ]);

    verificar('Un campus inexistente no se cuela en el filtro',
        $rechazado || ($conAjeno->filtros['campus_id'] ?? []) === [],
        json_encode($conAjeno->filtros['campus_id'] ?? null));

    echo PHP_EOL.'7. La bitácora anota cada corrida'.PHP_EOL;

    $antes = EjecucionReporte::count();
    $ejecutor->ejecutar($global, 'egresados-por-generacion');
    $ultima = EjecucionReporte::latest('id')->first();

    verificar('Se anotó una ejecución más', EjecucionReporte::count() === $antes + 1);
    verificar('Con el reporte y quién lo corrió',
        $ultima->reporte === 'egresados-por-generacion' && $ultima->persona_id === $global->persona_id);
    verificar('Y con los filtros EFECTIVOS, no los que se pidieron',
        ($ultima->filtros['situacion_id'] ?? null) == $conBandera,
        json_encode($ultima->filtros));

    echo PHP_EOL.'8. El registro sólo ofrece lo que la persona puede ver'.PHP_EOL;

    verificar('El director ve los tres reportes',
        count($registro->para($global)) === 3,
        (string) count($registro->para($global)));

    // Una clave desconocida es 404, no 403: un 403 ya confirma que existe.
    $estado = null;

    try {
        $ejecutor->ejecutar($global, 'reporte-que-no-existe');
    } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
        $estado = 404;
    }

    verificar('Un reporte desconocido da 404 y no 403', $estado === 404);

    echo PHP_EOL.'9. La exportación sale del MISMO motor'.PHP_EOL;

    auth()->login($global);

    /*
     * Con lotes de CINCO, no de quinientos.
     *
     * Con el tamaño real, dieciocho filas caben en un solo lote y el bucle del
     * keyset nunca da una segunda vuelta: la parte más delicada de la
     * exportación quedaría sin probar. Comprobado —al mutar el keyset para que
     * ignorara el orden, la suite seguía en verde—. Con lotes de cinco son
     * cuatro vueltas y las mutaciones mueren.
     */
    $ejecutorLotesChicos = new class(app(RegistroReportes::class), app(App\Services\Plataforma\ModulosDeLaEscuela::class)) extends Ejecutor
    {
        protected function tamanoDeLote(): int
        {
            return 5;
        }
    };

    $exp = $ejecutorLotesChicos->paraExportar($global, 'alumnos-inscritos');

    verificar('El total de la exportación es el de la pantalla',
        $exp->total === $inscritos->total(),
        "exportación {$exp->total}, pantalla {$inscritos->total()}");

    // Se recorre TODO, no una página: es lo que distingue una descarga de una
    // captura de pantalla.
    $recorridas = iterator_to_array($exp->recorrer(), false);

    verificar('El recorrido entrega todas las filas',
        count($recorridas) === $exp->total,
        count($recorridas).' de '.$exp->total);

    verificar('Y con las columnas del reporte',
        array_keys($recorridas[0] ?? []) === array_map(fn ($c) => $c->clave, $exp->columnas));

    /*
     * El recorrido RESPETA el orden pedido.
     *
     * Es lo que `chunkById` habria roto: reemplaza el ORDER BY por el de la
     * llave primaria, asi que un CSV «ordenado por fecha de ingreso» habria
     * salido ordenado por id --sin ningun error y sin que nadie lo note--.
     */
    $porFecha = $ejecutorLotesChicos->paraExportar($global, 'alumnos-inscritos', [
        'columnas' => ['matricula', 'fecha_ingreso'],
        'orden_por' => 'fecha_ingreso',
        'orden_dir' => 'asc',
    ]);

    $fechas = array_values(array_filter(array_map(
        fn ($f) => $f['fecha_ingreso'] instanceof DateTimeInterface ? $f['fecha_ingreso']->format('Y-m-d') : (string) $f['fecha_ingreso'],
        iterator_to_array($porFecha->recorrer(), false),
    )));

    $ordenadas = $fechas;
    sort($ordenadas);

    verificar('El recorrido respeta el orden pedido, no el de la llave',
        $fechas === $ordenadas,
        'primeras: '.implode(', ', array_slice($fechas, 0, 3)));

    verificar('Y no repite ni se salta filas',
        count($fechas) === $exp->total,
        count($fechas).' de '.$exp->total);

    echo PHP_EOL.'10. El Excel avisa ANTES en vez de morirse a la mitad'.PHP_EOL;

    app(Ajustes::class)->guardar([CatalogoAjustes::TOPE_FILAS_XLSX => 5]);
    app(Ajustes::class)->olvidar();

    $estadoTope = null;
    $mensajeTope = '';

    try {
        app(ExportadorXlsx::class)->responder($ejecutorLotesChicos->paraExportar($global, 'alumnos-inscritos'));
    } catch (AvisoParaElUsuario $e) {
        $estadoTope = $e->getStatusCode();
        $mensajeTope = $e->getMessage();
    }

    verificar('Por encima del tope se niega con 422', $estadoTope === 422);

    verificar('Y el mensaje dice la cifra real Y la salida',
        str_contains($mensajeTope, (string) $exp->total) && str_contains($mensajeTope, 'CSV'),
        $mensajeTope);

    app(Ajustes::class)->olvidar();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    $db->rollBack();
}
