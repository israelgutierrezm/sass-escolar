<?php

/**
 * Pagar TODO lo pendiente de una vez, y el interruptor que lo habilita.
 *
 * `php scripts/prueba-pago-total.php` desde la raíz. Contra la BD real del
 * tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * La escuela puede apagar «pagar todo de una vez». Apagado, un cobro de
 * autoservicio que cubra TODOS los cargos abiertos se rehúsa cuando hay dos o
 * más: hay que elegir un subconjunto y dejar al menos uno para otro movimiento.
 * La comprobación es la capacidad de verdad —el panel sólo la refleja—, así que
 * se prueba invocando el SERVICIO, que es por donde entra el POST.
 *
 * Un solo cargo abierto nunca cae en la regla —pagar tu única deuda no es
 * «pagar todo de una vez»—, y el abono parcial no es una salida —elegir los
 * mismos cargos y abonar sigue tocándolos todos en un movimiento—.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\CobroEnLineaController;
use App\Http\Controllers\FinanzasController;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\MatriculadorOferta;
use App\Services\Pagos\CobroEnLinea;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

// Sin salir a internet: el modo `fake` recorre el flujo con una pasarela de
// mentira. La regla que se prueba corre ANTES de la pasarela de todos modos.
config(['pagos.modo' => 'fake']);

$ok = 0;
$fallos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;

    if ($condicion) {
        $ok++;
        echo "  OK    {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Invoca `iniciar` y devuelve el código HTTP del aviso, o 0 si no hubo. */
function codigoDe(callable $accion): int
{
    try {
        $accion();

        return 0;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }
}

DB::beginTransaction();

