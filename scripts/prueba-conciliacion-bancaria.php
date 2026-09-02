<?php

/**
 * Conciliación bancaria.
 *
 * `php scripts/prueba-conciliacion-bancaria.php` desde la raíz. Contra la BD
 * real del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Lo primero, que un archivo INCOMPLETO no se pueda importar: es el defecto que
 * hace inútil todo lo demás, porque un estado de cuenta al que le faltan
 * renglones concilia impecable —lo que falta no reclama nada— y se entrega como
 * si estuviera revisado.
 *
 * Después, que reimportar no duplique PERO que dos movimientos idénticos y
 * legítimos del mismo día sigan siendo dos; que el pareo automático no decida
 * cuando hay más de un candidato; y que el efectivo no se busque en el banco
 * cobro por cobro, porque llega junto en el depósito del turno.
 *
 * ── El escenario se construye ENTERO ───────────────────────────────────────
 * El demo no tiene ni una cuenta bancaria, ni un pago, ni una caja. Lo que aquí
 * se mide es aritmética de conciliación, y eso sólo se puede afirmar sabiendo
 * exactamente qué hay.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ConciliacionBancariaController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\ConciliacionPartida;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\DepositoCaja;
use App\Models\Finanzas\EstadoCuentaBancaria;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\MovimientoBancario;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Banco\ConciliadorBancario;
use App\Services\Banco\ImportadorEstadoCuenta;
use App\Services\Banco\MapeoEstadoCuenta;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$ok = 0;
$fallos = [];
$temporales = [];

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

/** Escribe un CSV de banco de mentiras y devuelve su ruta. */
function csv(array $renglones, string $encabezado = "Fecha,Concepto,Referencia,Cargo,Abono"): string
{
    global $temporales;

    $ruta = tempnam(sys_get_temp_dir(), 'edo').'.csv';
    file_put_contents($ruta, $encabezado."\n".implode("\n", $renglones)."\n");
    $temporales[] = $ruta;

    return $ruta;
}

/** El motivo de un `AvisoParaElUsuario`, o null si no lo lanzó. */
function motivoDe(callable $accion): ?string
{
    try {
        $accion();

        return null;
    } catch (AvisoParaElUsuario $e) {
        return $e->getMessage();
    }
}

DB::beginTransaction();

