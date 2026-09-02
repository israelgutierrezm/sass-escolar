<?php

/**
 * Convenios de pago: reprogramar una deuda sin perdonarla.
 *
 * `php scripts/prueba-convenios-pago.php` desde la raíz. Contra la BD real del
 * tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Que un convenio no sea una puerta trasera para perdonar deuda —la suma tiene
 * que cuadrar al centavo, en las dos direcciones—, que los cargos acordados
 * dejen de pesar SIN perderse, que la mora se pare de verdad, y que la
 * situación «con convenio» —la fila del catálogo que llevaba desde 7.1 sin que
 * nadie la escribiera— por fin la escriba alguien.
 *
 * Y la diferencia entre CANCELAR e INCUMPLIR, que es lo que impide cobrar dos
 * veces el mismo dinero.
 *
 * ── El escenario se construye ENTERO ───────────────────────────────────────
 * Con sus propios conceptos y su propia matrícula: lo que se mide es
 * aritmética de saldos, y eso sólo se puede afirmar sabiendo qué hay.
 *
 * Los `use` van ARRIBA del arranque a propósito.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ConvenioPagoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\ConvenioPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SituacionPago;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\CalculadorRecargos;
use App\Services\ConvenioDePago;
use App\Services\EstadoCuenta;
use App\Services\EvaluadorDeudor;
use App\Services\RegistradorPago;
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
    $servicio = app(ConvenioDePago::class);
    $control = app(ConvenioPagoController::class);
    $estadoCuenta = app(EstadoCuenta::class);

    echo '0. Escenario'.PHP_EOL;

    $usuario = Usuario::findOrFail(1);
    Auth::login($usuario);

    $matricula = MatriculaOferta::query()->orderBy('id')->firstOrFail();
    $otraMatricula = MatriculaOferta::query()->where('id', '!=', $matricula->id)->orderBy('id')->firstOrFail();

    /*
     * A propósito NO se usa el primer concepto de la tabla: si el escenario
     * usara el que cualquier consulta devuelve primero, una mutación que
     * tomara «un concepto cualquiera» daría el mismo y pasaría inadvertida.
     */
    $colegiatura = ConceptoPago::query()->where('clave', 'colegiatura')->firstOrFail();
    $otroConcepto = ConceptoPago::query()->where('clave', 'inscripcion')->firstOrFail();

    // Y los cargos acordados llevan LÍNEA DE PLAN, que es como llegan los de
    // verdad: sin ella, copiarla a la parcialidad no cambiaría nada y la regla
    // de «la parcialidad no hereda plan» quedaría sin comprobar.
    $linea = App\Models\Finanzas\ConceptoPlan::query()->where('concepto_id', $colegiatura->id)->firstOrFail();

    /** Un cargo vencido, del concepto que se le pida. */
    $cargo = function (float $monto, string $vence, ?ConceptoPago $concepto = null, ?int $lineaId = null) use ($matricula, $colegiatura) {
        return Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => ($concepto ?? $colegiatura)->id,
            'concepto_plan_id' => $lineaId,
            'periodo_etiqueta' => 'PRUEBA-CONV-'.uniqid(),
            'monto' => $monto,
            'monto_total' => $monto,
            'fecha_generacion' => '2026-01-01',
            'fecha_vencimiento' => $vence,
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);
    };

    $a1 = $cargo(2500.00, '2026-02-15', null, $linea->id);
    $a2 = $cargo(2500.00, '2026-03-15', null, $linea->id);
    $a3 = $cargo(1000.00, '2026-04-15', $otroConcepto);

    verificar('Los cargos acordados llevan línea de plan', $a1->concepto_plan_id !== null);

    verificar('Tres cargos por cobrar', Adeudo::whereIn('id', [$a1->id, $a2->id, $a3->id])->porCobrar()->count() === 3);

    $elegibles = $servicio->elegibles($matricula);

    verificar('Los tres son elegibles', $elegibles->pluck('id')->intersect([$a1->id, $a2->id, $a3->id])->count() === 3);

    echo PHP_EOL.'0.b Lo que se acuerda es el SALDO, no el monto'.PHP_EOL;

    /*
     * A un cargo con abono parcial se le acuerda lo que FALTA. Acordar el monto
     * completo volvería a cobrar lo que ya entró — y no se notaría, porque las
     * dos cifras se parecen.
     */
    $registrador = app(RegistradorPago::class);
    $metodo = MetodoPago::where('clave', 'transferencia')->firstOrFail();

    $conAbono = $cargo(3000.00, '2026-02-20', null, $linea->id);
    $abono = $registrador->registrar($matricula, $metodo, 1200.00, [$conAbono->id], referencia: 'ABONO-PARCIAL');
    $registrador->confirmar($abono);
    $conAbono->refresh();

    verificar('El cargo quedó parcial', $conAbono->estatus === Adeudo::ESTATUS_PARCIAL, $conAbono->estatus);
    verificar('Su saldo es 1,800 y su monto 3,000', abs($conAbono->saldo() - 1800.0) < 0.005 && abs((float) $conAbono->monto_total - 3000.0) < 0.005);

    $porElMonto = motivoDe(fn () => $servicio->crear(
        $matricula, [$conAbono->id],
        [['fecha' => '2026-05-15', 'monto' => 3000.00]],
        'Acordando el monto completo.', '2026-04-01', $usuario,
    ));

    verificar('Acordar el MONTO y no el saldo se rechaza', $porElMonto !== null, (string) $porElMonto);

    $porElSaldo = $servicio->crear(
        $matricula, [$conAbono->id],
        [['fecha' => '2026-05-15', 'monto' => 1800.00]],
        'Acordando lo que falta.', '2026-04-01', $usuario,
    );

    verificar('Y acordar el saldo sí pasa', abs((float) $porElSaldo->monto_cubierto - 1800.0) < 0.005, (string) $porElSaldo->monto_cubierto);

    // Se retira para no estorbar lo que sigue.
    $servicio->cancelar($porElSaldo, 'Sólo era para comprobar el saldo.');

    echo PHP_EOL.'0.c El alcance: los cargos son del alumno que se dice'.PHP_EOL;

    $ajeno = Adeudo::create([
        'matricula_oferta_id' => $otraMatricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'PRUEBA-CONV-AJENO',
        'monto' => 500.00,
        'monto_total' => 500.00,
        'fecha_generacion' => '2026-01-01',
        'fecha_vencimiento' => '2026-02-01',
        'estatus' => Adeudo::ESTATUS_PENDIENTE,
    ]);

    $deOtro = motivoDe(fn () => $servicio->crear(
        $matricula, [$ajeno->id],
        [['fecha' => '2026-05-15', 'monto' => 500.00]],
        'Cargo de otra persona.', '2026-04-01', $usuario,
    ));

    verificar('Un cargo de otro alumno no se acuerda', $deOtro !== null, (string) $deOtro);
    verificar('Y no se le ofrece como elegible', ! $servicio->elegibles($matricula)->pluck('id')->contains($ajeno->id));

    echo PHP_EOL.'1. Un convenio no perdona: la suma tiene que cuadrar'.PHP_EOL;

    $deMenos = motivoDe(fn () => $servicio->crear(
        $matricula, [$a1->id, $a2->id],
        [['fecha' => '2026-05-15', 'monto' => 3000.00]],
        'Se atrasó por desempleo.', '2026-04-01', $usuario,
    ));

    verificar('Sumar de MENOS se rechaza', $deMenos !== null, (string) $deMenos);
    verificar('Y el mensaje manda a condonar', str_contains((string) $deMenos, 'condona'));

    $deMas = motivoDe(fn () => $servicio->crear(
        $matricula, [$a1->id, $a2->id],
        [['fecha' => '2026-05-15', 'monto' => 6000.00]],
        'Con intereses inventados.', '2026-04-01', $usuario,
    ));

    verificar('Y sumar de MÁS también', $deMas !== null, (string) $deMas);

    echo PHP_EOL.'2. Un convenio cubre un solo concepto'.PHP_EOL;

    $mezclado = motivoDe(fn () => $servicio->crear(
        $matricula, [$a1->id, $a3->id],
        [['fecha' => '2026-05-15', 'monto' => 3500.00]],
        'Todo junto.', '2026-04-01', $usuario,
    ));

    verificar('Mezclar conceptos se rechaza', $mezclado !== null, (string) $mezclado);
    verificar('Y el motivo nombra al CFDI', str_contains((string) $mezclado, 'CFDI'));

    echo PHP_EOL.'3. Y otras cosas que no se pueden acordar'.PHP_EOL;

    $sinFecha = motivoDe(fn () => $servicio->crear(
        $matricula, [$a1->id],
        [['fecha' => '2026-03-01', 'monto' => 2500.00]],
        'Vence antes de firmarse.', '2026-04-01', $usuario,
    ));

    verificar('Una parcialidad anterior a la firma se rechaza', $sinFecha !== null, (string) $sinFecha);

    $pagado = $cargo(100.00, '2026-02-01');
    $pagado->update(['estatus' => Adeudo::ESTATUS_PAGADO]);

    $yaPagado = motivoDe(fn () => $servicio->crear(
        $matricula, [$pagado->id],
        [['fecha' => '2026-05-15', 'monto' => 100.00]],
        'Ya está pagado.', '2026-04-01', $usuario,
    ));

    verificar('Un cargo que ya no está por cobrar se rechaza', $yaPagado !== null, (string) $yaPagado);

    echo PHP_EOL.'4. Firmar: qué cambia de verdad'.PHP_EOL;

    $convenio = $servicio->crear(
        $matricula, [$a1->id, $a2->id],
        [
            ['fecha' => '2026-05-15', 'monto' => 1666.66],
            ['fecha' => '2026-06-15', 'monto' => 1666.67],
            ['fecha' => '2026-07-15', 'monto' => 1666.67],
        ],
        'Se atrasó por desempleo del tutor.', '2026-04-01', $usuario,
    );

    verificar('Nace vigente', $convenio->estaVigente());
    verificar('Con el monto cubierto congelado', abs((float) $convenio->monto_cubierto - 5000.0) < 0.005, (string) $convenio->monto_cubierto);
    verificar('Y con quién lo autorizó', (int) $convenio->autorizado_por === (int) $usuario->getKey());

    $a1->refresh();
    $a2->refresh();

    verificar('Los cargos originales pasan a `en_convenio`', $a1->estatus === Adeudo::ESTATUS_EN_CONVENIO && $a2->estatus === Adeudo::ESTATUS_EN_CONVENIO);
    verificar('NO se cancelan: siguen contando qué se debía', $a1->estatus !== Adeudo::ESTATUS_CANCELADO);
    verificar('Y dejan de pesar en la cartera', Adeudo::whereIn('id', [$a1->id, $a2->id])->porCobrar()->count() === 0);

    verificar('Se crearon tres parcialidades', $convenio->parcialidades()->count() === 3);
    verificar('Que suman lo acordado', abs($convenio->parcialidades()->sum('monto_total') - 5000.0) < 0.005);
    verificar('Y son del mismo concepto', $convenio->parcialidades()->where('concepto_id', '!=', $colegiatura->id)->count() === 0);
    verificar(
        'Sin línea de plan: es lo que para la mora',
        $convenio->parcialidades()->whereNotNull('concepto_plan_id')->count() === 0,
        'los cargos cubiertos sí la tienen, así que heredarla se notaría'
    );

    // La comprobación de fondo: la mora deja de correr de verdad.
    $recargos = app(CalculadorRecargos::class);
    $unaParcialidad = $convenio->parcialidades()->first();
    $unaParcialidad->update(['fecha_vencimiento' => '2026-01-01']);   // vencidísima

    verificar(
        'Una parcialidad vencida no genera recargo',
        abs($recargos->recargoPara($unaParcialidad->fresh())) < 0.005
    );
    $unaParcialidad->update(['fecha_vencimiento' => '2026-05-15']);

    echo PHP_EOL.'5. La situación «con convenio» por fin la escribe alguien'.PHP_EOL;

    $ultima = BitacoraSituacionFinanciera::where('matricula_oferta_id', $matricula->id)->latest('momento')->latest('id')->first();

    verificar(
        'Firmar deja la situación en «convenio»',
        SituacionPago::find($ultima?->situacion_id)?->clave === 'convenio',
        (string) SituacionPago::find($ultima?->situacion_id)?->clave
    );

    $evaluador = app(EvaluadorDeudor::class);

    // Con el convenio al día, el evaluador nocturno NO lo manda a moroso.
    $clave = $evaluador->evaluar($matricula->fresh(), Carbon\CarbonImmutable::parse('2026-05-01'));

    verificar(
        'Y el barrido nocturno lo respeta',
        $clave === null || $clave === 'convenio',
        'devolvió: '.var_export($clave, true)
    );

    // Con una parcialidad vencida, manda «moroso»: el acuerdo se está rompiendo
    // y esconderlo tras «con convenio» dejaría el atraso invisible.
    $claveAtraso = $evaluador->evaluar($matricula->fresh(), Carbon\CarbonImmutable::parse('2026-06-01'));

    verificar('Con una parcialidad vencida pasa a moroso', $claveAtraso === 'moroso', 'devolvió: '.var_export($claveAtraso, true));
    verificar('Y el convenio lo reporta', $convenio->fresh()->tieneAtraso('2026-06-01'));

    echo PHP_EOL.'6. El estado de cuenta no cuenta el dinero dos veces'.PHP_EOL;

    $cuenta = $estadoCuenta->para($matricula->fresh(), Carbon\CarbonImmutable::parse('2026-04-02'));
    $porCobrar = collect($cuenta['adeudos'])->whereIn('estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL]);

    verificar(
        'Los cargos acordados no aparecen por cobrar',
        $porCobrar->whereIn('id', [$a1->id, $a2->id])->isEmpty()
    );
    verificar(
        'Pero siguen en el estado de cuenta, con su estatus',
        collect($cuenta['adeudos'])->where('id', $a1->id)->first()['estatus'] === Adeudo::ESTATUS_EN_CONVENIO
    );
    verificar(
        'Y las parcialidades dicen de qué convenio son',
        collect($cuenta['adeudos'])->where('convenio_id', $convenio->id)->count() === 3
    );

    echo PHP_EOL.'6.b Lo condonado no sigue contando como saldo'.PHP_EOL;

    /*
     * Condonar una parcialidad es un acto legítimo, con su propio permiso. Un
     * cargo condonado no está pagado —nadie aplicó dinero— así que su «saldo»
     * crudo sigue siendo su importe: si el convenio lo sumara, diría que se
     * debe algo que ya nadie debe, y nunca se daría por cumplido.
     */
    $antesDeCondonar = $convenio->fresh()->saldo();
    $unaMas = $convenio->parcialidades()->orderByDesc('fecha_vencimiento')->first();
    $unaMas->update(['estatus' => Adeudo::ESTATUS_CONDONADO]);

    verificar('Una parcialidad condonada no tiene pagos aplicados', abs($unaMas->montoAplicado()) < 0.005);
    verificar(
        'Y deja de sumar al saldo del convenio',
        abs($convenio->fresh()->saldo() - ($antesDeCondonar - (float) $unaMas->monto_total)) < 0.005,
        'antes '.$antesDeCondonar.', ahora '.$convenio->fresh()->saldo()
    );

    $unaMas->update(['estatus' => Adeudo::ESTATUS_PENDIENTE]);

    echo PHP_EOL.'6.c Un cargo dentro de un convenio no se resuelve por su renglón'.PHP_EOL;

    /*
     * Condonarlo no perdonaría nada: sus parcialidades seguirían cobrándose y
     * el alumno acabaría pagando lo que se le acaba de regalar. Salió al mirar
     * la pantalla, donde el botón de condonar seguía ofreciéndose sobre los
     * cargos cubiertos.
     */
    $finanzas = app(App\Http\Controllers\FinanzasController::class);
    $peticionResolver = Request::create('/', 'PUT', [
        'estatus' => Adeudo::ESTATUS_CONDONADO,
        'motivo' => 'Intentando condonar uno que está en convenio.',
    ]);
    $peticionResolver->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionResolver);

    $respuesta = $finanzas->resolverAdeudo($peticionResolver, $a1->fresh());
    $errores = $respuesta->getSession()?->get('error');

    verificar('Se rehúsa', is_string($errores) && str_contains($errores, 'convenio'), (string) $errores);
    verificar('Y el cargo sigue en convenio', $a1->fresh()->estatus === Adeudo::ESTATUS_EN_CONVENIO);
    // Y la contraparte, que es la salida que el mensaje ofrece: la PARCIALIDAD
    // sí se resuelve, porque es lo que de verdad se cobra.
    $paraCondonar = $convenio->parcialidades()->orderByDesc('fecha_vencimiento')->first();
    $peticionParcial = Request::create('/', 'PUT', [
        'estatus' => Adeudo::ESTATUS_CONDONADO,
        'motivo' => 'Se le perdona la última parcialidad por su situación.',
    ]);
    $peticionParcial->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionParcial);

    $finanzas->resolverAdeudo($peticionParcial, $paraCondonar->fresh());

    verificar(
        'Mientras que una PARCIALIDAD sí se resuelve',
        $paraCondonar->fresh()->estatus === Adeudo::ESTATUS_CONDONADO,
        $paraCondonar->fresh()->estatus
    );

    $paraCondonar->update(['estatus' => Adeudo::ESTATUS_PENDIENTE]);

    echo PHP_EOL.'7. No se acuerda dos veces el mismo cargo'.PHP_EOL;

    $repetido = motivoDe(fn () => $servicio->crear(
        $matricula, [$a1->id],
        [['fecha' => '2026-08-15', 'monto' => 2500.00]],
        'Otra vez.', '2026-04-01', $usuario,
    ));

    verificar('El mismo cargo no entra a otro convenio', $repetido !== null, (string) $repetido);
    verificar(
        'Y deja de ofrecerse como elegible',
        ! $servicio->elegibles($matricula->fresh())->pluck('id')->contains($a1->id)
    );

    /*
     * Y el pivote es la ÚLTIMA defensa, no una repetición del estatus. Se
     * construye el caso que la hace falta: un cargo que alguien devolvió a
     * «pendiente» a mano —una reparación de datos, una migración torcida—
     * mientras su fila del pivote sigue ahí. Sin esta comprobación, el mismo
     * dinero entraría a dos convenios y sólo lo detendría el índice único, con
     * un error de base en la cara de quien captura.
     */
    $a1->update(['estatus' => Adeudo::ESTATUS_PENDIENTE]);

    verificar(
        'Un cargo devuelto a mano tampoco se ofrece: manda el pivote',
        ! $servicio->elegibles($matricula->fresh())->pluck('id')->contains($a1->id)
    );

    $porElPivote = motivoDe(fn () => $servicio->crear(
        $matricula, [$a1->id],
        [['fecha' => '2026-08-15', 'monto' => 2500.00]],
        'Con los datos torcidos.', '2026-04-01', $usuario,
    ));

    verificar('Y crear se niega nombrando el otro convenio', $porElPivote !== null, (string) $porElPivote);
    verificar('Con su mensaje, no con un error de base', str_contains((string) $porElPivote, 'otro convenio'));

    $a1->update(['estatus' => Adeudo::ESTATUS_EN_CONVENIO]);

    echo PHP_EOL.'8. Cancelar: sólo mientras no haya entrado un peso'.PHP_EOL;

    $sinAbonos = ConvenioPago::find($convenio->id);

    verificar('Sin abonos, se puede cancelar', ! $sinAbonos->tieneAbonos());

    $servicio->cancelar($sinAbonos, 'Se capturó con las fechas equivocadas.');
    $sinAbonos->refresh();

    verificar('Queda cancelado', $sinAbonos->estatus === ConvenioPago::CANCELADO);
    verificar('Sus parcialidades se cancelan', $sinAbonos->parcialidades()->porCobrar()->count() === 0);
    verificar(
        'Y los cargos originales VUELVEN a estar por cobrar',
        Adeudo::whereIn('id', [$a1->id, $a2->id])->porCobrar()->count() === 2
    );
    verificar(
        'Así que se pueden volver a acordar',
        $servicio->elegibles($matricula->fresh())->pluck('id')->contains($a1->id),
        'el pivote se borra de verdad, no en baja lógica'
    );

    echo PHP_EOL.'9. Incumplir ACELERA, no deshace'.PHP_EOL;

    $segundo = $servicio->crear(
        $matricula, [$a1->id, $a2->id],
        [
            ['fecha' => '2026-05-15', 'monto' => 2500.00],
            ['fecha' => '2026-09-15', 'monto' => 2500.00],
        ],
        'Segundo intento.', '2026-04-01', $usuario,
    );

    // Entra dinero a la primera parcialidad.
    $primera = $segundo->parcialidades()->orderBy('fecha_vencimiento')->first();
    $pago = $registrador->registrar($matricula->fresh(), $metodo, 2500.00, [$primera->id], referencia: 'CONVENIO-1');
    $registrador->confirmar($pago);

    verificar('La primera parcialidad se pagó', $primera->fresh()->estatus === Adeudo::ESTATUS_PAGADO, $primera->fresh()->estatus);
    verificar('Y el convenio ya tiene abonos', $segundo->fresh()->tieneAbonos());

    $noSePuede = motivoDe(fn () => $servicio->cancelar($segundo->fresh(), 'Ya no.'));

    verificar('Con abonos ya no se puede cancelar', $noSePuede !== null, (string) $noSePuede);
    verificar('Y el mensaje manda a incumplir', str_contains((string) $noSePuede, 'incumplid'));

    $n = $servicio->incumplir($segundo->fresh(), 'Dejó de pagar en junio.');
    $segundo->refresh();

    verificar('Queda incumplido', $segundo->estatus === ConvenioPago::INCUMPLIDO);
    verificar('Se venció la parcialidad que faltaba', $n === 1, "vencidas: {$n}");
    verificar(
        'Con fecha de hoy',
        $segundo->parcialidades()->porCobrar()->first()?->fecha_vencimiento?->toDateString() === now()->toDateString()
    );
    verificar(
        'Los cargos originales NO vuelven: el convenio ya cobró parte',
        Adeudo::whereIn('id', [$a1->id, $a2->id])->porCobrar()->count() === 0,
        'devolverlos completos cobraría dos veces los 2,500 que ya entraron'
    );
    verificar('Y lo que queda vivo son 2,500', abs($segundo->saldo() - 2500.0) < 0.005, (string) $segundo->saldo());

    $yaCerrado = motivoDe(fn () => $servicio->incumplir($segundo->fresh(), 'Otra vez.'));

    verificar('Un convenio cerrado no se vuelve a cerrar', $yaCerrado !== null);

    echo PHP_EOL.'10. Cumplirse se reconoce solo'.PHP_EOL;

    $resto = $segundo->parcialidades()->porCobrar()->first();
    $pago2 = $registrador->registrar($matricula->fresh(), $metodo, 2500.00, [$resto->id], referencia: 'CONVENIO-2');
    $registrador->confirmar($pago2);

    // Está incumplido, así que revisar NO debe reabrirlo como cumplido: un
    // convenio roto que se termina de pagar sigue habiéndose roto.
    verificar('Un convenio incumplido no pasa a cumplido al pagarse', ! $servicio->revisarCumplimiento($segundo->fresh()));

    $tercero = $servicio->crear(
        $matricula, [$a3->id],
        [['fecha' => '2026-05-15', 'monto' => 1000.00]],
        'Una sola parcialidad: sólo mueve la fecha.', '2026-04-01', $usuario,
    );

    verificar('Un convenio de una sola parcialidad es válido', $tercero->parcialidades()->count() === 1);
    verificar('Todavía no está cumplido', ! $servicio->revisarCumplimiento($tercero->fresh()));

    $unica = $tercero->parcialidades()->first();
    $pago3 = $registrador->registrar($matricula->fresh(), $metodo, 1000.00, [$unica->id], referencia: 'CONVENIO-3');
    $registrador->confirmar($pago3);

    verificar('Pagado del todo, se reconoce solo', $servicio->revisarCumplimiento($tercero->fresh()));
    verificar('Y queda cumplido', $tercero->fresh()->estatus === ConvenioPago::CUMPLIDO);

    echo PHP_EOL.'11. Las pantallas (props de Inertia, no sólo el servicio)'.PHP_EOL;

    $peticion = Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);

    $props = json_decode($control->index($peticion)->toResponse($peticion)->getContent(), true)['props'];
    $ids = collect($props['convenios'])->pluck('id');

    verificar('El listado trae los tres convenios', $ids->contains($convenio->id) && $ids->contains($segundo->id) && $ids->contains($tercero->id));
    verificar(
        'Con su saldo y su estatus',
        collect($props['convenios'])->firstWhere('id', $segundo->id)['estatus'] === ConvenioPago::INCUMPLIDO
    );

    $filtrada = Request::create('/', 'GET', ['estatus' => ConvenioPago::CUMPLIDO]);
    $filtrada->headers->set('X-Inertia', 'true');
    $filtrada->headers->set('X-Inertia-Version', '');
    $filtrada->setUserResolver(fn () => $usuario);
    app()->instance('request', $filtrada);

    $props = json_decode($control->index($filtrada)->toResponse($filtrada)->getContent(), true)['props'];

    verificar(
        'Y el filtro por estatus acota',
        collect($props['convenios'])->every(fn ($c) => $c['estatus'] === ConvenioPago::CUMPLIDO)
    );

    $json = json_decode($control->elegibles($peticion, $matricula->fresh())->getContent(), true);

    verificar('El endpoint de cargos elegibles contesta', array_key_exists('cargos', $json));
    verificar(
        'Y no ofrece los que ya están en un convenio',
        ! collect($json['cargos'])->pluck('id')->contains($a1->id)
    );

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

    DB::rollBack();
}

exit($fallos === [] ? 0 : 1);
