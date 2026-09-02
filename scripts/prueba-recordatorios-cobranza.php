<?php

/**
 * Recordatorios de cobranza.
 *
 * `php scripts/prueba-recordatorios-cobranza.php` desde la raíz. Contra la BD
 * real del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Lo primero, que un peldaño se mande UNA vez: un recordatorio que llega
 * treinta días seguidos deja de leerse al tercero, y ése es el defecto que hace
 * inservible todo lo demás. Después, que quien debe varios cargos reciba UN
 * aviso y no uno por cargo, con el texto del peldaño más severo.
 *
 * Y que apagadas no se mande NADA: las tres sembradas nacen apagadas a
 * propósito, y una escuela recién migrada no puede empezar avisándole de su
 * deuda a las familias con la cartera a medio cargar.
 *
 * ── El escenario se construye ENTERO, y parte de cero peldaños ─────────────
 * Lo que se mide es a quién le toca qué día, y eso sólo se puede afirmar
 * sabiendo qué escalera hay. Sin vaciar, la suite pasaría sola y se caería el
 * día que la escuela configure el primero.
 *
 * Los `use` van ARRIBA del arranque a propósito.
 */

use App\Enums\DestinoEvento;
use App\Http\Controllers\CobranzaController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\RecordatorioCobranza;
use App\Models\Finanzas\ReglaRecordatorioCobranza;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Tenant;
use App\Services\Finanzas\RecordatorioDeCobranza;
use Carbon\CarbonImmutable;
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

DB::beginTransaction();