try {
    $cobro = app(CobroEnLinea::class);
    $ajustes = app(Ajustes::class);

    echo '1. El interruptor nace encendido'.PHP_EOL;

    // Sin fila en la tabla, el valor es el por omisión declarado en el catálogo.
    $ajustes->olvidar();
    verificar('Por omisión se puede pagar todo de una vez', $ajustes->bool(CatalogoAjustes::PAGO_TOTAL) === true);

    echo PHP_EOL.'2. El escenario: una matrícula con dos cargos abiertos'.PHP_EOL;

    $persona = Persona::create(['nombre' => 'Pago', 'primer_apellido' => 'Total', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $constancia = ConceptoPago::where('clave', 'constancia')->firstOrFail();

    $cargar = function (ConceptoPago $concepto, float $monto, ?string $periodo = null) use ($matricula) {
        return Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $concepto->id,
            'monto' => $monto, 'monto_total' => $monto, 'periodo_etiqueta' => $periodo,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);
    };

    $marzo = $cargar($colegiatura, 1500.00, 'Marzo 2026');
    $abril = $cargar($colegiatura, 1500.00, 'Abril 2026');

    verificar('Tiene dos cargos abiertos', $matricula->adeudos()->porCobrar()->count() === 2);

    $iniciar = fn (array $ids, ?float $importe = null) => $cobro->iniciar(
        $matricula, 'stripe', $ids, 'https://x/retorno', 'https://x/aviso', null, $importe,
    );

    echo PHP_EOL.'3. Encendido: pagar TODO se permite'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::PAGO_TOTAL => true]);
    $intencion = $iniciar([$marzo->id, $abril->id]);

    verificar('Se abrió el cobro de los dos cargos', $intencion instanceof IntencionCobro);
    verificar('Y cubre los dos', count($intencion->adeudo_ids ?? []) === 2, implode(',', $intencion->adeudo_ids ?? []));
    verificar('Por el saldo entero', (float) $intencion->monto === 3000.00, (string) $intencion->monto);

    echo PHP_EOL.'4. Apagado: pagar TODO se rehúsa cuando hay dos o más'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::PAGO_TOTAL => false]);

    $codigo = codigoDe(fn () => $iniciar([$marzo->id, $abril->id]));
    verificar('Cubrir los dos cargos responde 422', $codigo === 422, (string) $codigo);

    echo PHP_EOL.'5. Apagado: un SUBCONJUNTO sí se puede'.PHP_EOL;

    $soloUno = $iniciar([$marzo->id]);
    verificar('Pagar un solo cargo se permite', $soloUno instanceof IntencionCobro);
    verificar('Y cubre exactamente ése', $soloUno->adeudo_ids === [$marzo->id]);

    echo PHP_EOL.'6. Apagado: el abono contra TODOS tampoco es salida'.PHP_EOL;

    // Elegir los dos y abonar menos sigue tocándolos a los dos en un movimiento.
    $codigoAbono = codigoDe(fn () => $iniciar([$marzo->id, $abril->id], 500.00));
    verificar('Abonar contra los dos responde 422', $codigoAbono === 422, (string) $codigoAbono);

    // Pero abonar contra UN cargo sí.
    $abonoUno = $iniciar([$marzo->id], 500.00);
    verificar('Abonar contra un solo cargo se permite', $abonoUno instanceof IntencionCobro);
    verificar('Y el abono es el importe pedido', (float) $abonoUno->monto === 500.00, (string) $abonoUno->monto);

    echo PHP_EOL.'7. Apagado: con UN solo cargo abierto, pagarlo se permite'.PHP_EOL;

    // Otra matrícula con un único cargo: pagar tu única deuda no es «pagar todo».
    $persona2 = Persona::create(['nombre' => 'Cargo', 'primer_apellido' => 'Unico', 'sexo_id' => 1]);
    $matricula2 = app(MatriculadorOferta::class)->matricular($persona2, Oferta::firstOrFail(), '2026-2030');
    $unico = Adeudo::create([
        'matricula_oferta_id' => $matricula2->id, 'concepto_id' => $constancia->id,
        'monto' => 232.00, 'monto_total' => 232.00,
        'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);

    $intencionUnica = $cobro->iniciar(
        $matricula2, 'stripe', [$unico->id], 'https://x/retorno', 'https://x/aviso', null, null,
    );
    verificar('El único cargo se paga aunque «pagar todo» esté apagado', $intencionUnica instanceof IntencionCobro);

    echo PHP_EOL.'8. El estado de cuenta le pasa el interruptor al panel'.PHP_EOL;

    // El panel decide con el prop `pagoTotal`. Que exista y refleje el ajuste se
    // comprueba invocando el controlador y leyendo sus props de Inertia: sin
    // eso, el interruptor viviría en la base y la pantalla no se enteraría.
    $admin = Persona::create(['nombre' => 'Caja', 'primer_apellido' => 'PagoTotal', 'sexo_id' => 1]);
    $usuario = Usuario::create([
        'persona_id' => $admin->id,
        'usuario' => 'prueba_pt_'.random_int(100000, 999999),
        'email' => 'prueba_pt_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', 'director_general')->firstOrFail()->id,
    ]);
    $admin->asignacionesRol()->create(['rol_id' => $usuario->rol_activo_id, 'activo' => true]);
    auth()->login($usuario->fresh(['rolActivo']));

    $propsDeCuenta = static function () use ($usuario, $matricula): array {
        $r = Request::create('/', 'GET');
        $r->headers->set('X-Inertia', 'true');
        $r->setUserResolver(fn () => $usuario);
        app()->instance('request', $r);

        return app(FinanzasController::class)->cuenta($r, $matricula->fresh())
            ->toResponse($r)->getData(true)['props'];
    };

    $ajustes->guardar([CatalogoAjustes::PAGO_TOTAL => true]);
    verificar('Con el interruptor encendido, pagoTotal llega en true', ($propsDeCuenta()['pagoTotal'] ?? null) === true);

    $ajustes->guardar([CatalogoAjustes::PAGO_TOTAL => false]);
    verificar('Apagado, llega en false', ($propsDeCuenta()['pagoTotal'] ?? null) === false);

    echo PHP_EOL.'9. Por el ENDPOINT real, el mensaje llega tal cual'.PHP_EOL;

    /*
     * El servicio lanza un AvisoParaElUsuario, que desciende de HttpException y
     * ésta de RuntimeException. El controlador atrapa RuntimeException para
     * culpar a la pasarela de SUS fallos, así que sin cuidado se tragaría este
     * aviso y el alumno leería «avísale a la escuela» en vez de «elige menos
     * cargos». Esto recorre el controlador y renderiza como el kernel HTTP.
     */
    $ctrl = app(CobroEnLineaController::class);
    $handler = app(Illuminate\Contracts\Debug\ExceptionHandler::class);

    $porEndpoint = static function (array $ids) use ($ctrl, $handler, $usuario, $matricula): array {
        $r = Request::create('/', 'POST', ['pasarela' => 'stripe', 'adeudo_ids' => $ids]);
        $r->headers->set('Accept', 'application/json');
        $r->setUserResolver(fn () => $usuario);
        app()->instance('request', $r);

        try {
            $resp = $ctrl->iniciar($r, $matricula->fresh());
        } catch (Throwable $e) {
            $resp = $handler->render($r, $e); // lo que hace el kernel HTTP
        }

        return [$resp->getStatusCode(), (string) $resp->getContent()];
    };

    $ajustes->guardar([CatalogoAjustes::PAGO_TOTAL => false]);

    [$cod, $cuerpo] = $porEndpoint([$marzo->id, $abril->id]);
    verificar('Cubrir todos responde 422 por el endpoint', $cod === 422, (string) $cod);
    // El cuerpo es JSON con el unicode escapado (`qué`), así que se buscan
    // subcadenas sin acentos: la regla de «pagar todos» y la ausencia del
    // mensaje genérico de la pasarela.
    verificar('Y con el mensaje de elegir cargos, no el de la pasarela',
        str_contains($cuerpo, 'pagar todos en un solo movimiento')
        && ! str_contains($cuerpo, 'No se pudo abrir el pago'));

    [$codUno, $cuerpoUno] = $porEndpoint([$marzo->id]);
    verificar('Un subconjunto responde 200 con liga', $codUno === 200 && str_contains($cuerpoUno, 'url'));

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} catch (Throwable $e) {
    $fallos[] = 'la suite murió antes de terminar';
    echo '  FALLA la suite murió antes de terminar  ['.$e::class.': '.$e->getMessage().']'.PHP_EOL;
    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} finally {
    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }

    DB::rollBack();
    tenancy()->end();
}

exit($fallos === [] ? 0 : 1);
