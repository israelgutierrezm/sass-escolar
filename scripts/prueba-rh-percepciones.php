<?php

/**
 * Módulo 10 · Nómina y RH, segunda rebanada — esquemas de percepción y
 * conceptos. Con rollback.
 *
 * Se corre con `php scripts/prueba-rh-percepciones.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. La modalidad se lee por sus BANDERAS, no por su clave: una modalidad
 *     nueva armada desde la pantalla tiene que funcionar. Si el motor
 *     reconociera «por_hora» por su nombre, el catálogo sería decorativo.
 *  2. Las banderas EXIGEN su dato, y mayor que cero. Un esquema por horas con
 *     la tarifa en blanco —o en cero— pagaría cero y el recibo saldría sin un
 *     solo error: es el defecto que no se descubre hasta el día de pago.
 *  3. Los componentes que la modalidad NO usa se guardan en NULL, no con lo que
 *     venga en la petición: si no, cambiar de modalidad aplicaría una tarifa
 *     que nadie volvió a autorizar.
 *  4. Un solo esquema abierto por expediente. Abrir uno cierra el anterior el
 *     día ANTES: dos esquemas no pueden cubrir la misma fecha.
 *  5. El anterior se conserva, y se consulta POR FECHA: un recibo de la
 *     quincena pasada usa el sueldo que regía entonces, no el de hoy.
 *  6. No se le fija sueldo a quien está dado de baja.
 *  7. Una modalidad sin ningún componente pagaría cero y se rechaza, tanto al
 *     guardarla en el catálogo como al colgarle un esquema.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\Rh\PercepcionController;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Nomina\ConceptoNomina;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\ModalidadPercepcion;
use App\Models\Nomina\MotivoBajaLaboral;
use App\Models\Nomina\SituacionEmpleado;
use App\Models\Nomina\TipoContrato;
use App\Models\Tenant;
use App\Services\Nomina\RegistroLaboral;
use App\Services\Nomina\RegistroPercepciones;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

function peticionDe(Usuario $usuario, string $metodo = 'POST', array $datos = []): Request
{
    $peticion = Request::create('/prueba', $metodo, $datos);
    $peticion->setUserResolver(fn () => $usuario);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

try {
    $registro = app(RegistroPercepciones::class);
    $laboral = app(RegistroLaboral::class);
    $control = app(PercepcionController::class);
    $staff = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    echo PHP_EOL.'0. La modalidad se lee por sus banderas'.PHP_EOL;

    $fijo = ModalidadPercepcion::query()->where('clave', 'fijo_mensual')->firstOrFail();
    $porHora = ModalidadPercepcion::query()->where('clave', 'por_hora')->firstOrFail();
    $mixto = ModalidadPercepcion::query()->where('clave', 'mixto')->firstOrFail();

    verificar('«fijo mensual» sólo usa el monto base', $fijo->componentes() === ['monto_base']);
    verificar('«por hora» sólo usa la tarifa por hora', $porHora->componentes() === ['tarifa_hora']);
    // «Mixto» NO es un cuarto caso del motor: es una fila con dos banderas.
    verificar('«mixto» son dos banderas, no un caso especial',
        $mixto->componentes() === ['monto_base', 'tarifa_asignatura'],
        implode('+', $mixto->componentes()));

    echo PHP_EOL.'1. El escenario'.PHP_EOL;

    $contrato = TipoContrato::query()->activos()->firstOrFail();
    $activo = SituacionEmpleado::query()->where('clave', 'activo')->firstOrFail();

    $conExpediente = ExpedienteLaboral::query()->pluck('persona_id');
    $gente = Persona::query()->whereNotIn('id', $conExpediente)->take(2)->get();

    verificar('hay dos personas del directorio con las que trabajar', $gente->count() === 2);

    $marca = 'PERC-'.substr((string) microtime(true), -6);

    $expediente = ExpedienteLaboral::create([
        'persona_id' => $gente[0]->id,
        'numero_empleado' => $marca.'-A',
        'tipo_contrato_id' => $contrato->id,
        'situacion_id' => $activo->id,
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
    ]);

    verificar('el expediente nace sin esquema', $expediente->esquemas()->count() === 0);
    verificar('y sin sueldo en ninguna fecha', $expediente->esquemaEn(now()->toDateString()) === null);

    echo PHP_EOL.'2. Las banderas exigen su dato, y mayor que cero'.PHP_EOL;

    $sinTarifa = null;

    try {
        $registro->abrir($expediente, $porHora, now()->subYear()->toDateString(), []);
    } catch (RuntimeException $e) {
        $sinTarifa = $e->getMessage();
    }

    verificar('por horas sin tarifa se rechaza', $sinTarifa !== null);
    verificar('y el mensaje nombra el componente que falta',
        str_contains((string) $sinTarifa, 'tarifa por hora'), (string) $sinTarifa);

    /*
     * Y CERO no cuenta como capturado: un esquema por horas a cero pagaría cero
     * y el recibo saldría, con el neto en nada y sin un solo error por ningún
     * lado.
     */
    $enCero = false;

    try {
        $registro->abrir($expediente, $porHora, now()->subYear()->toDateString(), ['tarifa_hora' => 0]);
    } catch (RuntimeException) {
        $enCero = true;
    }

    verificar('y en cero tampoco pasa', $enCero);
    verificar('no quedó ningún esquema colgando', $expediente->esquemas()->count() === 0);

    echo PHP_EOL.'3. Lo que la modalidad no usa se guarda en NULL'.PHP_EOL;

    $primero = $registro->abrir($expediente, $fijo, now()->subYear()->toDateString(), [
        'monto_base' => 18000,
        // Se manda a propósito una tarifa que esta modalidad NO usa.
        'tarifa_hora' => 250,
        'tarifa_asignatura' => 3000,
    ]);

    verificar('guardó el monto base', (float) $primero->monto_base === 18000.0);
    verificar('y descartó la tarifa por hora que no usa', $primero->tarifa_hora === null);
    verificar('y la de asignatura también', $primero->tarifa_asignatura === null);
    verificar('queda abierto', $primero->estaAbierto());

    echo PHP_EOL.'4. Un solo esquema abierto'.PHP_EOL;

    $haceSeisMeses = now()->subMonths(6)->toDateString();
    $segundo = $registro->abrir($expediente, $mixto, $haceSeisMeses, [
        'monto_base' => 9000,
        'tarifa_asignatura' => 2500,
    ]);

    verificar('el nuevo queda abierto', $segundo->estaAbierto());
    verificar('y el anterior se cerró',
        ! $primero->refresh()->estaAbierto(), (string) $primero->vigente_hasta);

    // El día ANTES: dos esquemas no pueden cubrir la misma fecha.
    verificar('se cerró el día antes de que empiece el nuevo',
        $primero->vigente_hasta->toDateString()
            === Carbon::parse($haceSeisMeses)->subDay()->toDateString(),
        $primero->vigente_hasta->toDateString());

    verificar('sólo hay uno abierto', $expediente->esquemas()->abiertos()->count() === 1);
    verificar('y el anterior se conservó', $expediente->esquemas()->count() === 2);

    echo PHP_EOL.'5. El sueldo se consulta POR FECHA'.PHP_EOL;

    // Un recibo de hace un año tiene que usar el sueldo que regía ENTONCES.
    $entonces = $expediente->esquemaEn(now()->subMonths(9)->toDateString());
    $ahora = $expediente->esquemaEn(now()->toDateString());

    verificar('hace nueve meses regía el primero', (int) $entonces?->id === (int) $primero->id);
    verificar('y hoy rige el segundo', (int) $ahora?->id === (int) $segundo->id);
    verificar('antes de contratarlo no había ninguno',
        $expediente->esquemaEn(now()->subYears(3)->toDateString()) === null);

    // Y no se puede abrir uno que empiece antes del vigente: cerrar el viejo le
    // pondría un fin anterior a su propio inicio.
    $haciaAtras = false;

    try {
        $registro->abrir($expediente, $fijo, now()->subYear()->toDateString(), ['monto_base' => 1000]);
    } catch (RuntimeException) {
        $haciaAtras = true;
    }

    verificar('no se abre uno que empiece antes del vigente', $haciaAtras);

    echo PHP_EOL.'6. Se corrigen las CIFRAS, no las fechas'.PHP_EOL;

    $registro->corregir($segundo, ['monto_base' => 9500, 'tarifa_asignatura' => 2800]);

    verificar('la cifra quedó corregida', (float) $segundo->refresh()->monto_base === 9500.0);
    verificar('y la vigencia no se movió',
        $segundo->vigente_desde->toDateString() === $haceSeisMeses);

    // La corrección respeta las mismas reglas: no se puede dejar en cero.
    $corregirEnCero = false;

    try {
        $registro->corregir($segundo, ['monto_base' => 0, 'tarifa_asignatura' => 2800]);
    } catch (RuntimeException) {
        $corregirEnCero = true;
    }

    verificar('corregir a cero se rechaza igual', $corregirEnCero);

    echo PHP_EOL.'7. Una modalidad sin componentes pagaría cero'.PHP_EOL;

    $vacia = ModalidadPercepcion::create([
        'clave' => 'vacia-'.substr((string) microtime(true), -5),
        'nombre' => 'Sin componentes',
        'usa_monto_base' => false,
        'usa_tarifa_hora' => false,
        'usa_tarifa_asignatura' => false,
        'activo' => true,
    ]);

    verificar('el modelo la reconoce como inutilizable', ! $vacia->esUtilizable());

    $conVacia = false;

    try {
        $registro->abrir($expediente, $vacia, now()->toDateString(), ['monto_base' => 5000]);
    } catch (RuntimeException) {
        $conVacia = true;
    }

    verificar('y no se le puede colgar un esquema', $conVacia);

    // Y la pantalla de catálogos tampoco la deja guardar así.
    $control->guardarModalidad(peticionDe($staff, 'POST', [
        'clave' => 'otra-'.substr((string) microtime(true), -5),
        'nombre' => 'Tampoco',
        'usa_monto_base' => false,
        'usa_tarifa_hora' => false,
        'usa_tarifa_asignatura' => false,
    ]));

    verificar('el catálogo no guarda una sin componentes',
        ModalidadPercepcion::query()->where('nombre', 'Tampoco')->doesntExist());

    echo PHP_EOL.'8. Una modalidad NUEVA funciona sin tocar código'.PHP_EOL;

    /*
     * Es lo que separa un catálogo de verdad de uno decorativo: si el motor
     * reconociera «por_hora» por su nombre, esta combinación no serviría para
     * nada aunque la pantalla la dejara crear.
     */
    $inventada = ModalidadPercepcion::create([
        'clave' => 'base_horas_'.substr((string) microtime(true), -5),
        'nombre' => 'Base más horas',
        'usa_monto_base' => true,
        'usa_tarifa_hora' => true,
        'usa_tarifa_asignatura' => false,
        'activo' => true,
    ]);

    verificar('declara sus dos componentes',
        $inventada->componentes() === ['monto_base', 'tarifa_hora']);

    $conInventada = $registro->abrir($expediente, $inventada, now()->toDateString(), [
        'monto_base' => 7000,
        'tarifa_hora' => 180,
    ]);

    verificar('el esquema se abre con la modalidad inventada', $conInventada->exists);
    verificar('y guarda sus dos cifras',
        (float) $conInventada->monto_base === 7000.0 && (float) $conInventada->tarifa_hora === 180.0);
    verificar('sin la de asignatura, que no usa', $conInventada->tarifa_asignatura === null);

    $faltaUna = false;

    try {
        $registro->abrir($expediente, $inventada, now()->addDay()->toDateString(), ['monto_base' => 7000]);
    } catch (RuntimeException) {
        $faltaUna = true;
    }

    verificar('y exige las DOS, no una', $faltaUna);

    echo PHP_EOL.'9. A quien está de baja no se le fija sueldo'.PHP_EOL;

    $motivo = MotivoBajaLaboral::query()->activos()->firstOrFail();
    $laboral->darDeBaja($expediente->refresh(), now()->toDateString(), (int) $motivo->id);

    $aUnBaja = false;

    try {
        $registro->abrir($expediente->refresh(), $fijo, now()->addDay()->toDateString(), ['monto_base' => 5000]);
    } catch (RuntimeException) {
        $aUnBaja = true;
    }

    verificar('se rechaza', $aUnBaja);
    verificar('y sus esquemas anteriores siguen ahí',
        $expediente->esquemas()->count() === 3, (string) $expediente->esquemas()->count());

    /*
     * Y la baja CIERRA el esquema abierto, igual que las adscripciones.
     *
     * Uno abierto sobre alguien que ya no trabaja aquí contestaría «gana tanto»
     * a una pregunta sobre una fecha en la que ya no ganaba nada. Salió de una
     * mutación que sobrevivió: sin ningún tramo cerrado SIN SUCESOR, la consulta
     * por fecha nunca ejercitaba su fecha de fin.
     */
    verificar('la baja cerró el esquema que estaba abierto',
        $expediente->esquemas()->abiertos()->count() === 0);
    verificar('el día de la baja todavía tenía sueldo',
        $expediente->esquemaEn(now()->toDateString()) !== null);
    verificar('pero al día siguiente ya no',
        $expediente->esquemaEn(now()->addDay()->toDateString()) === null);

    echo PHP_EOL.'10. El catálogo de conceptos'.PHP_EOL;

    $percepciones = ConceptoNomina::query()->activos()->percepciones()->count();
    $deducciones = ConceptoNomina::query()->activos()->deducciones()->count();

    verificar('hay percepciones y deducciones sembradas',
        $percepciones > 0 && $deducciones > 0, "{$percepciones} / {$deducciones}");

    $isr = ConceptoNomina::query()->where('clave', 'isr')->firstOrFail();

    verificar('el ISR resta', ! $isr->suma());
    verificar('y el sueldo suma',
        ConceptoNomina::query()->where('clave', 'sueldo')->firstOrFail()->suma());

    echo PHP_EOL.'11. La pantalla'.PHP_EOL;

    $props = json_decode(
        $control->index($expediente->refresh())->toResponse(
            tap(Request::create('/prueba', 'GET'), function ($p) use ($staff) {
                $p->setUserResolver(fn () => $staff);
                $p->headers->set('X-Inertia', 'true');
                $p->headers->set('X-Inertia-Version', '');
            })
        )->getContent(),
        true,
    )['props'];

    verificar('el historial trae los tres esquemas', count($props['esquemas']) === 3);
    verificar('el más reciente va primero',
        (int) $props['esquemas'][0]['id'] === (int) $conInventada->id);

    // La pantalla recibe QUÉ componentes usa cada uno, para no pintar una
    // tarifa por hora en un sueldo fijo.
    $delFijo = collect($props['esquemas'])->firstWhere('id', $primero->id);

    verificar('y dice qué componentes usa cada esquema',
        ($delFijo['componentes'] ?? []) === ['monto_base'],
        implode('+', $delFijo['componentes'] ?? []));
    verificar('la pantalla avisa de que está dado de baja',
        ($props['expediente']['vigente'] ?? true) === false);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
