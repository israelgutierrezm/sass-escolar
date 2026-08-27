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
use App\Http\Controllers\Reportes\ConfiguracionReportesController;
use App\Http\Controllers\Reportes\VistaReporteController;
use Database\Seeders\Tenant\AreasReporteSeeder;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\AreaReporte;
use App\Models\Reportes\ReporteFavorito;
use App\Models\Reportes\VistaReporte;
use App\Models\Reportes\EjecucionReporte;
use App\Models\Reportes\UbicacionReporte;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Reportes\Salida\TextoDeCelda;
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

/**
 * Una petición con datos, para invocar a un controlador.
 *
 * Lleva el resolutor de usuario: varios controladores leen `$peticion->user()`
 * y no `Auth::user()`, así que sin esto ven null. Es la misma trampa que ya
 * mordió en la suite de disciplina.
 */
function peticionCon(array $datos, ?Usuario $como = null): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', 'POST', $datos);

    $p->setUserResolver(fn () => $como ?? auth()->user());

    return $p;
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

    echo PHP_EOL.'7. La bitácora anota cada PREGUNTA, no cada clic'.PHP_EOL;

    /*
     * Con un juego de columnas PROPIO, para que sea una pregunta nueva.
     *
     * Las secciones de arriba ya corrieron este reporte, y desde que los
     * repintados se deduplican, repetir lo mismo dentro de la ventana no
     * escribe. Sin esto, la comprobación medía la deduplicación creyendo medir
     * la anotación — y las dos de abajo leerían la fila de otra corrida.
     */
    $columnasPropias = ['matricula', 'egresado', 'generacion'];

    $antes = EjecucionReporte::count();
    $ejecutor->ejecutar($global, 'egresados-por-generacion', ['columnas' => $columnasPropias]);
    $ultima = EjecucionReporte::latest('id')->first();

    verificar('Se anotó una ejecución más', EjecucionReporte::count() === $antes + 1,
        $antes.' → '.EjecucionReporte::count());

    /*
     * ── El REPINTADO no se anota ──────────────────────────────────────────
     *
     * Volver atrás, recargar la pestaña o deshacer un filtro pedían lo mismo
     * otra vez y escribían una fila cada vez: la bitácora contaba clics. Medido
     * antes de cambiarlo, 113 de 119 filas del demo eran de pantalla, con 44
     * repeticiones idénticas en menos de dos minutos sobre 40 consultas
     * distintas.
     *
     * Lo que NO se deduplica es la descarga: un archivo sale de la escuela y se
     * reenvía, así que «bajó el padrón tres veces» es otro hecho que «una».
     */
    $trasLaPrimera = EjecucionReporte::count();
    $ejecutor->ejecutar($global, 'egresados-por-generacion', ['columnas' => $columnasPropias]);

    verificar('Pedir LO MISMO otra vez no agrega fila',
        EjecucionReporte::count() === $trasLaPrimera,
        $trasLaPrimera.' → '.EjecucionReporte::count());

    /*
     * Y cambiar la pregunta sí. Se cambian las COLUMNAS y no los filtros porque
     * este reporte lleva filtros fijos: dos peticiones distintas pueden
     * normalizar al mismo JSON efectivo, y entonces son la misma pregunta.
     */
    $ejecutor->ejecutar($global, 'egresados-por-generacion', ['columnas' => ['matricula']]);

    verificar('Pero cambiar la pregunta sí agrega fila',
        EjecucionReporte::count() === $trasLaPrimera + 1,
        $trasLaPrimera.' → '.EjecucionReporte::count());

    $antesDeVolver = EjecucionReporte::count();
    $ejecutor->ejecutar($global, 'egresados-por-generacion', ['columnas' => $columnasPropias]);

    verificar('Y VOLVER a la anterior también: A, B, A son tres preguntas',
        EjecucionReporte::count() === $antesDeVolver + 1,
        $antesDeVolver.' → '.EjecucionReporte::count());

    /*
     * Y la VENTANA tiene su lector: pasados los diez minutos, volver a pedir lo
     * mismo ya no es un repintado —es consultarlo otra vez— y cuenta como uso,
     * que es lo que esta tabla mide. Sin esta comprobación la ventana sería una
     * regla que nadie vigila: quitarla del todo pasaba en verde.
     */
    /*
     * Se envejecen TODAS las suyas de ese reporte, no sólo la última: el motor
     * busca la más reciente por fecha, así que envejecer una sola la manda al
     * fondo y encuentra otra. La primera versión de esto no ejercitaba la
     * ventana —se vio porque la mutación que la quitaba sobrevivía—.
     */
    DB::table('ejecuciones_reporte')
        ->where('persona_id', $global->persona_id)
        ->where('reporte', 'egresados-por-generacion')
        ->update(['created_at' => now()->subMinutes(60)]);

    $antesDeLaVentana = EjecucionReporte::count();
    $ejecutor->ejecutar($global, 'egresados-por-generacion', ['columnas' => $columnasPropias]);

    verificar('Pasada la ventana, lo mismo SÍ vuelve a anotarse',
        EjecucionReporte::count() === $antesDeLaVentana + 1,
        $antesDeLaVentana.' → '.EjecucionReporte::count());

    /*
     * ── Dos consultas del mismo reporte con distintos FILTROS son dos ─────
     *
     * No lo comprobaba nadie: quitando la comparación de filtros, todo seguía en
     * verde porque las comprobaciones de arriba cambian las COLUMNAS.
     */
    $antesDeFiltros = EjecucionReporte::count();
    $conCiclo = DB::table('grupos')->whereNull('deleted_at')->value('ciclo_id');
    $otroCiclo = DB::table('ciclos')->whereNull('deleted_at')
        ->where('id', '!=', $conCiclo)->value('id');

    if ($otroCiclo !== null) {
        $ejecutor->ejecutar($global, 'carga-academica', ['filtros' => ['ciclo_id' => [$conCiclo]]]);
        $ejecutor->ejecutar($global, 'carga-academica', ['filtros' => ['ciclo_id' => [$conCiclo]]]);

        verificar('Dos veces el mismo filtro: una sola fila',
            EjecucionReporte::count() === $antesDeFiltros + 1,
            $antesDeFiltros.' → '.EjecucionReporte::count());

        $trasElPrimero = EjecucionReporte::count();
        $ejecutor->ejecutar($global, 'carga-academica', ['filtros' => ['ciclo_id' => [$otroCiclo]]]);

        verificar('Y cambiar SÓLO el filtro sí agrega fila',
            EjecucionReporte::count() === $trasElPrimero + 1,
            $trasElPrimero.' → '.EjecucionReporte::count());
    } else {
        echo '  (la escuela tiene un solo ciclo; se omite el caso de los filtros)'.PHP_EOL;
    }

    /*
     * ── Y el orden de las claves NO hace de dos consultas dos ─────────────
     *
     * Se compara contra lo GUARDADO, y la columna es `json` nativo: MySQL
     * normaliza el orden de las claves al escribirlas, así que el arreglo que se
     * lee de vuelta no conserva el orden con que se mandó. Con `===` eso hacía
     * fallar la deduplicación en silencio en cuanto había más de un filtro.
     */
    $antesDeOrden = EjecucionReporte::count();
    $ejecutor->ejecutar($global, 'alumnos-inscritos', ['columnas' => ['alumno', 'matricula']]);
    $ejecutor->ejecutar($global, 'alumnos-inscritos', ['columnas' => ['matricula', 'alumno']]);

    verificar('Las mismas columnas en otro orden son la MISMA consulta',
        EjecucionReporte::count() === $antesDeOrden + 1,
        $antesDeOrden.' → '.EjecucionReporte::count());

    $antesDeBajar = EjecucionReporte::count();
    $exportacion = $ejecutor->paraExportar($global, 'egresados-por-generacion');
    ($exportacion->alTerminar)(14, 'csv');
    ($exportacion->alTerminar)(14, 'csv');

    verificar('Una DESCARGA repetida SÍ se anota las dos veces',
        EjecucionReporte::count() === $antesDeBajar + 2,
        $antesDeBajar.' → '.EjecucionReporte::count());
    verificar('Con el reporte y quién lo corrió',
        $ultima->reporte === 'egresados-por-generacion' && $ultima->persona_id === $global->persona_id);
    verificar('Y con los filtros EFECTIVOS, no los que se pidieron',
        ($ultima->filtros['situacion_id'] ?? null) == $conBandera,
        json_encode($ultima->filtros));

    echo PHP_EOL.'8. El registro sólo ofrece lo que la persona puede ver'.PHP_EOL;

    /*
     * Se comprueba POR CLAVE y no por CANTIDAD.
     *
     * Decía «=== 3» y se cayó en cuanto se agregó la primera fuente de
     * finanzas: un conteo fijo convierte cada reporte nuevo en una suite roja
     * que no señala ningún defecto. Lo que importa es que estén los que tiene
     * que ver, no cuántos hay.
     */
    $suyos = array_map(fn ($r) => $r->clave(), $registro->para($global));

    verificar('El director ve los reportes de matrículas',
        array_diff(['alumnos-inscritos', 'bajas-de-alumnos', 'egresados-por-generacion'], $suyos) === [],
        implode(', ', $suyos));

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

    echo PHP_EOL.'11. Las áreas se renombran y los reportes se mueven'.PHP_EOL;

    (new Database\Seeders\Tenant\AreasReporteSeeder)->run();

    $config = app(App\Http\Controllers\Reportes\ConfiguracionReportesController::class);

    $control = AreaReporte::query()->where('clave', 'control-escolar')->firstOrFail();
    $finanzas = AreaReporte::query()->where('clave', 'finanzas')->firstOrFail();

    $agrupadoAntes = $registro->agrupadosPara($global);
    $nombreAntes = collect($agrupadoAntes)->firstWhere('clave', 'control-escolar')['nombre'] ?? null;

    verificar('El índice agrupa por el área configurada',
        $nombreAntes === 'Control escolar', (string) $nombreAntes);

    // ── Renombrar: la CLAVE no se toca ──
    $config->guardarArea(peticionCon(['nombre' => 'Servicios escolares']), $control);

    $agrupado = $registro->agrupadosPara($global);
    $grupo = collect($agrupado)->firstWhere('clave', 'control-escolar');

    verificar('Renombrar el área cambia lo que se ve',
        ($grupo['nombre'] ?? null) === 'Servicios escolares', $grupo['nombre'] ?? 'null');

    verificar('Pero la CLAVE sigue siendo la del código',
        $control->fresh()->clave === 'control-escolar',
        $control->fresh()->clave);

    // ── Mover un reporte de área ──
    $config->ubicarReporte(
        peticionCon(['area_id' => $finanzas->id, 'nombre' => null, 'activo' => '1']),
        'alumnos-inscritos',
    );

    $agrupado = $registro->agrupadosPara($global);
    $enFinanzas = collect(collect($agrupado)->firstWhere('clave', 'finanzas')['reportes'] ?? [])
        ->pluck('clave')->all();

    verificar('El reporte movido aparece en su área nueva',
        in_array('alumnos-inscritos', $enFinanzas, true), implode(',', $enFinanzas));

    $enControl = collect(collect($agrupado)->firstWhere('clave', 'control-escolar')['reportes'] ?? [])
        ->pluck('clave')->all();

    verificar('Y ya no en la anterior',
        ! in_array('alumnos-inscritos', $enControl, true), implode(',', $enControl));

    /*
     * Mover NO concede acceso.
     *
     * Es lo que alguien podría suponer al arrastrar un reporte a un área
     * llamada «Dirección»: que quien entra ahí ya lo ve. El permiso lo sigue
     * decidiendo la FUENTE.
     */
    /*
     * El rol se CONSTRUYE, no se busca.
     *
     * La primera version usaba el rol `administrativo` «si no tiene
     * ver-alumnos», y en esta escuela SI lo tiene: la comprobacion se saltaba en
     * silencio y la prueba pasaba sin probar nada. Aqui se crea un rol funcional
     * colgado de la faceta administrativa y sin un solo permiso.
     */
    $facetaAdmin = Rol::query()->where('name', 'administrativo')->firstOrFail();

    $rolPelado = Rol::query()->create([
        'name' => 'prueba_sin_permisos_'.random_int(1000, 9999),
        'nombre' => 'Rol de prueba sin permisos',
        'guard_name' => 'web',
        'rol_padre_id' => $facetaAdmin->id,
    ]);

    /*
     * Y se le retira `ver-alumnos` a la FACETA, dentro de la transaccion.
     *
     * Un rol funcional HEREDA los permisos de su faceta --asi esta disenado--,
     * asi que crear uno pelado no basta: seguia viendo alumnos por herencia. Lo
     * deshace el rollback.
     */
    $facetaAdmin->revokePermissionTo('ver-alumnos');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $sinAlumnos = usuarioConRol($rolPelado->name);

    verificar('El rol construido de verdad NO ve alumnos',
        ! $sinAlumnos->can('ver-alumnos'));

    $claves = collect($registro->agrupadosPara($sinAlumnos))
        ->flatMap(fn ($g) => collect($g['reportes'])->pluck('clave'))
        ->all();

    verificar('Mover un reporte de área NO concede acceso a sus datos',
        ! in_array('alumnos-inscritos', $claves, true), implode(',', $claves));

    // ── Renombrar el reporte, y volver al del código ──
    $config->ubicarReporte(
        peticionCon(['area_id' => $finanzas->id, 'nombre' => 'Padrón vigente', 'activo' => '1']),
        'alumnos-inscritos',
    );

    $titulo = collect(collect($registro->agrupadosPara($global))->firstWhere('clave', 'finanzas')['reportes'])
        ->firstWhere('clave', 'alumnos-inscritos')['titulo'] ?? null;

    verificar('Un reporte se puede renombrar localmente', $titulo === 'Padrón vigente', (string) $titulo);

    $config->ubicarReporte(
        peticionCon(['area_id' => $finanzas->id, 'nombre' => '', 'activo' => '1']),
        'alumnos-inscritos',
    );

    // Vacío se guarda como NULL, que significa «el título de la clase»: así un
    // reporte renombrado en el código se sigue actualizando solo.
    verificar('Dejarlo en blanco vuelve al título del código',
        UbicacionReporte::query()->where('reporte', 'alumnos-inscritos')->value('nombre') === null);

    // ── Apagar el área esconde lo que tiene dentro ──
    $config->alternarArea(peticionCon(['activo' => '0']), $finanzas);

    $claves = collect($registro->agrupadosPara($global))
        ->flatMap(fn ($g) => collect($g['reportes'])->pluck('clave'))
        ->all();

    verificar('Un área apagada esconde sus reportes',
        ! in_array('alumnos-inscritos', $claves, true), implode(',', $claves));

    verificar('Pero no borra nada',
        UbicacionReporte::query()->where('reporte', 'alumnos-inscritos')->exists());

    // ── Un área con reportes NO se borra ──
    $config->alternarArea(peticionCon(['activo' => '1']), $finanzas);

    $estadoBorrar = null;

    try {
        $config->eliminarArea($finanzas);
    } catch (AvisoParaElUsuario $e) {
        $estadoBorrar = $e->getStatusCode();
    }

    verificar('Un área con reportes dentro no se elimina', $estadoBorrar === 422);
    verificar('Y sigue ahí', AreaReporte::query()->whereKey($finanzas->id)->exists());

    // ── UNA sola ubicación por reporte ──
    $config->ubicarReporte(peticionCon(['area_id' => $control->id, 'nombre' => null, 'activo' => '1']), 'alumnos-inscritos');

    verificar('Un reporte vive en UNA sola área',
        UbicacionReporte::query()->where('reporte', 'alumnos-inscritos')->count() === 1,
        (string) UbicacionReporte::query()->where('reporte', 'alumnos-inscritos')->count());

    echo PHP_EOL.'12. Una vista guarda CONFIGURACIÓN, no filas'.PHP_EOL;

    $vistas = app(VistaReporteController::class);

    auth()->login($global);

    // El global guarda una vista SIN filtro de campus: la suya ve toda la escuela.
    $vistas->guardar(peticionCon([
        'nombre' => 'Padrón completo',
        'columnas' => ['matricula', 'alumno', 'campus'],
        'orden_dir' => 'asc',
        'de_la_escuela' => '1',
    ]), 'alumnos-inscritos');

    $vista = VistaReporte::query()->where('nombre', 'Padrón completo')->firstOrFail();

    verificar('La vista guarda columnas y filtros, no filas',
        $vista->columnas === ['matricula', 'alumno', 'campus'] && $vista->persona_id === null);

    /*
     * ── LA PRUEBA CENTRAL: compartir una vista no comparte datos ──────────
     *
     * La misma vista, guardada por quien ve toda la escuela, ejecutada por el
     * coordinador de un campus: tiene que devolver SÓLO lo suyo. Si el motor
     * arrastrara el alcance del dueño, compartir una vista sería una fuga —y
     * una que nadie notaría, porque el reporte «funciona»—.
     */
    $comoGlobal = $ejecutor->ejecutar($global, 'alumnos-inscritos', [
        'columnas' => $vista->columnas,
        'filtros' => $vista->filtros ?? [],
    ]);

    auth()->login($acotado);

    $comoAcotado = $ejecutor->ejecutar($acotado, 'alumnos-inscritos', [
        'columnas' => $vista->columnas,
        'filtros' => $vista->filtros ?? [],
    ]);

    verificar('La misma vista da menos filas a quien ve menos',
        $comoAcotado->total() < $comoGlobal->total(),
        "acotado {$comoAcotado->total()}, global {$comoGlobal->total()}");

    $campusVistos = collect($comoAcotado->filas)->pluck('campus')->unique()->filter()->values();

    verificar('Y sólo de SU campus: compartir una vista no comparte datos',
        $campusVistos->count() <= 1 && ($campusVistos->first() ?? $nombreCampus) === $nombreCampus,
        $campusVistos->implode(', '));

    auth()->login($global);

    echo PHP_EOL.'13. Una vista vieja sobrevive a que cambie el catálogo'.PHP_EOL;

    // Se guarda una vista que nombra una columna que NO existe --como una vista
    // de hace un año cuya columna se retiró del código--.
    $vistas->guardar(peticionCon([
        'nombre' => 'Vista antigua',
        'columnas' => ['matricula', 'columna-retirada-hace-un-anio', 'alumno'],
    ]), 'alumnos-inscritos');

    $antigua = VistaReporte::query()->where('nombre', 'Vista antigua')->firstOrFail();

    verificar('Se guarda tal cual, sin sanear al escribir',
        in_array('columna-retirada-hace-un-anio', $antigua->columnas, true));

    $conAntigua = $ejecutor->ejecutar($global, 'alumnos-inscritos', ['columnas' => $antigua->columnas]);

    // Abre igual, SIN esa columna, en vez de reventar: una vista guardada hace
    // un año no puede dejar de funcionar porque el catálogo evolucione.
    verificar('Se ejecuta igual, descartando la columna que ya no existe',
        array_map(fn ($c) => $c->clave, $conAntigua->columnas) === ['matricula', 'alumno'],
        implode(',', array_map(fn ($c) => $c->clave, $conAntigua->columnas)));

    echo PHP_EOL.'14. De quién es cada vista'.PHP_EOL;

    // Una vista de la ESCUELA sólo la crea quien organiza.
    $estadoEscuela = null;

    try {
        $vistas->guardar(peticionCon([
            'nombre' => 'Intento sin permiso',
            'de_la_escuela' => '1',
        ]), 'alumnos-inscritos');
    } catch (AvisoParaElUsuario $e) {
        $estadoEscuela = $e->getStatusCode();
    }

    // El global SÍ organiza, así que para probarlo hace falta alguien que no.
    $sinOrganizar = usuarioConRol($rolPelado->name);
    auth()->login($sinOrganizar);

    $estadoEscuela = null;

    try {
        $vistas->guardar(peticionCon([
            'nombre' => 'Intento sin permiso',
            'de_la_escuela' => '1',
        ]), 'alumnos-inscritos');
    } catch (AvisoParaElUsuario $e) {
        $estadoEscuela = $e->getStatusCode();
    }

    verificar('Sin permiso de organizar no se crea una vista de la escuela',
        $estadoEscuela === 403, (string) $estadoEscuela);

    // Y la vista de otro no se borra.
    $estadoBorrar = null;

    try {
        $vistas->eliminar(peticionCon([]), $antigua);
    } catch (AvisoParaElUsuario $e) {
        $estadoBorrar = $e->getStatusCode();
    }

    verificar('La vista de otra persona no se elimina', $estadoBorrar === 403);
    verificar('Y sigue ahí', VistaReporte::query()->whereKey($antigua->id)->exists());

    /*
     * Y ni siquiera se VE.
     *
     * No basta con que no se pueda borrar: si apareciera en la lista, cualquiera
     * sabría qué reportes guarda cada quien y con qué nombres --y un nombre como
     * «bajas por impago del campus norte» ya dice algo--. Faltaba esta
     * comprobación: al quitar el filtro del scope, la suite seguía en verde.
     */
    $vistaPropia = VistaReporte::query()->create([
        'reporte' => 'alumnos-inscritos',
        'nombre' => 'Sólo mía, de nadie más',
        'persona_id' => $sinOrganizar->persona_id,
    ]);

    $visiblesParaOtro = VistaReporte::query()
        ->where('reporte', 'alumnos-inscritos')
        ->visiblesPara($global)
        ->pluck('nombre');

    verificar('La vista privada de otra persona NO aparece en la lista',
        ! $visiblesParaOtro->contains('Sólo mía, de nadie más'),
        $visiblesParaOtro->implode(', '));

    verificar('Pero su dueño sí la ve',
        VistaReporte::query()->where('reporte', 'alumnos-inscritos')->visiblesPara($sinOrganizar)
            ->pluck('nombre')->contains('Sólo mía, de nadie más'));

    // Y la de la ESCUELA la ven los dos: es lo que la hace compartida.
    verificar('La vista de la escuela la ven todos',
        VistaReporte::query()->where('reporte', 'alumnos-inscritos')->visiblesPara($sinOrganizar)
            ->pluck('nombre')->contains('Padrón completo'));

    auth()->login($global);

    echo PHP_EOL.'15. Predeterminada: una sola, y favoritos'.PHP_EOL;

    foreach (['Primera propia', 'Segunda propia'] as $nombre) {
        $vistas->guardar(peticionCon([
            'nombre' => $nombre,
            'columnas' => ['matricula'],
            'predeterminada' => '1',
        ]), 'alumnos-inscritos');
    }

    $predeterminadas = VistaReporte::query()
        ->where('reporte', 'alumnos-inscritos')
        ->where('persona_id', $global->persona_id)
        ->where('predeterminada', true)
        ->pluck('nombre');

    verificar('Sólo queda UNA predeterminada por persona',
        $predeterminadas->count() === 1, $predeterminadas->implode(', '));

    verificar('Y es la última que se marcó',
        $predeterminadas->first() === 'Segunda propia', (string) $predeterminadas->first());

    // Favoritos: marcar y desmarcar por la misma puerta.
    $vistas->favorito(peticionCon([]), 'alumnos-inscritos');

    verificar('Se marca el favorito',
        ReporteFavorito::query()->where('persona_id', $global->persona_id)->where('reporte', 'alumnos-inscritos')->exists());

    $vistas->favorito(peticionCon([]), 'alumnos-inscritos');

    verificar('Y se desmarca por la misma puerta',
        ! ReporteFavorito::query()->where('persona_id', $global->persona_id)->where('reporte', 'alumnos-inscritos')->exists());

    // Dos clics seguidos no revientan contra el índice único.
    $vistas->favorito(peticionCon([]), 'alumnos-inscritos');
    ReporteFavorito::query()->firstOrCreate(['persona_id' => $global->persona_id, 'reporte' => 'alumnos-inscritos']);

    verificar('Marcarlo dos veces no duplica ni revienta',
        ReporteFavorito::query()->where('persona_id', $global->persona_id)->where('reporte', 'alumnos-inscritos')->count() === 1);

    echo PHP_EOL.'16. El recorrido DESCENDENTE y con NULLs no pierde filas'.PHP_EOL;

    auth()->login($global);

    /*
     * Los dos defectos que esto vigila son de EXPORTACION, no de pantalla, y
     * ninguno da error: producen un archivo que abre perfectamente con filas
     * repetidas o de menos.
     *
     *  (1) El desempate del ORDER BY iba sin direccion --o sea ASC fijo--,
     *      mientras el cursor del keyset avanza con la del reporte. Con
     *      `col DESC, id ASC` y empates en la frontera de un lote, se repiten
     *      filas y se saltan otras.
     *  (2) En MySQL `(3,2) > (null,1)` no es falso: es NULL, y una condicion
     *      NULL descarta la fila. Con la columna de orden nulable, el recorrido
     *      se truncaba en silencio.
     *
     * Los dos se disparan por el camino POR OMISION de «Egresados por
     * generacion», que ordena `['generacion', 'desc']` sobre una columna
     * nullable. El demo no los exhibe porque no tiene generaciones en null ni
     * pasa de un lote.
     */
    $sinGeneracion = MatriculaOferta::query()
        ->whereIn('situacion_id', $conBandera)
        ->limit(14)
        ->pluck('id');

    verificar('Hay egresados suficientes para partir en lotes',
        $sinGeneracion->count() >= 12, (string) $sinGeneracion->count());

    /*
     * MÁS NULOS QUE EL TAMAÑO DEL LOTE, y eso es la prueba entera.
     *
     * Con 4 nulos y lotes de 5 el cursor NUNCA termina un lote dentro del
     * bloque de nulos: el primero se lleva los 4 y una fila con valor, así que
     * `$ultimo['orden']` sale con valor y la rama del NULL no se ejercita. La
     * primera versión de esta comprobación tenía justo eso y pasaba en las dos
     * direcciones con el defecto de ASC vivo — la cuarta prueba de este
     * proyecto que pasa por la razón equivocada.
     *
     * Con 8 nulos y lotes de 5, el primer lote acaba DENTRO del bloque nulo,
     * que es donde el predicado tiene que decidir si lo que sigue son más nulos
     * (DESC: van al final) o las filas con valor (ASC: van después).
     */
    $cuantosNulos = 8;

    verificar('Los nulos son MÁS que el lote (si no, la rama del NULL no se ejercita)',
        $cuantosNulos > 5, $cuantosNulos.' nulos, lotes de 5');

    MatriculaOferta::query()->whereIn('id', $sinGeneracion->take($cuantosNulos))->update(['generacion' => null]);

    $totalEgresados = $ejecutorLotesChicos->paraExportar($global, 'egresados-por-generacion')->total;

    foreach (['desc', 'asc'] as $direccion) {
        $exportacion = $ejecutorLotesChicos->paraExportar($global, 'egresados-por-generacion', [
            'columnas' => ['matricula', 'generacion'],
            'orden_por' => 'generacion',
            'orden_dir' => $direccion,
        ]);

        $filas = iterator_to_array($exportacion->recorrer(), false);
        $matriculas = array_column($filas, 'matricula');

        verificar("Orden {$direccion}: salen TODAS las filas, ninguna de menos",
            count($filas) === $totalEgresados,
            count($filas).' de '.$totalEgresados);

        verificar("Orden {$direccion}: ninguna fila repetida",
            count(array_unique($matriculas)) === count($matriculas),
            (count($matriculas) - count(array_unique($matriculas))).' repetidas');

        // Las que no tienen generación también salen: en DESC van al final y
        // eran justo las que desaparecían.
        $conNulo = array_filter($filas, fn (array $f) => blank($f['generacion']));

        verificar("Orden {$direccion}: las que no tienen generación también salen",
            count($conNulo) === $cuantosNulos, count($conNulo).' de '.$cuantosNulos);
    }

    echo PHP_EOL.'17. Lo que destapó la revisión adversaria'.PHP_EOL;

    /*
     * Una celda no puede convertirse en FÓRMULA al abrir el archivo.
     *
     * Excel interpreta como fórmula lo que empieza por = + - @, y un reporte
     * escolar está lleno de texto que escribió alguien de fuera: el nombre de un
     * aspirante que llegó por el formulario público. Un
     * `=HYPERLINK("http://…"&A2)` en el archivo que control escolar le manda a
     * la SEP se dispara solo al abrirlo, y nada en el archivo lo delata.
     */
    foreach (['=1+1', '+A1', '-2', '@SUM(A1)'] as $peligroso) {
        verificar("«{$peligroso}» sale neutralizado, no como fórmula",
            str_starts_with(TextoDeCelda::neutralizado($peligroso), "'"),
            TextoDeCelda::neutralizado($peligroso));
    }

    // Y el dato sigue siendo el dato: no se mutila nada normal.
    verificar('Un texto normal no se toca',
        TextoDeCelda::neutralizado('Leonardo Díaz') === 'Leonardo Díaz');

    /*
     * Compartirle una vista a un ROL es compartir en pequeño.
     *
     * Sin comprobarlo, cualquiera con `ver-reportes` le plantaba una vista al
     * rol que quisiera —y era el único que podía quitarla, porque el dueño
     * seguía siendo él—.
     */
    auth()->login($sinOrganizar);

    $estadoRol = null;

    try {
        $vistas->guardar(peticionCon([
            'nombre' => 'Colada en otro rol',
            'rol_id' => (string) Rol::query()->where('name', 'director_general')->value('id'),
        ]), 'alumnos-inscritos');
    } catch (AvisoParaElUsuario $e) {
        $estadoRol = $e->getStatusCode();
    }

    verificar('Sin permiso de organizar no se le planta una vista a un rol',
        $estadoRol === 403, (string) $estadoRol);

    auth()->login($global);

    /*
     * La cuenta de un área tiene que incluir los reportes que viven ahí POR
     * OMISIÓN, no sólo los movidos: si no, un área recién sembrada figura con
     * cero, se ofrece el botón de eliminar y borrarla deja sus reportes sin
     * sitio.
     */
    UbicacionReporte::query()->forceDelete();

    // La respuesta de Inertia sólo entrega JSON si la petición lo pide: sin la
    // cabecera devuelve el HTML de la SPA y las props no se ven.
    $comoInertia = Illuminate\Http\Request::create('/reportes/configuracion', 'GET');
    $comoInertia->headers->set('X-Inertia', 'true');
    $comoInertia->headers->set('X-Inertia-Version', '');
    $comoInertia->setUserResolver(fn () => auth()->user());

    $props = json_decode(
        $config->index($comoInertia)->toResponse($comoInertia)->getContent(),
        true
    )['props'];

    $controlEscolar = collect($props['areas'])->firstWhere('clave', 'control-escolar');

    /*
     * Se compara contra el REGISTRO, no contra un número escrito.
     *
     * Decía «=== 3» y se cayó en cuanto se agregó la primera fuente de control
     * escolar: es la segunda comprobación de esta suite que un conteo fijo
     * convierte en roja sin señalar ningún defecto. Lo que se vigila es que la
     * cuenta incluya los reportes que viven ahí POR OMISIÓN, y eso se mide
     * contra lo que el registro declara.
     */
    $deControlEscolar = count(array_filter(
        $registro->todos(),
        fn ($r) => $r->areaSugerida() === 'control-escolar',
    ));

    verificar('Un área cuenta los reportes que viven en ella por omisión',
        ($controlEscolar['cuantos'] ?? 0) === $deControlEscolar,
        'cuenta '.($controlEscolar['cuantos'] ?? 'null').' y el registro declara '.$deControlEscolar);

    /*
     * El área vacía se BUSCA, no se nombra.
     *
     * Decía «movilidad» y se cayó en cuanto esa área tuvo reportes: es la
     * tercera comprobación de esta suite que un nombre o un número escrito a
     * mano pone en rojo sin señalar ningún defecto. Lo que se vigila es que un
     * área sin reportes cuente cero, no cuál es esa área.
     */
    $conReportes = array_unique(array_map(fn ($r) => $r->areaSugerida(), $registro->todos()));

    $vacia = collect($props['areas'])->first(fn (array $a) => ! in_array($a['clave'], $conReportes, true));

    verificar('Hay un área sin reportes para comprobarlo',
        $vacia !== null, $vacia['clave'] ?? 'todas tienen reportes');

    verificar('Y un área de verdad vacía sigue contando cero',
        ($vacia['cuantos'] ?? null) === 0,
        ($vacia['clave'] ?? '?').' cuenta '.($vacia['cuantos'] ?? 'null'));

    echo PHP_EOL.'18. Una cuenta sin rol activo no ejecuta nada'.PHP_EOL;

    /*
     * Quién lo detiene: el PERMISO, no la faceta.
     *
     * Se llegó aquí sospechando un fail-open en la comprobación de faceta
     * (`$faceta !== null && ...`). Medido, la rama es inalcanzable:
     * `Rol::faceta()` devuelve `self` y nunca null, así que sólo vale null sin
     * rol activo — y sin rol activo el `Gate::before` no concede ningún permiso
     * y `can()` ya negó. Cerrar la faceta con `facetas() !== []` no tumbaba
     * ninguna comprobación, así que se retiró.
     *
     * La comprobación se queda porque el HECHO sí importa y no estaba fijado en
     * ningún sitio: una cuenta a la que se le quitó el rol activo no puede
     * seguir descargando el padrón de la escuela. Da 403 —lo niega el permiso—
     * y ésa es la respuesta correcta.
     */
    $sinRol = usuarioConRol('director_general');
    // `rolActivo` es un `belongsTo` sobre `usuarios.rol_activo_id`, no la
    // bandera del pivote: apagar `persona_rol.activo` no lo vacía.
    DB::table('usuarios')->where('id', $sinRol->id)->update(['rol_activo_id' => null]);

    $sinRol = Usuario::find($sinRol->id);

    verificar('El usuario de prueba quedó sin rol activo (si no, la prueba sería vacua)',
        $sinRol->rolActivo === null, $sinRol->rolActivo?->name ?? 'null');

    $estadoFaceta = null;

    try {
        $ejecutor->ejecutar($sinRol, 'alumnos-inscritos');
    } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
        $estadoFaceta = 404;
    } catch (AvisoParaElUsuario $e) {
        $estadoFaceta = $e->getStatusCode();
    }

    verificar('Sin rol activo no se ejecuta ningún reporte',
        $estadoFaceta !== null, $estadoFaceta === null ? 'lo ejecutó' : (string) $estadoFaceta);

    echo PHP_EOL.'19. Un filtro booleano llega de la pantalla como CADENA'.PHP_EOL;

    /*
     * Validar NO es convertir, y la diferencia daba un 500 en cada casilla.
     *
     * La regla `boolean` de Laravel acepta la cadena «1» —que es lo que manda
     * una casilla marcada— pero devuelve el valor tal cual, así que a la closure
     * del filtro, tipada `bool $v`, le llegaba un string y reventaba con
     * TypeError.
     *
     * Ninguna suite lo veía porque todas pasaban booleanos de PHP, que es lo que
     * escribe un `filtrosFijos()`. Ésta manda LO QUE MANDA EL NAVEGADOR, que es
     * lo único que lo caza. Se recorren TODOS los filtros booleanos de TODOS los
     * reportes: es un defecto de clase.
     */
    $rotos = [];
    $probados = 0;

    foreach ($registro->todos() as $reporte) {
        $fuente = $registro->fuente($reporte->fuente());

        foreach ($fuente->filtros() as $clave => $filtro) {
            if ($filtro->tipo !== App\Reportes\TipoFiltro::Booleano) {
                continue;
            }

            // Las dos formas que manda la pantalla: marcada y sin marcar.
            foreach (['1', '0'] as $comoLoMandaLaPantalla) {
                try {
                    $ejecutor->ejecutar($global, $reporte->clave(), [
                        'filtros' => [$clave => $comoLoMandaLaPantalla],
                    ]);

                    $probados++;
                } catch (\TypeError $e) {
                    $rotos[] = $reporte->clave().'/'.$clave.'="'.$comoLoMandaLaPantalla.'": TypeError';
                } catch (\Throwable $e) {
                    // Un reporte que exige otro filtro se niega, y eso es
                    // correcto: no es el defecto que se busca.
                    if (! $e instanceof AvisoParaElUsuario) {
                        $rotos[] = $reporte->clave().'/'.$clave.': '.class_basename($e);
                    }
                }
            }
        }
    }

    verificar('Hay filtros booleanos que probar', $probados > 0, $probados.' combinaciones');

    verificar('Ninguno revienta al recibir la cadena que manda la pantalla',
        $rotos === [], $rotos === [] ? $probados.' combinaciones, ninguna rota' : implode(' | ', array_slice($rotos, 0, 3)));

    /*
     * Y «0» de verdad APAGA el filtro, en vez de encenderlo por ser una cadena
     * no vacía. Es el otro lado del mismo defecto: `(bool) '0'` es false en PHP,
     * pero `'0'` a secas dentro de un `if` también — lo que fallaría es una
     * conversión escrita a mano con `(bool)` sobre «false» o «off».
     */
    $conCero = collect($ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula'],
        'filtros' => ['solo_con_saldo' => '0'],
    ])->filas);

    $sinFiltro = collect($ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula'],
    ])->filas);

    verificar('Un booleano en «0» deja el reporte igual que sin filtro',
        $conCero->count() === $sinFiltro->count(),
        $conCero->count().' vs '.$sinFiltro->count());

    $conUno = collect($ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula'],
        'filtros' => ['solo_con_saldo' => '1'],
    ])->filas);

    verificar('Y en «1» sí acota',
        $conUno->count() < $sinFiltro->count(),
        $conUno->count().' de '.$sinFiltro->count());

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

} finally {
    $db->rollBack();
}
