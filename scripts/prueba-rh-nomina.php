<?php

/**
 * Módulo 10 · Nómina y RH, tercera rebanada — el periodo, el recibo y el
 * cálculo. Con rollback.
 *
 * Se corre con `php scripts/prueba-rh-nomina.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. El recibo se MATERIALIZA: cambiar el sueldo después NO cambia lo ya
 *     calculado. Es un hecho fechado que hay que poder explicar en cinco años.
 *  2. El sueldo se resuelve al FIN DEL PERIODO, no a hoy: un periodo viejo se
 *     paga con lo que regía entonces.
 *  3. Lo que suma sale de las BANDERAS de la modalidad, así que una armada
 *     desde la pantalla produce sus renglones sin tocar el motor.
 *  4. Una entrada de reloj SIN SALIDA no se paga y se REPORTA. Contarla hasta
 *     el fin del día pagaría horas no trabajadas; ignorarla en silencio deja al
 *     empleado sin saber por qué le faltan horas.
 *  5. Lo que no se puede calcular se ANOTA: sin sueldo fijado sale el recibo en
 *     ceros con el motivo, no un cero mudo.
 *  6. Recalcular avisa de cuántos renglones capturados A MANO se lleva:
 *     perderlos en silencio es pagarle de más a alguien.
 *  7. Un empleado, un recibo por periodo. Y el periodo cerrado no se toca.
 *  8. La fórmula se aplica sobre lo GRAVABLE, con su tope, y el ISR NO se
 *     calcula solo.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Models\Asistencia\Checada;
use App\Models\Identidad\Persona;
use App\Models\Nomina\ConceptoNomina;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\FormulaNomina;
use App\Models\Nomina\ModalidadPercepcion;
use App\Models\Nomina\PeriodoNomina;
use App\Models\Nomina\SituacionEmpleado;
use App\Models\Nomina\TipoContrato;
use App\Models\Tenant;
use App\Services\Nomina\CalculadoraNomina;
use App\Services\Nomina\ContadorHoras;
use App\Services\Nomina\RegistroLaboral;
use App\Services\Nomina\RegistroPercepciones;
use Illuminate\Contracts\Console\Kernel;
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

$db->beginTransaction();

try {
    $calculadora = app(CalculadoraNomina::class);
    $percepciones = app(RegistroPercepciones::class);
    $contador = app(ContadorHoras::class);

    $contrato = TipoContrato::query()->activos()->firstOrFail();
    $activo = SituacionEmpleado::query()->where('clave', 'activo')->firstOrFail();
    $sinGoce = SituacionEmpleado::query()->where('clave', 'licencia_sin_goce')->firstOrFail();
    $fijo = ModalidadPercepcion::query()->where('clave', 'fijo_mensual')->firstOrFail();
    $porHora = ModalidadPercepcion::query()->where('clave', 'por_hora')->firstOrFail();

    $conExpediente = ExpedienteLaboral::query()->pluck('persona_id');
    $gente = Persona::query()->whereNotIn('id', $conExpediente)->take(4)->get();

    verificar('hay cuatro personas del directorio con las que trabajar', $gente->count() === 4);

    $marca = 'NOM-'.substr((string) microtime(true), -6);

    $nuevo = fn (Persona $p, string $sufijo, $situacion) => ExpedienteLaboral::create([
        'persona_id' => $p->id,
        'numero_empleado' => $marca.'-'.$sufijo,
        'tipo_contrato_id' => $contrato->id,
        'situacion_id' => $situacion->id,
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
    ]);

    $conSueldo = $nuevo($gente[0], 'FIJO', $activo);
    $porHoras = $nuevo($gente[1], 'HORA', $activo);
    $sinSueldo = $nuevo($gente[2], 'SIN', $activo);
    $deLicencia = $nuevo($gente[3], 'LIC', $sinGoce);

    // El periodo es una quincena de hace dos meses: así se puede comprobar que
    // el sueldo se resuelve a ESA fecha y no a hoy.
    $inicio = now()->subMonths(2)->startOfMonth();
    $fin = (clone $inicio)->addDays(14);

    $percepciones->abrir($conSueldo, $fijo, $inicio->copy()->subMonth()->toDateString(), ['monto_base' => 30000]);
    $percepciones->abrir($porHoras, $porHora, $inicio->copy()->subMonth()->toDateString(), ['tarifa_hora' => 200]);
    $percepciones->abrir($deLicencia, $fijo, $inicio->copy()->subMonth()->toDateString(), ['monto_base' => 12000]);

    echo PHP_EOL.'1. Quiénes entran al periodo'.PHP_EOL;

    $periodo = PeriodoNomina::create([
        'nombre' => 'Quincena de prueba',
        'fecha_inicio' => $inicio->toDateString(),
        'fecha_fin' => $fin->toDateString(),
        'estado' => PeriodoNomina::ABIERTO,
    ]);

    $elegibles = $calculadora->elegibles($periodo)->pluck('id');

    verificar('entra quien tiene sueldo', $elegibles->contains($conSueldo->id));
    verificar('entra quien no lo tiene fijado —para que salga su incidencia—',
        $elegibles->contains($sinSueldo->id));
    // La licencia SIN GOCE sigue contratada y no cobra: es la que delata a
    // quien filtre por `clave = 'activo'` o sólo por la fecha de baja.
    verificar('NO entra quien está de licencia sin goce', ! $elegibles->contains($deLicencia->id));

    echo PHP_EOL.'2. Las checadas: lo que no cierra no se paga, y se reporta'.PHP_EOL;

    $checar = function (int $personaId, string $momento, string $tipo) {
        Checada::create([
            'persona_id' => $personaId,
            'tipo_movimiento' => $tipo,
            'momento' => $momento,
            'origen' => 'prueba',
        ]);
    };

    $d1 = $inicio->copy()->addDay()->toDateString();
    $d2 = $inicio->copy()->addDays(2)->toDateString();
    $d3 = $inicio->copy()->addDays(3)->toDateString();

    $checar($porHoras->persona_id, "{$d1} 08:00:00", Checada::ENTRADA);
    $checar($porHoras->persona_id, "{$d1} 13:00:00", Checada::SALIDA);   // 5 h
    $checar($porHoras->persona_id, "{$d2} 09:00:00", Checada::ENTRADA);
    $checar($porHoras->persona_id, "{$d2} 12:30:00", Checada::SALIDA);   // 3.5 h
    // Se le olvidó checar la salida: NO se paga y se avisa.
    $checar($porHoras->persona_id, "{$d3} 08:00:00", Checada::ENTRADA);

    $medido = $contador->contar((int) $porHoras->persona_id, $periodo->fecha_inicio->toDateString(), $periodo->fecha_fin->toDateString());

    verificar('cuenta 8.5 horas', abs($medido['horas'] - 8.5) < 0.001, (string) $medido['horas']);
    verificar('y reporta la que quedó abierta', count($medido['sin_cerrar']) === 1,
        implode(',', $medido['sin_cerrar']));

    echo PHP_EOL.'3. El cálculo'.PHP_EOL;

    $resultado = $calculadora->calcular($periodo);

    verificar('se hicieron tres recibos', $resultado['recibos'] === 3, (string) $resultado['recibos']);
    verificar('el periodo quedó calculado', $periodo->refresh()->estado === PeriodoNomina::CALCULADO);
    verificar('sin renglones manuales que perder', $resultado['manuales_borrados'] === 0);

    $recibos = $periodo->recibos()->get()->keyBy('expediente_laboral_id');

    // Quincena de 15 días sobre un mensual de 30 000: la mitad.
    $rFijo = $recibos[$conSueldo->id];

    verificar('el sueldo fijo se prorrateó a la quincena',
        abs((float) $rFijo->total_percepciones - 15000.0) < 0.01, (string) $rFijo->total_percepciones);

    // 8.5 h × 200 = 1 700. La entrada sin salida NO se pagó.
    $rHoras = $recibos[$porHoras->id];
    $renglonHoras = $rHoras->conceptos()->first();

    verificar('las horas se pagaron a su tarifa',
        abs((float) $renglonHoras->importe - 1700.0) < 0.01, (string) $renglonHoras->importe);
    verificar('con la cantidad de horas anotada',
        abs((float) $renglonHoras->cantidad - 8.5) < 0.001, (string) $renglonHoras->cantidad);
    verificar('y la checada abierta quedó como incidencia',
        str_contains((string) $rHoras->incidencias, 'sin cerrar'), (string) $rHoras->incidencias);

    // Sin sueldo fijado sale el recibo en ceros CON el motivo: uno que no
    // aparece se confunde con alguien a quien no le tocaba cobrar.
    $rSin = $recibos[$sinSueldo->id];

    verificar('quien no tiene sueldo sale en ceros', (float) $rSin->neto === 0.0);
    verificar('pero con el motivo escrito',
        str_contains((string) $rSin->incidencias, 'sueldo fijado'), (string) $rSin->incidencias);
    verificar('y el cálculo los contó', $resultado['con_incidencias'] === 2, (string) $resultado['con_incidencias']);

    echo PHP_EOL.'4. La fórmula se aplica sobre lo gravable'.PHP_EOL;

    $imss = ConceptoNomina::query()->where('clave', 'imss')->firstOrFail();
    $formula = FormulaNomina::query()->where('clave', 'imss_obrero')->firstOrFail();

    verificar('el concepto de IMSS tiene fórmula', $imss->formula_id !== null);
    verificar('y su base es lo gravable', $formula->base === FormulaNomina::BASE_GRAVABLE);

    $deduccion = $rFijo->conceptos()->where('concepto_nomina_id', $imss->id)->first();

    verificar('el recibo trae su renglón de IMSS', $deduccion !== null);
    verificar('por el 2.75 % de las percepciones gravables',
        $deduccion !== null && abs((float) $deduccion->importe - round(15000 * 0.0275, 2)) < 0.01,
        (string) $deduccion?->importe);
    verificar('y el neto descuenta la deducción',
        abs((float) $rFijo->refresh()->neto - (15000 - round(15000 * 0.0275, 2))) < 0.01,
        (string) $rFijo->neto);

    /*
     * El ISR NO se calcula solo: su tarifa es por rangos y un factor plano daría
     * un número que alguien enteraría al SAT. Se captura a mano.
     */
    $isr = ConceptoNomina::query()->where('clave', 'isr')->firstOrFail();

    verificar('el ISR NO tiene fórmula', $isr->formula_id === null);
    verificar('y no aparece en el recibo',
        $rFijo->conceptos()->where('concepto_nomina_id', $isr->id)->doesntExist());

    echo PHP_EOL.'5. El recibo está MATERIALIZADO'.PHP_EOL;

    $antes = (float) $rFijo->refresh()->total_percepciones;

    // Se le sube el sueldo DESPUÉS de calcular. El recibo ya emitido no puede
    // moverse: es un hecho fechado.
    $percepciones->abrir($conSueldo, $fijo, now()->toDateString(), ['monto_base' => 60000]);

    verificar('subirle el sueldo no mueve el recibo ya calculado',
        (float) $rFijo->refresh()->total_percepciones === $antes, (string) $rFijo->total_percepciones);
    verificar('y el recibo recuerda con qué esquema se calculó',
        $rFijo->esquema_percepcion_id !== null);

    /*
     * Y el sueldo se resuelve al FIN DEL PERIODO. Recalcular ahora tiene que
     * seguir dando 15 000 —el sueldo viejo— y no 30 000, porque el aumento
     * empieza hoy y el periodo terminó hace dos meses.
     */
    $calculadora->calcular($periodo->refresh());
    $rFijo = $periodo->recibos()->where('expediente_laboral_id', $conSueldo->id)->firstOrFail();

    verificar('recalcular usa el sueldo que regía ENTONCES, no el de hoy',
        abs((float) $rFijo->total_percepciones - 15000.0) < 0.01, (string) $rFijo->total_percepciones);

    echo PHP_EOL.'6. Los renglones capturados a mano'.PHP_EOL;

    $prestamo = ConceptoNomina::query()->where('clave', 'prestamo')->firstOrFail();

    $rFijo->conceptos()->create([
        'concepto_nomina_id' => $prestamo->id,
        'importe' => 1200,
        'manual' => true,
        'orden' => 999,
    ]);

    $rFijo->recalcularTotales();

    verificar('la deducción a mano baja el neto',
        abs((float) $rFijo->refresh()->neto - (15000 - round(15000 * 0.0275, 2) - 1200)) < 0.01,
        (string) $rFijo->neto);

    // Recalcular se los lleva, y LO DICE: perderlos en silencio es pagarle de
    // más a alguien.
    $conManuales = $calculadora->calcular($periodo->refresh());

    verificar('recalcular avisa de cuántos se llevó', $conManuales['manuales_borrados'] === 1,
        (string) $conManuales['manuales_borrados']);
    verificar('y efectivamente ya no está',
        $periodo->recibos()->where('expediente_laboral_id', $conSueldo->id)->firstOrFail()
            ->conceptos()->where('manual', true)->doesntExist());

    echo PHP_EOL.'7. Un empleado, un recibo por periodo'.PHP_EOL;

    verificar('no hay expedientes repetidos en el periodo',
        $periodo->recibos()->count() === $periodo->recibos()->distinct()->count('expediente_laboral_id'));

    $duplicado = false;

    try {
        $periodo->recibos()->create(['expediente_laboral_id' => $conSueldo->id]);
    } catch (Throwable) {
        $duplicado = true;
    }

    verificar('y la base lo impide', $duplicado);

    echo PHP_EOL.'8. Un periodo cerrado no se toca'.PHP_EOL;

    $periodo->update(['estado' => PeriodoNomina::CERRADO]);

    $cerrado = false;

    try {
        $calculadora->calcular($periodo->refresh());
    } catch (RuntimeException) {
        $cerrado = true;
    }

    verificar('no se recalcula', $cerrado);
    verificar('y sus recibos siguen ahí', $periodo->recibos()->count() === 3);

    echo PHP_EOL.'9. El periodo por campus'.PHP_EOL;

    $campusId = DB::connection('tenant')->table('campus')->whereNull('deleted_at')->value('id');

    $porCampus = PeriodoNomina::create([
        'nombre' => 'Quincena de un campus',
        'fecha_inicio' => $inicio->toDateString(),
        'fecha_fin' => $fin->toDateString(),
        'campus_id' => $campusId,
        'estado' => PeriodoNomina::ABIERTO,
    ]);

    /*
     * Nadie está adscrito a ese campus todavía, así que no entra nadie. Es la
     * comprobación que importa: con el periodo global entraban tres.
     */
    verificar('acotado a un campus, sin adscritos no entra nadie',
        $calculadora->elegibles($porCampus)->count() === 0,
        (string) $calculadora->elegibles($porCampus)->count());

    app(RegistroLaboral::class)->adscribir(
        $conSueldo->refresh(),
        (int) DB::connection('tenant')->table('puestos')->value('id'),
        (int) $campusId,
        $inicio->toDateString(),
        null,
        true,
    );

    verificar('y con uno adscrito, entra ése', $calculadora->elegibles($porCampus)->count() === 1);

    echo PHP_EOL.'10. Gravable NO es lo mismo que percepción'.PHP_EOL;

    /*
     * Hace falta un recibo con una percepción GRAVABLE y otra que NO lo sea: con
     * todas gravables, «lo gravable» y «el total de percepciones» dan el mismo
     * número y la base de la fórmula da igual cuál sea. Se descubrió mutando —el
     * cambio de base no tumbaba nada— y es justo el caso que en una escuela real
     * aparece el primer mes que alguien reciba vales de despensa.
     */
    $conHoras = ModalidadPercepcion::create([
        'clave' => 'base_horas_'.substr((string) microtime(true), -5),
        'nombre' => 'Base más horas',
        'usa_monto_base' => true,
        'usa_tarifa_hora' => true,
        'usa_tarifa_asignatura' => false,
        'activo' => true,
    ]);

    $conceptoHoras = ConceptoNomina::query()->where('clave', 'horas_trabajadas')->firstOrFail();
    $conceptoHoras->update(['es_gravable' => false]);

    $mixtoExp = $nuevo(Persona::query()->whereNotIn('id', ExpedienteLaboral::query()->pluck('persona_id'))->firstOrFail(), 'MIX', $activo);
    $percepciones->abrir($mixtoExp, $conHoras, $inicio->copy()->subMonth()->toDateString(), [
        'monto_base' => 30000,
        'tarifa_hora' => 200,
    ]);

    $checar($mixtoExp->persona_id, "{$d1} 08:00:00", Checada::ENTRADA);
    $checar($mixtoExp->persona_id, "{$d1} 18:00:00", Checada::SALIDA);   // 10 h → 2 000

    $otro = PeriodoNomina::create([
        'nombre' => 'Quincena con no gravable',
        'fecha_inicio' => $inicio->toDateString(),
        'fecha_fin' => $fin->toDateString(),
        'estado' => PeriodoNomina::ABIERTO,
    ]);

    $calculadora->calcular($otro);

    $rMixto = $otro->recibos()->where('expediente_laboral_id', $mixtoExp->id)->firstOrFail();

    verificar('cobra la base Y las horas',
        abs((float) $rMixto->total_percepciones - 17000.0) < 0.01, (string) $rMixto->total_percepciones);

    $suImss = $rMixto->conceptos()->where('concepto_nomina_id', $imss->id)->first();

    // 2.75 % de 15 000 (sólo lo gravable) = 412.50. Sobre las percepciones
    // completas —17 000— habrían salido 467.50.
    verificar('la fórmula grava SÓLO la parte gravable',
        $suImss !== null && abs((float) $suImss->importe - round(15000 * 0.0275, 2)) < 0.01,
        (string) $suImss?->importe);
    verificar('y NO el total de percepciones',
        $suImss !== null && abs((float) $suImss->importe - round(17000 * 0.0275, 2)) > 0.01);

    echo PHP_EOL.'11. El tope de la fórmula'.PHP_EOL;

    // Una cuota topada: sin respetar el tope, a quien gana más se le
    // descontaría de más y nadie lo notaría hasta que reclamara.
    $formula->update(['tope' => 100]);
    $calculadora->calcular($otro->refresh());

    $topado = $otro->recibos()->where('expediente_laboral_id', $mixtoExp->id)->firstOrFail()
        ->conceptos()->where('concepto_nomina_id', $imss->id)->first();

    verificar('se respeta el tope', $topado !== null && abs((float) $topado->importe - 100.0) < 0.01,
        (string) $topado?->importe);
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
