<?php

/**
 * Presupuesto de egresos y centros de costo.
 *
 * `php scripts/prueba-presupuesto-egresos.php` desde la raíz. Contra la BD real
 * del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Que el ejercido tenga UNA sola fuente —los egresos registrados— y que se
 * pueda auditar renglón por renglón. Que un cruce con gasto y SIN presupuesto
 * salga en el tablero, porque es exactamente lo que hay que ver: se está
 * gastando en algo que nadie autorizó. Y que traer la misma nómina dos veces no
 * duplique el gasto más grande de la escuela.
 *
 * ── El escenario se construye ENTERO ───────────────────────────────────────
 * En el demo no hay ni un centro de costo, ni una partida, ni un periodo de
 * nómina. Lo que aquí se mide es aritmética presupuestal, y eso sólo se puede
 * afirmar sabiendo qué hay.
 *
 * Los `use` van ARRIBA del arranque a propósito.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\EgresoController;
use App\Http\Controllers\PresupuestoController;
use App\Models\Academico\Campus;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\Egreso;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Presupuesto;
use App\Models\Identidad\Usuario;
use App\Models\Nomina\PeriodoNomina;
use App\Models\Tenant;
use App\Services\Finanzas\PresupuestoDeEgresos;
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
    $servicio = app(PresupuestoDeEgresos::class);
    $control = app(PresupuestoController::class);
    $egresos = app(EgresoController::class);

    echo '0. Escenario'.PHP_EOL;

    $usuario = Usuario::findOrFail(1);
    Auth::login($usuario);

    $ciclo = Ciclo::query()->orderByDesc('id')->firstOrFail();
    $otroCiclo = Ciclo::query()->where('id', '!=', $ciclo->id)->orderByDesc('id')->firstOrFail();
    $campus = Campus::query()->orderBy('id')->firstOrFail();
    $otroCampus = Campus::query()->where('id', '!=', $campus->id)->orderBy('id')->firstOrFail();

    $centro = CentroCosto::create(['clave' => 'PRB-NORTE', 'nombre' => 'Prueba Norte', 'campus_id' => $campus->id, 'activo' => true]);
    $general = CentroCosto::create(['clave' => 'PRB-GRAL', 'nombre' => 'Prueba General', 'campus_id' => null, 'activo' => true]);
    $sueldos = PartidaPresupuesto::create(['clave' => 'PRB-SUELDOS', 'nombre' => 'Prueba sueldos', 'activo' => true]);
    $manten = PartidaPresupuesto::create(['clave' => 'PRB-MANT', 'nombre' => 'Prueba mantenimiento', 'activo' => true]);

    verificar('Un centro puede no ser de ningún campus', $general->campus_id === null, 'licencias, dirección general');

    Presupuesto::create([
        'centro_costo_id' => $centro->id, 'partida_id' => $manten->id,
        'ciclo_id' => $ciclo->id, 'monto' => 50000.00,
    ]);

    // Y otro en OTRO ciclo, sobre un cruce que este ciclo no tiene: si el
    // tablero no filtrara por ciclo, aparecerian los 12,345 de un ano que
    // nadie esta mirando.
    Presupuesto::create([
        'centro_costo_id' => $general->id, 'partida_id' => $sueldos->id,
        'ciclo_id' => $otroCiclo->id, 'monto' => 12345.00,
    ]);

    echo PHP_EOL.'1. El ejercido se mide de los egresos'.PHP_EOL;

    verificar('Sin egresos, cero', abs($servicio->ejercido($centro->id, $manten->id, $ciclo->id)) < 0.005);

    $gasto = function (CentroCosto $c, PartidaPresupuesto $p, float $monto, ?Ciclo $ci = null) use ($ciclo) {
        return Egreso::create([
            'fecha' => '2026-06-10',
            'centro_costo_id' => $c->id,
            'partida_id' => $p->id,
            'ciclo_id' => ($ci ?? $ciclo)->id,
            'monto' => $monto,
            'descripcion' => 'Prueba de gasto',
        ]);
    };

    $gasto($centro, $manten, 12000.00);
    $gasto($centro, $manten, 3000.00);

    verificar('Con dos egresos, la suma', abs($servicio->ejercido($centro->id, $manten->id, $ciclo->id) - 15000.0) < 0.005);

    // Lo que NO debe entrar: otro centro, otra partida, otro ciclo.
    $gasto($general, $manten, 999.00);
    $gasto($centro, $sueldos, 888.00);
    $gasto($centro, $manten, 777.00, $otroCiclo);

    verificar(
        'No arrastra otro centro, otra partida ni otro ciclo',
        abs($servicio->ejercido($centro->id, $manten->id, $ciclo->id) - 15000.0) < 0.005,
        (string) $servicio->ejercido($centro->id, $manten->id, $ciclo->id)
    );

    echo PHP_EOL.'2. El tablero del ciclo'.PHP_EOL;

    $panorama = collect($servicio->panorama($ciclo->id));
    $mio = $panorama->first(fn ($f) => $f['centro_costo_id'] === $centro->id && $f['partida_id'] === $manten->id);

    verificar('El cruce presupuestado aparece', $mio !== null);
    verificar('Con lo autorizado', abs($mio['asignado'] - 50000.0) < 0.005);
    verificar('Y lo ejercido', abs($mio['ejercido'] - 15000.0) < 0.005);
    verificar('Y el disponible', abs($mio['disponible'] - 35000.0) < 0.005);
    verificar('Sin marcar excedido', $mio['excedido'] === false);
    verificar('Y dice de cuántos renglones sale', $mio['renglones'] === 2, (string) $mio['renglones']);

    /*
     * Un cruce con gasto y SIN presupuesto es lo que hay que ver: se está
     * gastando en algo que nadie autorizó. Listando sólo los presupuestados,
     * ese gasto sería invisible.
     */
    $huerfano = $panorama->first(fn ($f) => $f['centro_costo_id'] === $centro->id && $f['partida_id'] === $sueldos->id);

    verificar('Un cruce con gasto y sin presupuesto también sale', $huerfano !== null);
    verificar('Marcado como sin presupuesto', $huerfano['sin_presupuesto'] === true);
    verificar(
        'Y su disponible es NULL, no cero',
        $huerfano['disponible'] === null,
        'cero se leería como «ya no queda», que es otra cosa'
    );

    /*
     * Escrita antes como `X < X + 777`, que es cierta pase lo que pase: es el
     * patron vacuo que esta bitacora tiene documentado, y lo escribi igual. Lo
     * que de verdad hay que comprobar es que el cruce de ESTE ciclo no traiga
     * los 777 del otro.
     */
    verificar(
        'El gasto de otro ciclo no esta en este tablero',
        abs($mio['ejercido'] - 15000.0) < 0.005,
        'los 777 del otro ciclo se quedaron fuera'
    );
    verificar(
        'Pero sí en el suyo',
        collect($servicio->panorama($otroCiclo->id))->contains(fn ($f) => abs($f['ejercido'] - 777.0) < 0.005)
    );
    verificar(
        'Y el presupuesto de otro ciclo tampoco se cuela',
        ! $panorama->contains(fn ($f) => $f['asignado'] !== null && abs($f['asignado'] - 12345.0) < 0.005),
        'esta asignado en el otro ciclo, no en este'
    );
    verificar(
        'Aunque sí sale en el suyo',
        collect($servicio->panorama($otroCiclo->id))->contains(fn ($f) => $f['asignado'] !== null && abs($f['asignado'] - 12345.0) < 0.005)
    );

    echo PHP_EOL.'3. Pasarse se avisa, no se bloquea'.PHP_EOL;

    $gasto($centro, $manten, 40000.00);

    $mio = collect($servicio->panorama($ciclo->id))
        ->first(fn ($f) => $f['centro_costo_id'] === $centro->id && $f['partida_id'] === $manten->id);

    verificar('El egreso que se pasa se registra igual', abs($mio['ejercido'] - 55000.0) < 0.005);
    verificar('Y el cruce sale marcado como excedido', $mio['excedido'] === true);
    verificar('Con el disponible en negativo', $mio['disponible'] < 0, (string) $mio['disponible']);

    echo PHP_EOL.'4. Una sola cifra por cruce'.PHP_EOL;

    $peticion = Request::create('/', 'POST', [
        'centro_costo_id' => $centro->id, 'partida_id' => $manten->id,
        'ciclo_id' => $ciclo->id, 'monto' => 70000.00,
    ]);
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);

    $control->guardarPresupuesto($peticion);

    verificar(
        'Volver a asignar CORRIGE, no duplica',
        Presupuesto::where('centro_costo_id', $centro->id)->where('partida_id', $manten->id)->where('ciclo_id', $ciclo->id)->count() === 1
    );
    verificar(
        'Con la cifra nueva',
        abs((float) Presupuesto::where('centro_costo_id', $centro->id)->where('partida_id', $manten->id)->where('ciclo_id', $ciclo->id)->value('monto') - 70000.0) < 0.005
    );

    echo PHP_EOL.'5. La nómina entra como un egreso, con un acto'.PHP_EOL;

    $periodo = PeriodoNomina::create([
        'nombre' => 'Quincena de prueba',
        'fecha_inicio' => '2026-06-01',
        'fecha_fin' => '2026-06-15',
        'fecha_pago' => '2026-06-15',
        'periodicidad_sat' => '04',
        'campus_id' => $campus->id,
        'estado' => PeriodoNomina::ABIERTO,
    ]);

    $abierto = motivoDe(fn () => $servicio->traerNomina($periodo, $sueldos, $ciclo->id));

    verificar('Un periodo abierto no se trae', $abierto !== null, (string) $abierto);
    verificar('Y el motivo dice por qué', str_contains((string) $abierto, 'recalcule'));

    $periodo->update(['estado' => PeriodoNomina::CERRADO]);

    $sinRecibos = motivoDe(fn () => $servicio->traerNomina($periodo->fresh(), $sueldos, $ciclo->id));

    verificar('Un periodo sin recibos tampoco', $sinRecibos !== null, (string) $sinRecibos);

    // Dos recibos, para que el neto sea la suma.
    $expediente = App\Models\Nomina\ExpedienteLaboral::query()->first()
        ?? App\Models\Nomina\ExpedienteLaboral::create([
            'persona_id' => App\Models\Identidad\Persona::query()->value('id'),
            'numero_empleado' => 'PRB-'.uniqid(),
            'tipo_contrato_id' => DB::table('tipos_contrato')->value('id'),
            'situacion_id' => DB::table('situaciones_empleado')->value('id'),
            'fecha_ingreso' => '2026-01-01',
        ]);

    // Dos expedientes, porque `recibos_nomina` tiene un unico por (periodo,
    // expediente): con uno solo la suma no se podria comprobar.
    $segundo = App\Models\Nomina\ExpedienteLaboral::create([
        'persona_id' => App\Models\Identidad\Persona::query()->where('id', '!=', $expediente->persona_id)->value('id'),
        'numero_empleado' => 'PRB2-'.uniqid(),
        'tipo_contrato_id' => DB::table('tipos_contrato')->value('id'),
        'situacion_id' => DB::table('situaciones_empleado')->value('id'),
        'fecha_ingreso' => '2026-01-01',
    ]);

    foreach ([[$expediente, 14000.00], [$segundo, 9000.00]] as [$exp, $neto]) {
        DB::table('recibos_nomina')->insert([
            'periodo_nomina_id' => $periodo->id,
            'expediente_laboral_id' => $exp->id,
            'total_percepciones' => $neto,
            'total_deducciones' => 0,
            'neto' => $neto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $r = $servicio->traerNomina($periodo->fresh(), $sueldos, $ciclo->id);

    verificar('Cerrado y con recibos sí se trae', abs($r['neto'] - 23000.0) < 0.005, (string) $r['neto']);
    verificar('Queda como egreso', $r['egreso']->exists);
    verificar('Marcado como venido de nómina', $r['egreso']->vieneDeNomina());
    verificar('Contra el centro del campus del periodo', $r['egreso']->centro_costo_id === $centro->id);
    verificar('Y suma al ejercido de su partida', abs($servicio->ejercido($centro->id, $sueldos->id, $ciclo->id) - 23888.0) < 0.005);

    $repetida = motivoDe(fn () => $servicio->traerNomina($periodo->fresh(), $sueldos, $ciclo->id));

    verificar('La misma nómina no se trae dos veces', $repetida !== null, (string) $repetida);
    verificar('Y el mensaje nombra el egreso que ya está', str_contains((string) $repetida, '#'));

    // Un periodo de un campus SIN centro de costo: se dice, no se carga a otro.
    $huerfanoPeriodo = PeriodoNomina::create([
        'nombre' => 'Quincena sin centro',
        'fecha_inicio' => '2026-06-16',
        'fecha_fin' => '2026-06-30',
        'fecha_pago' => '2026-06-30',
        'periodicidad_sat' => '04',
        'campus_id' => $otroCampus->id,
        'estado' => PeriodoNomina::CERRADO,
    ]);

    /*
     * Con RECIBOS a proposito: sin ellos, quitarle el filtro por campus seguia
     * dando un rechazo —«no tiene recibos»— y la comprobacion pasaba por la
     * razon equivocada. Lo destapo una mutacion.
     */
    DB::table('recibos_nomina')->insert([
        'periodo_nomina_id' => $huerfanoPeriodo->id,
        'expediente_laboral_id' => $expediente->id,
        'total_percepciones' => 5000,
        'total_deducciones' => 0,
        'neto' => 5000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sinCentro = motivoDe(fn () => $servicio->traerNomina($huerfanoPeriodo->fresh(), $sueldos, $ciclo->id));

    verificar('Sin centro para ese campus, se rehúsa', $sinCentro !== null, (string) $sinCentro);
    verificar('Y el motivo es ése, no otro', str_contains((string) $sinCentro, 'centro de costo'));
    verificar(
        'En vez de cargarlo a otro centro',
        Egreso::where('origen', Egreso::ORIGEN_NOMINA)->where('origen_id', $huerfanoPeriodo->id)->count() === 0
    );

    $abiertoSiempre = PeriodoNomina::create([
        'nombre' => 'Quincena todavía abierta',
        'fecha_inicio' => '2026-07-01',
        'fecha_fin' => '2026-07-15',
        'fecha_pago' => '2026-07-15',
        'periodicidad_sat' => '04',
        'campus_id' => $campus->id,
        'estado' => PeriodoNomina::ABIERTO,
    ]);

    verificar(
        'Un periodo abierto no figura entre los pendientes',
        ! $servicio->nominasPendientes()->contains('id', $abiertoSiempre->id),
        'todavía puede cambiar de importe'
    );
    verificar(
        'Y deja de figurar como pendiente el que ya se trajo',
        ! $servicio->nominasPendientes()->contains('id', $periodo->id)
    );
    verificar(
        'Pero el que no se pudo traer sigue pendiente',
        $servicio->nominasPendientes()->contains('id', $huerfanoPeriodo->id)
    );

    echo PHP_EOL.'6. Un egreso de nómina no se toca a mano'.PHP_EOL;

    $deNomina = $r['egreso'];

    $peticionEditar = Request::create('/', 'POST', [
        'fecha' => '2026-06-20',
        'centro_costo_id' => $centro->id,
        'partida_id' => $sueldos->id,
        'ciclo_id' => $ciclo->id,
        'monto' => 1.00,
        'descripcion' => 'Cambiándole el importe a la nómina',
    ]);
    $peticionEditar->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionEditar);

    $noEdita = motivoDe(fn () => $egresos->guardar($peticionEditar, $deNomina->fresh()));

    verificar('No se le cambia el importe', $noEdita !== null, (string) $noEdita);
    verificar('Y sigue valiendo lo que la nómina', abs((float) $deNomina->fresh()->monto - 23000.0) < 0.005);

    $peticionBorrar = Request::create('/', 'DELETE');
    $peticionBorrar->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionBorrar);

    $noBorra = motivoDe(fn () => $egresos->eliminar($deNomina->fresh()));

    verificar('Ni se retira desde aquí', $noBorra !== null, (string) $noBorra);
    verificar('Sigue ahí', Egreso::find($deNomina->id) !== null);

    echo PHP_EOL.'7. Un egreso capturado SÍ se corrige'.PHP_EOL;

    $capturado = $gasto($general, $manten, 500.00);

    $peticionOk = Request::create('/', 'POST', [
        'fecha' => '2026-06-11',
        'centro_costo_id' => $general->id,
        'partida_id' => $manten->id,
        'ciclo_id' => $ciclo->id,
        'monto' => 650.00,
        'descripcion' => 'Corregido: era 650',
    ]);
    $peticionOk->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionOk);

    $egresos->guardar($peticionOk, $capturado->fresh());

    verificar('Se corrige el importe', abs((float) $capturado->fresh()->monto - 650.0) < 0.005);
    verificar('Y queda quién lo cambió', $capturado->fresh()->updated_by !== null);

    $egresos->eliminar($capturado->fresh());

    verificar('Y se retira en baja lógica', Egreso::withTrashed()->find($capturado->id)?->trashed() === true);
    verificar('Dejando de contar en el ejercido', abs($servicio->ejercido($general->id, $manten->id, $ciclo->id) - 999.0) < 0.005);

    echo PHP_EOL.'8. Las pantallas'.PHP_EOL;

    $peticion = Request::create('/', 'GET', ['ciclo' => $ciclo->id]);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);

    $props = json_decode($control->index($peticion)->toResponse($peticion)->getContent(), true)['props'];

    verificar('El presupuesto trae su tablero', count($props['panorama']) >= 2);
    verificar('Sus centros', collect($props['centros'])->contains('id', $centro->id));
    verificar('Sus partidas', collect($props['partidas'])->contains('id', $sueldos->id));
    verificar('Y los campus para elegir', is_array($props['campus']));
    verificar(
        'Con las nóminas cerradas que faltan por traer',
        collect($props['nominasPendientes'])->contains('id', $huerfanoPeriodo->id)
    );

    $peticionEg = Request::create('/', 'GET', ['ciclo' => $ciclo->id, 'partida' => $manten->id]);
    $peticionEg->headers->set('X-Inertia', 'true');
    $peticionEg->headers->set('X-Inertia-Version', '');
    $peticionEg->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionEg);

    $props = json_decode($egresos->index($peticionEg)->toResponse($peticionEg)->getContent(), true)['props'];

    verificar('Los egresos responden filtrados', collect($props['egresos'])->every(fn ($e) => $e['partida'] === $manten->nombre));
    verificar(
        'Y el total es el de lo FILTRADO, no el de la página',
        abs($props['total'] - (55000.0 + 999.0)) < 0.005,
        (string) $props['total']
    );

    /*
     * Y con MAS renglones que la pagina. Con pocos, «el total de lo filtrado» y
     * «el total de la pagina» dan lo mismo y la regla se queda sin comprobar —
     * lo destapo una mutacion. Se insertan de golpe: son de relleno.
     */
    $relleno = [];

    for ($i = 0; $i <= EgresoController::POR_PAGINA; $i++) {
        $relleno[] = [
            'fecha' => '2026-06-12',
            'centro_costo_id' => $general->id,
            'partida_id' => $manten->id,
            'ciclo_id' => $ciclo->id,
            'monto' => 1.00,
            'descripcion' => 'Relleno para pasar del tope',
            'origen' => Egreso::ORIGEN_CAPTURA,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('egresos')->insert($relleno);

    $peticionMuchos = Request::create('/', 'GET', ['ciclo' => $ciclo->id, 'partida' => $manten->id]);
    $peticionMuchos->headers->set('X-Inertia', 'true');
    $peticionMuchos->headers->set('X-Inertia-Version', '');
    $peticionMuchos->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionMuchos);

    $props = json_decode($egresos->index($peticionMuchos)->toResponse($peticionMuchos)->getContent(), true)['props'];

    verificar(
        'La página se topa',
        count($props['egresos']) === EgresoController::POR_PAGINA,
        (string) count($props['egresos'])
    );
    verificar(
        'Pero el total suma TODO lo filtrado',
        abs($props['total'] - (55000.0 + 999.0 + (EgresoController::POR_PAGINA + 1))) < 0.005,
        (string) $props['total']
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
