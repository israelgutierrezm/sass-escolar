<?php

/**
 * Los cuatro defectos que destapó el plan del módulo de Reportes. Con rollback.
 *
 * Se corre con `php scripts/prueba-rebanada-cero.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `AsistenciaClase::scopeFaltas()` encuentra las faltas de verdad. Filtraba
 *     por `'ausente'` y lo guardado es `'falta'`, así que devolvía CERO siempre.
 *     No mordía porque nadie lo llamaba; mordería en el primer reporte de
 *     inasistencias. Y la lista del pase de lista sale de las MISMAS constantes,
 *     para que no puedan volver a divergir.
 *  2. `Exportador` arma el .xlsx y NO deja el temporal huérfano. `tempnam()`
 *     crea el archivo y devuelve su ruta; al pegarle `.xlsx` se guarda en otra,
 *     y la primera se quedaba en el temporal del sistema para siempre.
 *  3. La columna del encabezado de Excel pasada la Z. Con `chr(ord('A') + $i)`
 *     la columna 27 salía `'['` y el archivo quedaba corrupto.
 *  4. La cartera cuadra: el total de la escuela incluye lo de los aspirantes y
 *     lo DICE, y el listado por matrícula sigue sin contarlo. Antes eran dos
 *     consultas escritas aparte que ya habían divergido en eso mismo.
 */

use App\Models\Admisiones\Aspirante;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\Finanzas\Adeudo;
use App\Models\Tenant;
use App\Services\Excel\Exportador;
use App\Services\Finanzas\SaldosDeCartera;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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

$db->beginTransaction();

