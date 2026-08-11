<?php

/**
 * Prueba de integración del MOTOR DE COBRO: expansión de colegiaturas,
 * asignación del plan, generación idempotente de cargos, becas y descuentos,
 * recargo por mora y estado de cuenta. Con rollback.
 *
 * Se corre con `php scripts/prueba-cobro.php` desde la raíz.
 *
 * ── Por qué esta suite está reescrita de cero ─────────────────────────────
 * La anterior probaba el motor ABSTRACTO de «periodicidad + día del mes»:
 * `PeriodosCobro`, `ReglaGeneracion`, `RecargoDescuento`,
 * `AplicadorRecargosDescuentos`. Ese motor se cambió entero en `86a3899` por
 * uno de LÍNEAS FECHADAS —`conceptos_plan`, una fila por cargo, expandidas
 * desde un rango— y la suite se quedó importando clases borradas: moría en el
 * primer `app(PeriodosCobro::class)` con «Target class does not exist».
 *
 * O sea que llevaba meses figurando como suite verde en la documentación y sin
 * ejecutar una sola comprobación. Eso es peor que no tenerla: una suite rota
 * que nadie corre es cobertura que se cree tener.
 *
 * No toca a ningún usuario existente ni le cambia el rol activo a nadie: crea
 * sus propias personas y su propio plan.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\PlanCobroAlumno;
use App\Models\Finanzas\ReglaRecargo;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\CalculadorCargo;
use App\Services\CalculadorRecargos;
use App\Services\EstadoCuenta;
use App\Services\ExpansorColegiaturas;
use App\Services\GeneradorAdeudos;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use App\Services\ResolutorPlanCobro;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
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
        echo "  OK   {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

DB::beginTransaction();

try {
    $expansor = app(ExpansorColegiaturas::class);
    $generador = app(GeneradorAdeudos::class);
    $resolutor = app(ResolutorPlanCobro::class);
    $calculador = app(CalculadorCargo::class);
    $recargos = app(CalculadorRecargos::class);
    $registrador = app(RegistradorPago::class);
    $estadoCuenta = app(EstadoCuenta::class);

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $inscripcion = ConceptoPago::where('clave', 'inscripcion')->firstOrFail();
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();

    $sufijo = substr((string) microtime(true), -6);

    // ── Un alumno propio ───────────────────────────────────────────────────
    $oferta = Oferta::firstOrFail();
    $persona = Persona::create(['nombre' => 'Cobro', 'primer_apellido' => 'Prueba'.$sufijo, 'sexo_id' => 1]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, $oferta);

    echo '1. Una colegiatura se captura por RANGO y se expande en líneas'.PHP_EOL;

    $plan = PlanCobro::create([
        'nombre' => 'Plan de prueba '.$sufijo,
        'moneda' => 'MXN',
        'tiene_fecha_limite' => true,
        'fecha_limite_modo' => PlanCobro::LIMITE_EXACTA,
        'aplica_recargos' => true,
        'afecta_estatus_deudor' => true,
        'vigente_desde' => '2026-01-01',
    ]);

    $lineas = $expansor->expandir($plan, [
        'concepto_id' => $colegiatura->id,
        'descripcion' => null,
        'monto' => 2000,
        'desde' => '2026-03-05',
        'cantidad' => 3,
        'cadencia' => ExpansorColegiaturas::CADA_MES,
        'dia_limite' => 5,
        'aplica_recargos' => true,
    ]);

    verificar('Tres meses producen tres líneas', count($lineas) === 3, (string) count($lineas));
    verificar('Con su mes y año de referencia, no una etiqueta suelta',
        $lineas[0]['mes_referencia'] === 3 && $lineas[0]['anio_referencia'] === 2026
        && $lineas[2]['mes_referencia'] === 5,
        $lineas[0]['mes_referencia'].'/'.$lineas[0]['anio_referencia']);
    verificar('Todas comparten el grupo de colegiatura, que es lo que las hace UN cobro',
        count(array_unique(array_column($lineas, 'grupo_colegiatura'))) === 1);
    verificar('Y cada una su fecha límite',
        $lineas[0]['fecha_limite'] === '2026-03-05' && $lineas[1]['fecha_limite'] === '2026-04-05',
        $lineas[0]['fecha_limite'].' → '.$lineas[1]['fecha_limite']);

    // La cadencia no es un caso del código: semanal es el mismo mecanismo.
    $semanales = $expansor->expandir($plan, [
        'concepto_id' => $colegiatura->id,
        'descripcion' => null,
        'monto' => 500,
        'desde' => '2026-03-02',
        'cantidad' => 4,
        'cadencia' => ExpansorColegiaturas::CADA_SEMANA,
        'dia_limite' => null,
        'aplica_recargos' => false,
    ]);

    verificar('La cadencia semanal avanza de siete en siete',
        abs(CarbonImmutable::parse($semanales[1]['fecha_limite'])
            ->diffInDays(CarbonImmutable::parse($semanales[0]['fecha_limite']))) === 7.0,
        $semanales[0]['fecha_limite'].' → '.$semanales[1]['fecha_limite']);

    // Un plan que NO admite recargos no puede tener líneas que sí, por más que
    // venga marcado desde el formulario: la regla del plan manda.
    $planSinMora = PlanCobro::create([
        'nombre' => 'Sin mora '.$sufijo,
        'moneda' => 'MXN',
        'tiene_fecha_limite' => true,
        'fecha_limite_modo' => PlanCobro::LIMITE_EXACTA,
        'aplica_recargos' => false,
        'afecta_estatus_deudor' => false,
        'vigente_desde' => '2026-01-01',
    ]);

    $sinMora = $expansor->expandir($planSinMora, [
        'concepto_id' => $colegiatura->id,
        'descripcion' => null,
        'monto' => 100,
        'desde' => '2026-03-01',
        'cantidad' => 1,
        'cadencia' => ExpansorColegiaturas::CADA_MES,
        'dia_limite' => 5,
        'aplica_recargos' => true,
    ]);

    verificar('Un plan sin recargos no admite líneas con recargo',
        $sinMora[0]['aplica_recargos'] === false);

    echo PHP_EOL.'2. Los cargos se generan una vez, y solo una'.PHP_EOL;

    foreach ($lineas as $linea) {
        ConceptoPlan::create($linea);
    }

    ConceptoPlan::create([
        'plan_cobro_id' => $plan->id,
        'concepto_id' => $inscripcion->id,
        'tipo_pago' => ConceptoPlan::TIPO_INSCRIPCION,
        'monto' => 3000,
        'mes_referencia' => 3,
        'anio_referencia' => 2026,
        'fecha_limite' => '2026-03-05',
        'aplica_recargos' => false,
        'obligatorio' => true,
        'orden' => 0,
    ]);

    PlanCobroAlumno::create([
        'plan_cobro_id' => $plan->id,
        'matricula_oferta_id' => $matricula->id,
        'estatus' => PlanCobroAlumno::ACTIVO,
        'asignado_en' => now(),
    ]);

    verificar('El resolutor devuelve el plan asignado',
        $resolutor->planesDe($matricula)->pluck('id')->contains($plan->id));

    $creados = $generador->generarCargos($plan->fresh('conceptos'), $matricula);

    verificar('Genera un cargo por línea', $creados === 4, (string) $creados);

    $repetido = $generador->generarCargos($plan->fresh('conceptos'), $matricula);

    verificar('Volver a generar no duplica nada', $repetido === 0, (string) $repetido);

    /*
     * Inscripción y colegiatura del MISMO mes conviven.
     *
     * Es la trampa que costó un `Duplicate entry` en producción: el índice único
     * quedó un tiempo sobre (matrícula, periodo) y una matrícula sólo admitía UN
     * cargo por periodo. Ahora la terna incluye la línea del plan.
     */
    $deMarzo = Adeudo::where('matricula_oferta_id', $matricula->id)
        ->where('periodo_etiqueta', 'like', '%2026%')
        ->whereIn('concepto_id', [$colegiatura->id, $inscripcion->id])
        ->count();

    verificar('Inscripción y colegiatura del mismo mes conviven', $deMarzo >= 2, (string) $deMarzo);

    echo PHP_EOL.'3. La beca descuenta al generar, y deja dicho por qué'.PHP_EOL;

    $beca = Beca::create([
        'clave' => 'prueba'.$sufijo,
        'nombre' => 'Beca de prueba',
        'modo' => Beca::MODO_PORCENTAJE,
        // FRACCIÓN, no 0–100: `descuentoSobre()` multiplica sin dividir entre
        // cien, y la pantalla pinta `valor * 100`. Poner 50 aquí sería una beca
        // del 5000%, que se topa contra la base y deja el cargo en cero.
        'valor' => 0.5,
        'activo' => true,
    ]);
    $beca->conceptos()->sync([$colegiatura->id]);

    BecaAlumno::create([
        'matricula_oferta_id' => $matricula->id,
        'beca_id' => $beca->id,
        'estatus' => BecaAlumno::ACTIVA,
        'vigente_desde' => '2026-01-01',
    ]);

    $lineaColegiatura = ConceptoPlan::where('plan_cobro_id', $plan->id)
        ->where('tipo_pago', ConceptoPlan::TIPO_COLEGIATURA)
        ->firstOrFail();

    $calculo = $calculador->para($lineaColegiatura, $matricula->fresh());

    verificar('La mitad de 2000 es 1000', (float) $calculo['total'] === 1000.0, (string) $calculo['total']);
    verificar('Y el ajuste dice de qué beca salió',
        collect($calculo['ajustes'])->contains(fn ($a) => $a['etiqueta'] === 'Beca de prueba'));

    $lineaInscripcion = ConceptoPlan::where('plan_cobro_id', $plan->id)
        ->where('tipo_pago', ConceptoPlan::TIPO_INSCRIPCION)
        ->firstOrFail();

    verificar('Una beca que cubre colegiatura NO toca la inscripción',
        (float) $calculador->para($lineaInscripcion, $matricula->fresh())['total'] === 3000.0);

    /*
     * Los cargos YA EMITIDOS no cambian solos: la beca se otorgó después de
     * generarlos y el adeudo es una foto de lo que se le cobró. Para bajarlos
     * hay que recalcular, y eso toca sólo los pendientes —el dinero que ya
     * entró no se reescribe—.
     */
    $adeudoColegiatura = Adeudo::where('matricula_oferta_id', $matricula->id)
        ->where('concepto_plan_id', $lineaColegiatura->id)
        ->firstOrFail();

    verificar('El cargo emitido antes de la beca sigue en su monto',
        (float) $adeudoColegiatura->monto_total === 2000.0, (string) $adeudoColegiatura->monto_total);

    $recalculados = $generador->recalcularPendientes($matricula->fresh());

    verificar('Recalcular baja los pendientes', $recalculados > 0, (string) $recalculados);
    verificar('Y ahora el cargo trae la beca aplicada',
        (float) $adeudoColegiatura->fresh()->monto_total === 1000.0,
        (string) $adeudoColegiatura->fresh()->monto_total);

    echo PHP_EOL.'4. La mora corre desde el vencimiento, con su gracia'.PHP_EOL;

    ReglaRecargo::create([
        'plan_cobro_id' => $plan->id,
        'modo' => ReglaRecargo::MODO_PORCENTAJE,
        'valor' => 0.10, // misma convención que la beca: fracción, no 0–100
        'frecuencia' => ReglaRecargo::FRECUENCIA_UNICA,
        'dias_gracia' => 3,
        'activo' => true,
    ]);

    $adeudoConMora = Adeudo::where('matricula_oferta_id', $matricula->id)
        ->where('concepto_plan_id', $lineaColegiatura->id)
        ->firstOrFail();

    $vence = CarbonImmutable::parse($adeudoConMora->fecha_vencimiento);

    verificar('El día del vencimiento todavía no hay recargo',
        $recargos->recargoPara($adeudoConMora, $vence) === 0.0);
    verificar('Dentro de los días de gracia, tampoco',
        $recargos->recargoPara($adeudoConMora, $vence->addDays(3)) === 0.0);

    $conMora = $recargos->recargoPara($adeudoConMora, $vence->addDays(10));

    verificar('Pasada la gracia sí, y sobre el capital que se debe', $conMora > 0, (string) $conMora);

    // El recargo se calcula sobre lo que se debe DE VERDAD: la beca bajó el
    // cargo a la mitad, así que el 10% es de la mitad. Cobrarlo sobre el monto
    // de lista sería cobrar mora por dinero que nunca se adeudó.
    verificar('El 10% se calcula sobre el monto ya becado, no sobre el de lista',
        abs($conMora - 100.0) < 0.01, (string) $conMora);

    echo PHP_EOL.'5. El estado de cuenta cuadra con lo que se pagó'.PHP_EOL;

    $antes = $estadoCuenta->para($matricula->fresh());
    $saldoInicial = (float) $antes['resumen']['saldo'];

    verificar('Arranca debiendo lo generado', $saldoInicial > 0, (string) $saldoInicial);

    $aPagar = Adeudo::where('matricula_oferta_id', $matricula->id)
        ->where('concepto_plan_id', $lineaInscripcion->id)
        ->firstOrFail();

    $registrador->registrar($matricula->fresh(), $efectivo, (float) $aPagar->monto_total, [$aPagar->id]);

    verificar('El adeudo pagado queda liquidado',
        $aPagar->fresh()->estatus === Adeudo::ESTATUS_PAGADO, (string) $aPagar->fresh()->estatus);

    $despues = $estadoCuenta->para($matricula->fresh());

    verificar('Y el saldo baja exactamente lo pagado',
        abs(($saldoInicial - (float) $despues['resumen']['saldo']) - (float) $aPagar->monto_total) < 0.01,
        $saldoInicial.' → '.$despues['resumen']['saldo']);

    echo PHP_EOL.'6. Un plan que no alcanza al alumno no se le asigna'.PHP_EOL;

    // El alcance por campus: un plan acotado a otro campus no cubre a este
    // alumno aunque alguien lo intente asignar.
    $otroCampus = DB::table('campus')->where('id', '!=', $matricula->oferta->campus_id)->value('id');

    if ($otroCampus !== null) {
        $planAjeno = PlanCobro::create([
            'nombre' => 'De otro campus '.$sufijo,
            'moneda' => 'MXN',
            'tiene_fecha_limite' => false,
            'fecha_limite_modo' => PlanCobro::LIMITE_EXACTA,
            'aplica_recargos' => false,
            'afecta_estatus_deudor' => false,
            'vigente_desde' => '2026-01-01',
        ]);
        $planAjeno->campus()->sync([$otroCampus]);

        verificar('Un plan de otro campus NO alcanza a este alumno',
            ! $resolutor->alcanzaA($planAjeno->fresh(), $matricula->fresh()));
    }

    verificar('Y el suyo sí', $resolutor->alcanzaA($plan->fresh(), $matricula->fresh()));
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $f) {
    echo '  - '.$f.PHP_EOL;
}