try {
    $servicio = app(RecordatorioDeCobranza::class);
    $control = app(CobranzaController::class);

    echo '0. Escenario'.PHP_EOL;

    $usuario = Usuario::findOrFail(1);
    Auth::login($usuario);

    $sembradas = ReglaRecordatorioCobranza::query()->count();
    ReglaRecordatorioCobranza::query()->update(['activo' => false]);

    /*
     * La LÍNEA BASE de rastros. La escuela puede llevar meses mandando
     * recordatorios, así que contra cero esta suite pasaría sola y se caería en
     * el barrido — que es exactamente lo que pasó en cuanto se corrió el comando
     * de verdad contra el demo para mirar la pantalla. Se mide por diferencia.
     */
    $rastrosBase = RecordatorioCobranza::query()->count();

    verificar('Se parte sin ningún peldaño encendido', ReglaRecordatorioCobranza::query()->activas()->count() === 0, "la escuela tenía {$sembradas}");
    verificar('Y las sembradas nacen apagadas', $sembradas >= 3);

    $matricula = MatriculaOferta::query()->with('persona')->orderBy('id')->firstOrFail();
    $otra = MatriculaOferta::query()->where('id', '!=', $matricula->id)->orderBy('id')->firstOrFail();
    $colegiatura = ConceptoPago::query()->where('clave', 'colegiatura')->firstOrFail();

    /*
     * El plan y su línea se CONSTRUYEN. Los del demo apuntan a un plan que ya
     * no existe —restos de una resiembra con las foráneas apagadas—, así que
     * `whereHas('conceptoPlan.plan')` los descarta con razón y el escenario
     * habría medido «no le toca a nadie» creyendo que probaba algo.
     */
    $ciclo = App\Models\ControlEscolar\Ciclo::query()->orderByDesc('id')->firstOrFail();

    $planDuro = App\Models\Finanzas\PlanCobro::create([
        'nombre' => 'Plan de prueba que sí hace deudor',
        'ciclo_id' => $ciclo->id,
        'tiene_fecha_limite' => true,
        'fecha_limite_modo' => 'dia_marcado',
        'aplica_recargos' => false,
        'afecta_estatus_deudor' => true,
        'moneda' => 'MXN',
        'vigente_desde' => '2026-01-01',
    ]);

    $linea = ConceptoPlan::create([
        'plan_cobro_id' => $planDuro->id,
        'concepto_id' => $colegiatura->id,
        'monto' => 1000.00,
        'aplica_recargos' => false,
    ]);

    verificar('El plan del escenario sí hace deudor', $linea->plan?->afecta_estatus_deudor === true);

    $hoy = CarbonImmutable::parse('2026-06-15');

    $cargo = function (MatriculaOferta $m, string $vence, float $monto = 1000.00, ?int $lineaId = null) use ($colegiatura) {
        return Adeudo::create([
            'matricula_oferta_id' => $m->id,
            'concepto_id' => $colegiatura->id,
            'concepto_plan_id' => $lineaId,
            'periodo_etiqueta' => 'PRUEBA-COB-'.uniqid(),
            'monto' => $monto,
            'monto_total' => $monto,
            'fecha_generacion' => '2026-05-01',
            'fecha_vencimiento' => $vence,
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);
    };

    // Dos cargos que vencieron hace ocho días, y uno que vence en tres.
    $v1 = $cargo($matricula, '2026-06-06', 1200.00, $linea->id);
    $v2 = $cargo($matricula, '2026-06-06', 800.00, $linea->id);
    $porVencer = $cargo($matricula, '2026-06-18', 500.00, $linea->id);

    echo PHP_EOL.'1. Sin peldaños encendidos no se manda nada'.PHP_EOL;

    $r = $servicio->correr($hoy);

    verificar('Cero avisos', $r['avisos'] === 0, 'avisos: '.$r['avisos']);
    verificar('Y ningún rastro nuevo', RecordatorioCobranza::query()->count() === $rastrosBase);

    echo PHP_EOL.'2. Un peldaño alcanza la fecha EXACTA'.PHP_EOL;

    $ocho = ReglaRecordatorioCobranza::create([
        'nombre' => 'Prueba 9 días',
        'dias' => 9,
        'titulo' => 'Debes {monto}',
        'cuerpo' => "Hola {alumno}: tienes {cargos} cargo(s) por {monto}, con {dias} día(s) de atraso. Matrícula {matricula}, vencía el {vence}.",
        'prioridad' => 'importante',
        'dias_vigente' => 20,
        'activo' => true,
    ]);

    $avisosAntes = Aviso::query()->count();
    $r = $servicio->correr($hoy);

    verificar('Le toca a un alumno', $r['alumnos'] === 1, 'alumnos: '.$r['alumnos']);
    verificar('Con UN aviso, no uno por cargo', $r['avisos'] === 1, 'avisos: '.$r['avisos']);
    verificar('Y cubre los dos cargos vencidos', $r['cargos'] === 2, 'cargos: '.$r['cargos']);
    verificar('Se creó un solo aviso', Aviso::query()->count() === $avisosAntes + 1);

    $aviso = Aviso::query()->latest('id')->firstOrFail();

    verificar('El título rellena sus tokens', str_contains($aviso->titulo, '$2,000.00'), $aviso->titulo);
    verificar('Y el cuerpo también', str_contains($aviso->cuerpo, '2 cargo(s)') && str_contains($aviso->cuerpo, '9 día(s)'));
    verificar('Con el nombre del alumno', str_contains($aviso->cuerpo, (string) $matricula->persona?->nombreCompleto()));
    verificar('No quedó ningún token sin rellenar', ! str_contains($aviso->cuerpo, '{'), $aviso->cuerpo);

    verificar(
        'Le llega al alumno señalado',
        $aviso->destinos()->where('tipo', DestinoEvento::Alumno)->where('destino_id', $matricula->persona_id)->exists()
    );
    verificar(
        'Y a su familia',
        $aviso->destinos()->where('tipo', DestinoEvento::Familiares)->exists(),
        'quien paga la colegiatura es la familia'
    );
    verificar(
        'Caduca a los días del peldaño',
        $aviso->vigente_hasta?->toDateString() === $hoy->addDays(20)->toDateString(),
        (string) $aviso->vigente_hasta
    );

    // El cargo que vence en tres días NO entra: su peldaño no existe.
    verificar('El que vence en tres días no se tocó', ! RecordatorioCobranza::query()->where('adeudo_id', $porVencer->id)->exists());

    echo PHP_EOL.'3. El mismo peldaño no se repite al día siguiente'.PHP_EOL;

    $r = $servicio->correr($hoy);

    verificar('Correrlo otra vez el mismo día no manda nada', $r['avisos'] === 0, 'avisos: '.$r['avisos']);

    $r = $servicio->correr($hoy->addDay());

    verificar('Ni al día siguiente', $r['avisos'] === 0, 'avisos: '.$r['avisos']);
    verificar('Los rastros nuevos siguen siendo dos', RecordatorioCobranza::query()->count() === $rastrosBase + 2);

    echo PHP_EOL.'4. Otro peldaño sí vuelve a avisar'.PHP_EOL;

    $treinta = ReglaRecordatorioCobranza::create([
        'nombre' => 'Prueba 31 días',
        'dias' => 31,
        'titulo' => 'Tu adeudo lleva {dias} días',
        'cuerpo' => 'Hola {alumno}: {cargos} cargo(s) por {monto}.',
        'prioridad' => 'critico',
        'dias_vigente' => 30,
        'activo' => true,
    ]);

    $r = $servicio->correr(CarbonImmutable::parse('2026-07-07'));

    verificar('Al peldaño siguiente vuelve a tocarle', $r['avisos'] === 1, 'avisos: '.$r['avisos']);
    verificar('Y son dos rastros más', RecordatorioCobranza::query()->count() === $rastrosBase + 4);

    $ultimo = Aviso::query()->latest('id')->firstOrFail();

    verificar('Con el texto del peldaño nuevo', str_contains($ultimo->titulo, '31 días'), $ultimo->titulo);
    verificar('Y su prioridad', $ultimo->prioridad->value === 'critico', (string) $ultimo->prioridad->value);

    echo PHP_EOL.'5. Con dos peldaños el mismo día, manda el MÁS SEVERO'.PHP_EOL;

    // Un tercer cargo que cae a la vez en el peldaño de 8 y otro en el de 30.
    $a = $cargo($otra, '2026-06-06', 700.00, $linea->id);   // 9 días al 15/06
    $b = $cargo($otra, '2026-05-15', 300.00, $linea->id);   // 31 días al 15/06

    $antes = Aviso::query()->count();
    $r = $servicio->correr($hoy);

    verificar('Recibe un solo aviso', $r['avisos'] === 1, 'avisos: '.$r['avisos']);
    verificar('Que cubre los dos cargos', $r['cargos'] === 2, 'cargos: '.$r['cargos']);
    verificar('Y se creó uno solo', Aviso::query()->count() === $antes + 1);

    $mezcla = Aviso::query()->latest('id')->firstOrFail();

    verificar(
        'Con el texto del peldaño de 31, no el de 9',
        str_contains($mezcla->titulo, '31 días'),
        $mezcla->titulo
    );
    verificar('Y suma los dos importes', str_contains($mezcla->cuerpo, '$1,000.00'), $mezcla->cuerpo);

    echo PHP_EOL.'6. Lo que NO se recuerda'.PHP_EOL;

    $pagado = $cargo($matricula, '2026-06-06', 400.00, $linea->id);
    $pagado->update(['estatus' => Adeudo::ESTATUS_PAGADO]);

    $enConvenio = $cargo($matricula, '2026-06-06', 450.00, $linea->id);
    $enConvenio->update(['estatus' => Adeudo::ESTATUS_EN_CONVENIO]);

    $servicio->correr($hoy);

    verificar('Un cargo pagado no se recuerda', ! RecordatorioCobranza::query()->where('adeudo_id', $pagado->id)->exists());
    verificar(
        'Ni uno que cubre un convenio',
        ! RecordatorioCobranza::query()->where('adeudo_id', $enConvenio->id)->exists(),
        'lo que se cobra son sus parcialidades'
    );

    // Un plan que la escuela declaró que NO hace deudor a nadie.
    $planSuave = App\Models\Finanzas\PlanCobro::create([
        'nombre' => 'Plan de prueba sin estatus',
        'ciclo_id' => $ciclo->id,
        'tiene_fecha_limite' => true,
        'fecha_limite_modo' => 'dia_marcado',
        'aplica_recargos' => false,
        'afecta_estatus_deudor' => false,
        'moneda' => 'MXN',
        'vigente_desde' => '2026-01-01',
    ]);
    $lineaSuave = ConceptoPlan::create([
        'plan_cobro_id' => $planSuave->id,
        'concepto_id' => $colegiatura->id,
        'monto' => 250.00,
        'aplica_recargos' => false,
    ]);
    $suave = $cargo($matricula, '2026-06-06', 250.00, $lineaSuave->id);

    $servicio->correr($hoy);

    verificar(
        'Ni un cargo de un plan que no hace deudor',
        ! RecordatorioCobranza::query()->where('adeudo_id', $suave->id)->exists(),
        '`afecta_estatus_deudor` es la respuesta declarada de la escuela'
    );

    // Pero uno SIN plan sí: una parcialidad de convenio, un trámite suelto.
    $sinPlan = $cargo($matricula, '2026-06-06', 150.00);

    $servicio->correr($hoy);

    verificar(
        'Pero uno SIN plan sí se recuerda',
        RecordatorioCobranza::query()->where('adeudo_id', $sinPlan->id)->exists(),
        'la bandera es un opt-out de los planes que la llevan, no un requisito'
    );

    echo PHP_EOL.'7. Apagar un peldaño lo detiene'.PHP_EOL;

    $ocho->update(['activo' => false]);
    $treinta->update(['activo' => false]);

    $nuevo = $cargo($matricula, '2026-06-06', 999.00, $linea->id);
    $r = $servicio->correr($hoy);

    verificar('Apagados, no se manda nada', $r['avisos'] === 0, 'avisos: '.$r['avisos']);
    verificar('Y el cargo nuevo queda sin rastro', ! RecordatorioCobranza::query()->where('adeudo_id', $nuevo->id)->exists());

    echo PHP_EOL.'8. El modo seco no escribe'.PHP_EOL;

    $ocho->update(['activo' => true]);

    $avisosAntes = Aviso::query()->count();
    $rastrosAntes = RecordatorioCobranza::query()->count();
    $seco = $servicio->correr($hoy, seco: true);

    verificar('Dice a quién le tocaría', $seco['alumnos'] >= 1, 'alumnos: '.$seco['alumnos']);
    verificar('Y con qué peldaño', collect($seco['detalle'])->every(fn ($d) => $d['regla'] !== ''));
    verificar('Sin crear avisos', Aviso::query()->count() === $avisosAntes);
    verificar('Ni rastros', RecordatorioCobranza::query()->count() === $rastrosAntes);

    echo PHP_EOL.'9. La pantalla'.PHP_EOL;

    $peticion = Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);

    $props = json_decode($control->index()->toResponse($peticion)->getContent(), true)['props'];

    verificar('Trae la escalera', count($props['reglas']) >= 2);
    verificar('Ordenada por días', collect($props['reglas'])->pluck('dias')->toArray() === collect($props['reglas'])->pluck('dias')->sort()->values()->toArray());
    verificar('Dice si hay alguna encendida', $props['hayEncendidas'] === true);
    verificar('Y la vista previa de a quién le llegaría hoy', is_array($props['previo']));
    verificar('Con el conteo de lo ya enviado', collect($props['reglas'])->firstWhere('id', $ocho->id)['emitidos'] >= 2);

    // Dos peldaños el mismo día se pisarían: uno no se mandaría nunca.
    $peticionDup = Request::create('/', 'POST', [
        'nombre' => 'Otro de nueve',
        'dias' => 9,
        'titulo' => 'x',
        'cuerpo' => 'y',
        'prioridad' => 'informativo',
        'dias_vigente' => 10,
        'activo' => '1',
    ]);
    $peticionDup->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionDup);

    $duplicado = null;
    try {
        $control->guardar($peticionDup);
    } catch (Illuminate\Validation\ValidationException $e) {
        // Con JSON_UNESCAPED_UNICODE: `json_encode` escapa la eñe a `ñ` y
        // buscar «peldaño» dentro no encontraba nada — la comprobación fallaba
        // por su propia codificación, no por el código.
        $duplicado = json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    verificar('Dos peldaños el mismo día se rechazan', $duplicado !== null, (string) $duplicado);
    verificar('Con un mensaje que explica por qué', str_contains((string) $duplicado, 'peldaño'));

    // Y la casilla llega como cadena: validar no convierte.
    $peticionAlta = Request::create('/', 'POST', [
        'nombre' => 'De prueba apagado',
        'dias' => 47,
        'titulo' => 'x',
        'cuerpo' => 'y',
        'prioridad' => 'informativo',
        'dias_vigente' => 10,
        'activo' => '0',
    ]);
    $peticionAlta->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionAlta);
    $control->guardar($peticionAlta);

    $creado = ReglaRecordatorioCobranza::where('dias', 47)->firstOrFail();

    verificar('«0» apaga el peldaño de verdad', $creado->activo === false, var_export($creado->activo, true));

    echo PHP_EOL.'10. Retirar un peldaño conserva lo ya enviado'.PHP_EOL;

    $peticionBorrar = Request::create('/', 'DELETE');
    $peticionBorrar->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionBorrar);

    $rastros = RecordatorioCobranza::query()->where('regla_id', $ocho->id)->count();
    $control->eliminar($ocho);

    verificar('El peldaño se da de baja lógica', ReglaRecordatorioCobranza::withTrashed()->find($ocho->id)?->trashed() === true);
    verificar('Y sus recordatorios se conservan', RecordatorioCobranza::query()->where('regla_id', $ocho->id)->count() === $rastros, "rastros: {$rastros}");
    verificar('Retirado, deja de mandar', $servicio->correr($hoy)['avisos'] === 0);

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