try {
    $importador = app(ImportadorEstadoCuenta::class);
    $conciliador = app(ConciliadorBancario::class);
    $control = app(ConciliacionBancariaController::class);

    echo '0. Escenario'.PHP_EOL;

    $usuario = Usuario::findOrFail(1);
    Auth::login($usuario);

    $cuenta = CuentaBancaria::create([
        'nombre' => 'Cuenta de prueba', 'banco' => 'Banco de Prueba',
        'titular' => 'Escuela Demo', 'clabe' => '012345678901234567',
        'numero_cuenta' => '00112233', 'activa' => true,
        'mapeo_estado_cuenta' => [
            'delimitador' => ',',
            'renglon_encabezado' => 1,
            'formato_fecha' => 'd/m/Y',
            'columna_fecha' => 'Fecha',
            'columna_descripcion' => 'Concepto',
            'columna_referencia' => 'Referencia',
            'columna_monto' => null,
            'columna_cargo' => 'Cargo',
            'columna_abono' => 'Abono',
        ],
    ]);

    verificar('La cuenta guarda su mapeo como arreglo', is_array($cuenta->fresh()->mapeo_estado_cuenta));

    echo PHP_EOL.'1. El cuadre del saldo es lo que prueba que el archivo está completo'.PHP_EOL;

    // Tres entradas y un cargo: 2500 + 1800 + 3200 - 150 = 7350.
    $renglones = [
        '05/03/2026,SPEI RECIBIDO DE GARCIA,REF7788,,2500.00',
        '06/03/2026,SPEI RECIBIDO,REF9911,,1800.00',
        '09/03/2026,DEPOSITO EN EFECTIVO,FICHA22,,3200.00',
        '31/03/2026,COMISION POR MANEJO DE CUENTA,,150.00,',
    ];

    $motivo = motivoDe(fn () => $importador->importar(
        $cuenta, csv($renglones), '2026-03-01', '2026-03-31', 10000.00, 99999.00
    ));

    verificar('Un saldo final que no cuadra RECHAZA la importación', $motivo !== null, (string) $motivo);
    verificar('Y dice cuánto falta', str_contains((string) $motivo, 'faltan'));
    verificar('Sin dejar el estado de cuenta a medias', EstadoCuentaBancaria::query()->where('cuenta_bancaria_id', $cuenta->id)->count() === 0);

    // Un archivo al que le falta un renglón cuadra... si nadie mira los saldos.
    $motivoCorto = motivoDe(fn () => $importador->importar(
        $cuenta, csv(array_slice($renglones, 0, 3)), '2026-03-01', '2026-03-31', 10000.00, 17350.00
    ));

    verificar('Un archivo al que le falta un renglón también se rechaza', $motivoCorto !== null);

    $r = $importador->importar($cuenta, csv($renglones), '2026-03-01', '2026-03-31', 10000.00, 17350.00);
    $estado = $r['estado'];

    verificar('Con los saldos correctos entra', $r['nuevos'] === 4, "nuevos: {$r['nuevos']}");
    verificar('Y el periodo cuadra', $estado->cuadra(), 'descuadre: '.$estado->descuadre());

    echo PHP_EOL.'2. Cómo se leyó el archivo'.PHP_EOL;

    $comision = MovimientoBancario::where('estado_cuenta_id', $estado->id)->where('descripcion', 'like', 'COMISION%')->firstOrFail();
    $spei = MovimientoBancario::where('estado_cuenta_id', $estado->id)->where('referencia', 'REF7788')->firstOrFail();

    verificar('Un cargo entra en NEGATIVO', (float) $comision->monto === -150.0, (string) $comision->monto);
    verificar('Y un abono en positivo', (float) $spei->monto === 2500.0);
    verificar('El cargo no es una entrada', ! $comision->esEntrada());
    verificar('El neto del periodo es la suma con signo', abs($estado->neto() - 7350.0) < 0.005, (string) $estado->neto());

    echo PHP_EOL.'3. Reimportar no duplica, pero dos idénticos legítimos son DOS'.PHP_EOL;

    $r2 = $importador->importar($cuenta, csv($renglones), '2026-03-01', '2026-03-31', 10000.00, 17350.00);

    verificar('El mismo archivo otra vez no agrega nada', $r2['nuevos'] === 0, "nuevos: {$r2['nuevos']}");
    verificar('Y lo dice', $r2['repetidos'] === 4);
    verificar(
        'La base sigue con cuatro movimientos',
        MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->count() === 4
    );

    /*
     * Dos familias transfiriendo lo mismo, el mismo día, con la referencia en
     * blanco: son dos movimientos legítimos e idénticos. Un único sobre la
     * huella se comería el segundo EN SILENCIO, perdiendo dinero real de la
     * conciliación. Por eso se cuentan ocurrencias.
     */
    $gemelos = [
        '10/04/2026,SPEI RECIBIDO,,,2500.00',
        '10/04/2026,SPEI RECIBIDO,,,2500.00',
    ];
    $r3 = $importador->importar($cuenta, csv($gemelos), '2026-04-01', '2026-04-30', 17350.00, 22350.00);

    verificar('Dos renglones idénticos del mismo día entran los DOS', $r3['nuevos'] === 2, "nuevos: {$r3['nuevos']}");

    $r4 = $importador->importar($cuenta, csv($gemelos), '2026-04-01', '2026-04-30', 17350.00, 22350.00);

    verificar('Y reimportarlos no agrega un tercero', $r4['nuevos'] === 0);

    echo PHP_EOL.'4. Un movimiento fuera del periodo capturado se rechaza'.PHP_EOL;

    $motivoFuera = motivoDe(fn () => $importador->importar(
        $cuenta, csv($renglones), '2026-05-01', '2026-05-31', 0.0, 7350.0
    ));

    verificar('No se cuela dinero de otro mes', $motivoFuera !== null, (string) $motivoFuera);

    echo PHP_EOL.'5. Los cobros del sistema, y por dónde llegan al banco'.PHP_EOL;

    $matricula = MatriculaOferta::query()->firstOrFail();
    $transferencia = MetodoPago::where('clave', 'transferencia')->firstOrFail();
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();

    verificar('El efectivo afecta caja y la transferencia no', (bool) $efectivo->afecta_caja && ! $transferencia->afecta_caja);

    $pagoSpei = Pago::create([
        'matricula_oferta_id' => $matricula->id,
        'metodo_pago_id' => $transferencia->id,
        'monto' => 2500.00,
        'referencia' => 'REF7788',
        'estatus' => Pago::ESTATUS_COMPLETADO,
        'momento' => '2026-03-05 11:00:00',
    ]);

    $pagoEfectivo = Pago::create([
        'matricula_oferta_id' => $matricula->id,
        'metodo_pago_id' => $efectivo->id,
        'monto' => 3200.00,
        'referencia' => 'MOSTRADOR',
        'estatus' => Pago::ESTATUS_COMPLETADO,
        'momento' => '2026-03-09 10:00:00',
    ]);

    $deposito = DepositoCaja::create([
        'cuenta_bancaria_id' => $cuenta->id,
        'monto' => 3200.00,
        'fecha' => '2026-03-09',
        'referencia' => 'FICHA22',
    ]);

    $candidatos = $conciliador->candidatos($spei);
    $claves = array_column($candidatos, 'clave');

    verificar('El cobro por transferencia sale como candidato', in_array('pago:'.$pagoSpei->id, $claves, true));
    verificar(
        'El cobro en EFECTIVO no: al banco llega en el depósito, no cobro por cobro',
        ! in_array('pago:'.$pagoEfectivo->id, $claves, true)
    );

    $delEfectivo = MovimientoBancario::where('estado_cuenta_id', $estado->id)->where('referencia', 'FICHA22')->firstOrFail();
    $clavesDeposito = array_column($conciliador->candidatos($delEfectivo), 'clave');

    verificar('Y el depósito del turno sí', in_array('deposito:'.$deposito->id, $clavesDeposito, true));

    verificar('Un cargo del banco no ofrece candidatos', $conciliador->candidatos($comision) === []);

    echo PHP_EOL.'6. El pareo automático sólo cuando no hay duda'.PHP_EOL;

    $auto = $conciliador->conciliarAutomatico($estado);

    verificar('Casa los seguros', $auto['casados'] >= 2, 'casados: '.$auto['casados']);
    verificar('El SPEI quedó atado a su pago', $spei->fresh()->partidas()->where('pago_id', $pagoSpei->id)->exists());
    verificar('Y el depósito al suyo', $delEfectivo->fresh()->partidas()->where('deposito_caja_id', $deposito->id)->exists());
    verificar('El renglón queda resuelto', $spei->fresh()->estaResuelto());
    verificar('Y su pendiente es cero', abs($spei->fresh()->pendiente()) < 0.005);

    // Dos cobros iguales frente a dos renglones iguales: no hay forma de saber
    // cuál es cuál, así que no decide.
    $unoDeLosGemelos = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)
        ->where('fecha', '2026-04-10')->orderBy('id')->firstOrFail();

    foreach ([1, 2] as $i) {
        Pago::create([
            'matricula_oferta_id' => $matricula->id,
            'metodo_pago_id' => $transferencia->id,
            'monto' => 2500.00,
            'referencia' => 'SPEIRECIBIDO',
            'estatus' => Pago::ESTATUS_COMPLETADO,
            'momento' => '2026-04-10 09:0'.$i.':00',
        ]);
    }

    $estadoAbril = EstadoCuentaBancaria::where('cuenta_bancaria_id', $cuenta->id)
        ->where('periodo_inicio', '2026-04-01')->orderBy('id')->firstOrFail();

    $auto2 = $conciliador->conciliarAutomatico($estadoAbril);

    verificar('Con dos candidatos posibles NO decide', $auto2['casados'] === 0, 'casados: '.$auto2['casados']);
    verificar('Y lo reporta como ambiguo', $auto2['ambiguos'] > 0, 'ambiguos: '.$auto2['ambiguos']);

    echo PHP_EOL.'7. Un movimiento del sistema se concilia una sola vez'.PHP_EOL;

    $otroRenglon = MovimientoBancario::where('estado_cuenta_id', $estado->id)->where('referencia', 'REF9911')->firstOrFail();

    $motivoDoble = motivoDe(fn () => $conciliador->conciliar($otroRenglon, ['pago:'.$pagoSpei->id]));

    verificar('El mismo pago no cuadra dos renglones', $motivoDoble !== null, (string) $motivoDoble);
    verificar('Y el mensaje lo explica', str_contains((string) $motivoDoble, 'ya estaba conciliado'));

    $motivoSalida = motivoDe(fn () => $conciliador->conciliar($comision, ['pago:'.$pagoSpei->id]));

    verificar('Una salida del banco no se concilia contra un cobro', $motivoSalida !== null, (string) $motivoSalida);

    echo PHP_EOL.'8. Lo que no es un cobro se clasifica'.PHP_EOL;

    verificar('La comisión empieza sin resolver', ! $comision->fresh()->estaResuelto());

    $conciliador->clasificar($comision, MovimientoBancario::COMISION, null);

    verificar('Clasificada, queda resuelta', $comision->fresh()->estaResuelto());

    $motivoOtro = motivoDe(fn () => $conciliador->clasificar($otroRenglon, MovimientoBancario::OTRO, null));

    verificar('«Otro» sin decir qué es se rechaza', $motivoOtro !== null, (string) $motivoOtro);

    $motivoInventado = motivoDe(fn () => $conciliador->clasificar($otroRenglon, 'inventada', null));

    verificar('Una clasificación que no existe se rechaza', $motivoInventado !== null);

    echo PHP_EOL.'9. Las dos listas por las que existe esto'.PHP_EOL;

    $panorama = $conciliador->panorama($estado);

    // REF9911 entró al banco y no hay cobro que lo respalde: alguien pagó y
    // nadie lo registró.
    verificar(
        'Lo que entró y nadie registró aparece',
        $panorama['sin_registrar']->contains('id', $otroRenglon->id)
    );
    verificar('Con su importe sumado', abs($panorama['total_sin_registrar'] - 1800.0) < 0.005, (string) $panorama['total_sin_registrar']);

    // Un cobro registrado en el periodo cuyo dinero nunca llegó.
    $fantasma = Pago::create([
        'matricula_oferta_id' => $matricula->id,
        'metodo_pago_id' => $transferencia->id,
        'monto' => 999.00,
        'referencia' => 'NUNCALLEGO',
        'estatus' => Pago::ESTATUS_COMPLETADO,
        'momento' => '2026-03-20 12:00:00',
    ]);

    $panorama = $conciliador->panorama($estado);
    $sinLlegar = array_column($panorama['sin_llegar'], 'clave');

    verificar('Lo cobrado que el banco no vio aparece', in_array('pago:'.$fantasma->id, $sinLlegar, true));
    verificar(
        'Y el cobro ya conciliado NO aparece ahí',
        ! in_array('pago:'.$pagoSpei->id, $sinLlegar, true)
    );
    verificar(
        'El efectivo tampoco: no se busca en el banco cobro por cobro',
        ! in_array('pago:'.$pagoEfectivo->id, $sinLlegar, true)
    );

    echo PHP_EOL.'10. Deshacer un pareo lo deja volver a casarse'.PHP_EOL;

    $partida = ConciliacionPartida::where('pago_id', $pagoSpei->id)->firstOrFail();
    $conciliador->desconciliar($partida);

    verificar('La partida desaparece de verdad', ConciliacionPartida::withTrashed()->where('pago_id', $pagoSpei->id)->count() === 0);

    $rehecho = $conciliador->conciliar($spei->fresh(), ['pago:'.$pagoSpei->id]);

    verificar('Y se puede volver a atar', $rehecho === 1);

    echo PHP_EOL.'11. El mapeo se valida ANTES de guardarlo'.PHP_EOL;

    $sinImporte = motivoDe(fn () => MapeoEstadoCuenta::validar([
        'columna_fecha' => 'Fecha', 'columna_descripcion' => 'Concepto',
        'columna_monto' => null, 'columna_cargo' => null, 'columna_abono' => null,
    ]));

    verificar('Sin columna de importe no pasa', $sinImporte !== null, (string) $sinImporte);

    $dosFormas = motivoDe(fn () => MapeoEstadoCuenta::validar([
        'columna_fecha' => 'Fecha', 'columna_descripcion' => 'Concepto',
        'columna_monto' => 'Importe', 'columna_cargo' => 'Cargo', 'columna_abono' => 'Abono',
    ]));

    verificar('Con las dos formas a la vez tampoco', $dosFormas !== null, (string) $dosFormas);

    echo PHP_EOL.'12. Lo que el lector tiene que soportar de un banco real'.PHP_EOL;

    $cuentaUna = CuentaBancaria::create([
        'nombre' => 'Cuenta de una columna', 'banco' => 'Otro Banco',
        'titular' => 'Escuela Demo', 'clabe' => '012345678901234599',
        'numero_cuenta' => '99887766', 'activa' => true,
        'mapeo_estado_cuenta' => [
            'delimitador' => ',',
            // Dos renglones de preámbulo antes del encabezado, como hacen
            // varios bancos con los datos de la cuenta.
            'renglon_encabezado' => 3,
            'formato_fecha' => 'Y-m-d',
            'columna_fecha' => 'FECHA',
            // Sin acento y en mayúsculas: se compara normalizado a propósito,
            // porque la misma columna sale de tres formas según el banco.
            'columna_descripcion' => 'descripcion',
            'columna_referencia' => null,
            'columna_monto' => 'Importe',
            'columna_cargo' => null,
            'columna_abono' => null,
        ],
    ]);

    $raro = csv(
        [
            '2026-06-02,"SPEI RECIBIDO, REF 4471","$1,200.50"',
            '2026-06-03,COMISION,"(300.00)"',
            ',TOTAL DEL PERIODO,900.50',
        ],
        "Estado de cuenta 00998877\nDel 01/06/2026 al 30/06/2026\nFecha,Descripción,Importe",
    );

    $rRaro = $importador->importar($cuentaUna, $raro, '2026-06-01', '2026-06-30', 0.0, 900.50);

    verificar('Salta el preámbulo y encuentra el encabezado', $rRaro['nuevos'] === 2, 'nuevos: '.$rRaro['nuevos']);

    $entrada = MovimientoBancario::where('estado_cuenta_id', $rRaro['estado']->id)->where('monto', '>', 0)->firstOrFail();
    $salida = MovimientoBancario::where('estado_cuenta_id', $rRaro['estado']->id)->where('monto', '<', 0)->firstOrFail();

    verificar('«$1,200.50» es 1200.50', abs((float) $entrada->monto - 1200.50) < 0.005, (string) $entrada->monto);
    verificar('«(300.00)» es negativo: es notación contable', abs((float) $salida->monto + 300.0) < 0.005, (string) $salida->monto);
    verificar('El renglón de totales, sin fecha, no entra', $rRaro['estado']->fresh()->movimientos === 2);
    verificar('Y el periodo cuadra', $rRaro['estado']->fresh()->cuadra());

    $sinColumna = motivoDe(fn () => $importador->importar(
        $cuenta, csv(['05/03/2026,X,Y,,1.00'], 'Fecha,Concepto,Referencia,Debe,Haber'),
        '2026-03-01', '2026-03-31', 0.0, 1.0
    ));

    verificar('Una columna que no está se dice, no se adivina', $sinColumna !== null, (string) $sinColumna);
    verificar('Y el mensaje enumera las que sí trae', str_contains((string) $sinColumna, 'Haber'));

    echo PHP_EOL.'13. Las pantallas (props de Inertia, no sólo el servicio)'.PHP_EOL;

    $peticion = Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);

    $props = json_decode($control->index()->toResponse($peticion)->getContent(), true)['props'];

    verificar('El listado responde con sus cuentas', collect($props['cuentas'])->contains('id', $cuenta->id));
    verificar('Y dice cuál tiene mapeo', collect($props['cuentas'])->firstWhere('id', $cuenta->id)['tiene_mapeo'] === true);
    verificar('Y trae los periodos importados', collect($props['estados'])->contains('id', $estado->id));

    $props = json_decode($control->detalle($estado->fresh())->toResponse($peticion)->getContent(), true)['props'];

    verificar('El detalle trae los renglones', count($props['movimientos']) === 4);
    verificar('Con su clasificación cuando la tienen', collect($props['movimientos'])->contains('clasificacion', 'comision'));
    verificar('Y la lista de lo cobrado que el banco no vio', count($props['sinLlegar']) >= 1);

    $json = json_decode($control->candidatos($otroRenglon)->getContent(), true);

    verificar('El endpoint de candidatos contesta', array_key_exists('candidatos', $json));

    echo PHP_EOL.'14. Un estado de cuenta con pareos no se retira sin más'.PHP_EOL;

    $motivoBorrar = motivoDe(fn () => $control->eliminar($estado->fresh()));

    verificar('Se niega mientras tenga renglones conciliados', $motivoBorrar !== null, (string) $motivoBorrar);
    verificar('Y el estado de cuenta sigue ahí', EstadoCuentaBancaria::find($estado->id) !== null);

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} catch (Throwable $e) {
    $fallos[] = 'la suite murió antes de terminar';
    echo '  FALLA la suite murió antes de terminar  ['.$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().']'.PHP_EOL;
    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} finally {
    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }

    foreach ($temporales as $t) {
        @unlink($t);
    }

    DB::rollBack();
}

exit($fallos === [] ? 0 : 1);