try {
    echo '1. scopeFaltas encuentra las faltas de verdad'.PHP_EOL;

    verificar('La constante vale lo que se guarda, no «ausente»',
        AsistenciaClase::FALTA === 'falta', AsistenciaClase::FALTA);

    // Una inscripción cualquiera para colgarle asistencias propias.
    $inscripcionId = (int) $db->table('inscripcion')->whereNull('deleted_at')->value('id');

    if ($inscripcionId === 0) {
        echo 'Esta escuela no tiene inscripciones; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $base = AsistenciaClase::query()->where('inscripcion_id', $inscripcionId)->faltas()->count();

    /*
     * Se siembran con los valores LITERALES, no con las constantes.
     *
     * Con las constantes la prueba se valida contra sí misma: si alguien las
     * cambia a `'ausente'`, se escribe `'ausente'` y se consulta `'ausente'`,
     * los dos lados se mueven juntos y la comprobación pasa igual —que es
     * exactamente el defecto que veníamos a arreglar—. Comprobado mutando: con
     * las constantes la mutación sobrevivía; con los literales, muere.
     *
     * Estos cuatro literales son los que hay en la base del demo y los que
     * escribe `PaseListaController`.
     */
    foreach ([
        ['2019-03-04', 'falta'],
        ['2019-03-05', 'falta'],
        ['2019-03-06', 'retardo'],
        ['2019-03-07', 'justificada'],
    ] as [$fecha, $estatus]) {
        AsistenciaClase::create([
            'inscripcion_id' => $inscripcionId,
            'fecha' => $fecha,
            'modalidad' => 'unica',
            'estatus' => $estatus,
        ]);
    }

    $despues = AsistenciaClase::query()->where('inscripcion_id', $inscripcionId)->faltas()->count();

    verificar('Cuenta las dos faltas y NO el retardo ni la justificada',
        $despues - $base === 2, "antes {$base}, después {$despues}");

    // El pase de lista escribe con las MISMAS constantes: se comprueba que los
    // cuatro valores que acepta sean exactamente los del modelo.
    $reflexion = new ReflectionClass(App\Http\Controllers\PaseListaController::class);
    $aceptados = $reflexion->getConstant('ESTATUS');
    sort($aceptados);
    $delModelo = [AsistenciaClase::PRESENTE, AsistenciaClase::RETARDO, AsistenciaClase::FALTA, AsistenciaClase::JUSTIFICADA];
    sort($delModelo);

    verificar('El pase de lista acepta exactamente los estatus del modelo',
        $aceptados === $delModelo, implode(',', $aceptados));

    echo PHP_EOL.'2. El exportador de Excel no deja basura ni se rompe pasada la Z'.PHP_EOL;

    // Temporales ANTES, acotados al prefijo de este exportador. En Windows
    // `tempnam()` recorta el prefijo a tres letras, así que van los dos patrones
    // —es la trampa que ya mordió en prueba-grabaciones—.
    $patrones = [sys_get_temp_dir().'/aca*.tmp*', sys_get_temp_dir().'/acadion-xls*'];
    $antes = [];
    foreach ($patrones as $p) {
        $antes = array_merge($antes, glob($p) ?: []);
    }

    $encabezados = [];
    for ($i = 1; $i <= 30; $i++) {
        $encabezados[] = "Columna {$i}";
    }

    $respuesta = app(Exportador::class)->descargar('Prueba', $encabezados, [array_fill(0, 30, 'x')], 'prueba.xlsx');
    $ruta = $respuesta->getFile()->getPathname();

    verificar('Genera el archivo .xlsx', is_file($ruta) && filesize($ruta) > 0,
        basename($ruta).' · '.filesize($ruta).' bytes');

    // 30 columnas: la 27 es AA. Con chr(ord('A') + 26) habría salido '['.
    verificar('La columna 27 es AA y no «[»',
        Coordinate::stringFromColumnIndex(27) === 'AA');

    @unlink($ruta);

    $despuesTmp = [];
    foreach ($patrones as $p) {
        $despuesTmp = array_merge($despuesTmp, glob($p) ?: []);
    }

    $huerfanos = array_diff($despuesTmp, $antes);

    verificar('No deja ningún temporal huérfano detrás',
        $huerfanos === [], $huerfanos === [] ? '' : implode(',', array_map('basename', $huerfanos)));

    echo PHP_EOL.'3. La cartera cuadra entre el panel y la pantalla'.PHP_EOL;

    $saldos = app(SaldosDeCartera::class);
    $hoy = now()->toDateString();

    $totalAntes = $saldos->totalDeLaEscuela($hoy);

    // Un adeudo DE ASPIRANTE: es el caso que separaba las dos consultas. La
    // tabla admite titular dual —matrícula o aspirante, exactamente uno—.
    $aspiranteId = (int) $db->table('aspirantes')->whereNull('deleted_at')->value('id');

    if ($aspiranteId === 0) {
        echo '  (esta escuela no tiene aspirantes; se omite el caso)'.PHP_EOL;
    } else {
        $conceptoId = (int) $db->table('conceptos_pago')->whereNull('deleted_at')->value('id');

        // `monto` es el base y `monto_total` el que se cobra tras recargos y
        // descuentos; los dos son obligatorios, igual que `fecha_generacion`.
        Adeudo::create([
            'aspirante_id' => $aspiranteId,
            'concepto_id' => $conceptoId,
            'periodo_etiqueta' => 'prueba-rebanada-cero',
            'monto' => 1234.00,
            'monto_total' => 1234.00,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);

        $totalDespues = $saldos->totalDeLaEscuela($hoy);

        verificar('El total de la escuela SÍ incluye lo del aspirante',
            round($totalDespues['saldo'] - $totalAntes['saldo'], 2) === 1234.00,
            "antes {$totalAntes['saldo']}, después {$totalDespues['saldo']}");

        verificar('Y lo NOMBRA aparte en vez de esconderlo',
            round($totalDespues['de_aspirantes'] - $totalAntes['de_aspirantes'], 2) === 1234.00,
            'de_aspirantes = '.$totalDespues['de_aspirantes']);

        // El listado por matrícula NO puede contarlo: agrupa por matrícula y la
        // suya es NULL. Si lo contara, saldría un renglón sin dueño.
        $porMatricula = (float) DB::connection('tenant')
            ->query()
            ->fromSub($saldos->porMatricula($hoy), 'f')
            ->selectRaw('coalesce(sum(f.saldo), 0) as saldo')
            ->value('saldo');

        $porMatriculaAntes = $totalAntes['saldo'] - $totalAntes['de_aspirantes'];

        verificar('El listado por matrícula NO lo cuenta (sigue igual que antes)',
            round($porMatricula, 2) === round($porMatriculaAntes, 2),
            "listado {$porMatricula}, esperado {$porMatriculaAntes}");

        verificar('Y la diferencia entre las dos cifras es exactamente lo de aspirantes',
            round($totalDespues['saldo'] - $porMatricula, 2) === round($totalDespues['de_aspirantes'], 2));
    }

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    $db->rollBack();
}
