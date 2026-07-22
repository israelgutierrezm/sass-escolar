<?php

/**
 * Prueba de integración del motor de cobro (entrega 7.2): resolución del plan
 * aplicable, calendario de periodos, generación idempotente, prorrateo,
 * prerrequisitos, becas, recargos por mora y aplicación de pagos. Con rollback.
 *
 * Se corre con `php scripts/prueba-cobro.php` desde la raíz.
 *
 * No toca a ningún usuario existente ni le cambia el rol activo: crea sus
 * propias personas y no necesita sesión.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\RecargoDescuento;
use App\Models\Finanzas\ReglaGeneracion;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\AplicadorRecargosDescuentos;
use App\Services\EstadoCuenta;
use App\Services\GeneradorAdeudos;
use App\Services\MatriculadorOferta;
use App\Services\PeriodosCobro;
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
    $periodos = app(PeriodosCobro::class);
    $resolutor = app(ResolutorPlanCobro::class);
    $generador = app(GeneradorAdeudos::class);
    $aplicador = app(AplicadorRecargosDescuentos::class);
    $registrador = app(RegistradorPago::class);
    $estadoCuenta = app(EstadoCuenta::class);

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $inscripcion = ConceptoPago::where('clave', 'inscripcion')->firstOrFail();
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $transferencia = MetodoPago::where('clave', 'transferencia')->firstOrFail();

    echo '1. El calendario: de la regla a los periodos'.PHP_EOL;

    $planTmp = PlanCobro::create([
        'nombre' => 'Prueba calendario',
        'moneda' => 'MXN',
        'aplica_a_tipo' => PlanCobro::APLICA_GLOBAL,
        'vigente_desde' => '2026-01-01',
    ]);

    $mensual = $planTmp->reglas()->create([
        'concepto_id' => $colegiatura->id,
        'periodicidad' => ReglaGeneracion::PERIODICIDAD_MENSUAL,
        'monto_base' => 2000,
        'dia_generacion' => 25,
        'dia_limite' => 5,
    ]);

    $tres = $periodos->para($mensual, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-05-31'));

    verificar('Tres meses producen tres periodos', count($tres) === 3, (string) count($tres));
    verificar('Con etiquetas en español y estables', $tres[0]->etiqueta === 'Marzo 2026', $tres[0]->etiqueta);
    verificar('Se emite el día configurado',
        $tres[0]->generacion->toDateString() === '2026-03-25', $tres[0]->generacion->toDateString());
    verificar('Un límite anterior al día de emisión vence el mes SIGUIENTE',
        $tres[0]->vencimiento->toDateString() === '2026-04-05', $tres[0]->vencimiento->toDateString());

    // Febrero no tiene 31: el día se recorta al último real en vez de
    // desbordarse a marzo.
    $reglaFin = $planTmp->reglas()->create([
        'concepto_id' => $colegiatura->id,
        'periodicidad' => ReglaGeneracion::PERIODICIDAD_MENSUAL,
        'monto_base' => 100,
        'dia_generacion' => 31,
    ]);
    $feb = $periodos->para($reglaFin, CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-28'));

    verificar('Día 31 en febrero se recorta al último día del mes',
        $feb[0]->generacion->toDateString() === '2026-02-28', $feb[0]->generacion->toDateString());

    $reglaParcial = $planTmp->reglas()->create([
        'concepto_id' => $inscripcion->id,
        'periodicidad' => ReglaGeneracion::PERIODICIDAD_UNICO,
        'monto_base' => 1000,
        'num_parcialidades' => 3,
    ]);
    $parcialidades = $periodos->para($reglaParcial, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-12-31'));

    verificar('Un pago único se parte en las parcialidades pedidas', count($parcialidades) === 3);
    verificar('Y las parcialidades SUMAN el total, sin perder centavos',
        round(array_sum(array_map(fn ($p) => $p->monto, $parcialidades)), 2) === 1000.0,
        implode(' + ', array_map(fn ($p) => (string) $p->monto, $parcialidades)));
    verificar('El sobrante va a la PRIMERA, no a la última',
        $parcialidades[0]->monto >= $parcialidades[2]->monto);

    $reglaSemanal = $planTmp->reglas()->create([
        'concepto_id' => $colegiatura->id,
        'periodicidad' => ReglaGeneracion::PERIODICIDAD_SEMANAL,
        'monto_base' => 500,
    ]);
    $semanas = $periodos->para($reglaSemanal, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-29'));

    verificar('Cuatro semanas producen cuatro periodos', count($semanas) === 4, (string) count($semanas));
    verificar('Con etiqueta de semana ISO y año ISO',
        str_starts_with($semanas[0]->etiqueta, 'Semana '), $semanas[0]->etiqueta);
    verificar('Las etiquetas semanales no se repiten',
        count(array_unique(array_map(fn ($p) => $p->etiqueta, $semanas))) === 4);

    echo PHP_EOL.'2. Gana el plan de cobro MÁS ESPECÍFICO'.PHP_EOL;

    $oferta = Oferta::firstOrFail();

    $persona = Persona::create(['nombre' => 'Adriana', 'primer_apellido' => 'Nájera', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, $oferta, '2026-2030');
    $matricula->update(['fecha_ingreso' => '2026-03-01']);
    $matricula->refresh();

    $global = PlanCobro::create([
        'nombre' => 'General de la escuela',
        'moneda' => 'MXN',
        'aplica_a_tipo' => PlanCobro::APLICA_GLOBAL,
        'vigente_desde' => '2026-01-01',
    ]);

    $planTmp->update(['vigente_hasta' => '2026-01-02']); // sale de escena

    verificar('Sin plan específico, aplica el global',
        $resolutor->para($matricula, CarbonImmutable::parse('2026-06-01'))?->id === $global->id);

    $porOferta = PlanCobro::create([
        'nombre' => 'Especial de esta oferta',
        'moneda' => 'MXN',
        'aplica_a_tipo' => PlanCobro::APLICA_OFERTA,
        'aplica_a_id' => $oferta->id,
        'vigente_desde' => '2026-01-01',
    ]);

    verificar('Con uno de oferta, ese gana al global',
        $resolutor->para($matricula, CarbonImmutable::parse('2026-06-01'))?->id === $porOferta->id);

    $porOferta->update(['vigente_hasta' => '2026-01-05']);

    verificar('Un plan fuera de vigencia no se elige aunque sea más específico',
        $resolutor->para($matricula, CarbonImmutable::parse('2026-06-01'))?->id === $global->id);

    echo PHP_EOL.'3. Generación: idempotente y con prerrequisito'.PHP_EOL;

    $reglaInscripcion = $global->reglas()->create([
        'concepto_id' => $inscripcion->id,
        'periodicidad' => ReglaGeneracion::PERIODICIDAD_UNICO,
        'monto_base' => 3000,
        'dia_limite' => 10,
    ]);

    // La colegiatura NO debe emitirse mientras la inscripción esté sin pagar.
    $reglaColegiatura = $global->reglas()->create([
        'concepto_id' => $colegiatura->id,
        'periodicidad' => ReglaGeneracion::PERIODICIDAD_MENSUAL,
        'monto_base' => 2000,
        'dia_generacion' => 1,
        'dia_limite' => 10,
        'concepto_prerequisito_id' => $inscripcion->id,
    ]);

    $corte = CarbonImmutable::parse('2026-06-15');
    $r1 = $generador->generarPara($matricula, $corte);

    verificar('Se emite la inscripción', $r1['generados'] === 1, $r1['generados'].' generados');
    verificar('Pero NO las colegiaturas: falta pagar la inscripción',
        Adeudo::deMatricula($matricula->id)->where('concepto_id', $colegiatura->id)->count() === 0);
    verificar('Y se explica por qué', $r1['motivos'] !== [], implode(' ', $r1['motivos']));

    // Segunda corrida: no debe duplicar.
    $r2 = $generador->generarPara($matricula, $corte);

    verificar('Volver a correr no genera nada', $r2['generados'] === 0);
    verificar('Sigue habiendo un solo cargo', Adeudo::deMatricula($matricula->id)->count() === 1);

    // Se paga la inscripción y ahora sí deben salir las colegiaturas.
    $adeudoInscripcion = Adeudo::deMatricula($matricula->id)->firstOrFail();
    $registrador->registrar($matricula->id, $efectivo, (float) $adeudoInscripcion->monto_total);

    verificar('El pago liquida el cargo y el estatus se DERIVA, no se captura',
        $adeudoInscripcion->fresh()->estatus === Adeudo::ESTATUS_PAGADO);

    $r3 = $generador->generarPara($matricula, $corte);

    verificar('Ahora sí se emiten las colegiaturas de marzo a junio',
        $r3['generados'] === 4, $r3['generados'].' generados');
    verificar('Con las etiquetas del calendario',
        Adeudo::deMatricula($matricula->id)
            ->where('concepto_id', $colegiatura->id)
            ->pluck('periodo_etiqueta')->sort()->values()->all()
        === ['Abril 2026', 'Junio 2026', 'Marzo 2026', 'Mayo 2026']);

    $r4 = $generador->generarPara($matricula, $corte);
    verificar('Y repetir sigue sin duplicar', $r4['generados'] === 0);

    echo PHP_EOL.'4. La base impide el cargo duplicado aunque el código falle'.PHP_EOL;

    $duplicado = false;
    try {
        Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $colegiatura->id,
            'regla_id' => $reglaColegiatura->id,
            'periodo_etiqueta' => 'Marzo 2026',
            'monto' => 2000, 'monto_total' => 2000,
            'fecha_generacion' => '2026-03-01',
            'fecha_vencimiento' => '2026-03-10',
        ]);
    } catch (Throwable) {
        $duplicado = true;
    }
    verificar('Insertar el mismo (matrícula, regla, periodo) se rechaza', $duplicado);

    echo PHP_EOL.'5. Prorrateo al ingresar a media periodicidad'.PHP_EOL;

    $persona2 = Persona::create(['nombre' => 'Bruno', 'primer_apellido' => 'Cortés', 'sexo_id' => 1]);
    $ofertaB = Oferta::where('id', '!=', $oferta->id)->first() ?? $oferta;
    $matricula2 = app(MatriculadorOferta::class)->matricular($persona2, $ofertaB, '2026-2030');
    // Ingresa el 16 de marzo: le corresponden 16 de los 31 días.
    $matricula2->update(['fecha_ingreso' => '2026-03-16']);
    $matricula2->refresh();

    $planProrrateo = PlanCobro::create([
        'nombre' => 'Prorrateado',
        'moneda' => 'MXN',
        'aplica_a_tipo' => PlanCobro::APLICA_OFERTA,
        'aplica_a_id' => $ofertaB->id,
        'vigente_desde' => '2026-01-01',
    ]);
    $planProrrateo->reglas()->create([
        'concepto_id' => $colegiatura->id,
        'periodicidad' => ReglaGeneracion::PERIODICIDAD_MENSUAL,
        'monto_base' => 3100,
        'dia_generacion' => 1,
        'dia_limite' => 10,
        'prorratea' => true,
    ]);

    $generador->generarPara($matricula2, CarbonImmutable::parse('2026-04-30'));

    $marzo = Adeudo::deMatricula($matricula2->id)->where('periodo_etiqueta', 'Marzo 2026')->first();
    $abril = Adeudo::deMatricula($matricula2->id)->where('periodo_etiqueta', 'Abril 2026')->first();

    verificar('El mes de ingreso se cobra proporcional',
        $marzo !== null && (float) $marzo->monto === 1600.0, (string) $marzo?->monto);
    verificar('El siguiente ya se cobra completo',
        $abril !== null && (float) $abril->monto === 3100.0, (string) $abril?->monto);

    echo PHP_EOL.'6. Becas y recargos por mora'.PHP_EOL;

    $beca = RecargoDescuento::create([
        'tipo' => RecargoDescuento::TIPO_BECA,
        'nombre' => 'Beca de excelencia 50%',
        'modo' => RecargoDescuento::MODO_PORCENTAJE,
        'valor' => 50,
    ]);

    $persona3 = Persona::create(['nombre' => 'Citlali', 'primer_apellido' => 'Rentería', 'sexo_id' => 2]);
    $matricula3 = app(MatriculadorOferta::class)->matricular($persona3, $ofertaB, '2026-2030');
    $matricula3->update(['fecha_ingreso' => '2026-03-01']);
    $matricula3->refresh();

    BecaAlumno::create([
        'matricula_oferta_id' => $matricula3->id,
        'recargo_descuento_id' => $beca->id,
        'vigente_desde' => '2026-01-01',
    ]);

    $generador->generarPara($matricula3, CarbonImmutable::parse('2026-03-31'));

    $conBeca = Adeudo::deMatricula($matricula3->id)->where('periodo_etiqueta', 'Marzo 2026')->firstOrFail();

    verificar('La beca se aplica al generar', (float) $conBeca->monto_descuentos === 1550.0, (string) $conBeca->monto_descuentos);
    verificar('Y el total lo refleja', (float) $conBeca->monto_total === 1550.0, (string) $conBeca->monto_total);
    verificar('El monto base se conserva para poder explicar el descuento',
        (float) $conBeca->monto === 3100.0);

    $mora = RecargoDescuento::create([
        'tipo' => RecargoDescuento::TIPO_RECARGO,
        'nombre' => 'Mora 10%',
        'modo' => RecargoDescuento::MODO_PORCENTAJE,
        'valor' => 10,
        'dias_gracia' => 5,
    ]);

    // Vence el 10 de marzo (día límite 10, posterior al de emisión). A 3 días
    // de vencido todavía no corre: está dentro de los 5 de gracia.
    verificar('El cargo vence el día límite configurado',
        $conBeca->fecha_vencimiento->toDateString() === '2026-03-10',
        $conBeca->fecha_vencimiento->toDateString());

    $aplicador->recalcular($conBeca, CarbonImmutable::parse('2026-03-13'));
    verificar('Dentro de los días de gracia NO hay recargo',
        (float) $conBeca->fresh()->monto_recargos === 0.0, (string) $conBeca->fresh()->monto_recargos);

    $aplicador->recalcular($conBeca, CarbonImmutable::parse('2026-05-10'));
    $conBeca->refresh();

    verificar('Pasada la gracia sí se aplica', (float) $conBeca->monto_recargos === 310.0, (string) $conBeca->monto_recargos);
    verificar('El recargo se calcula sobre el monto BASE, no sobre el ya recargado',
        (float) $conBeca->monto_recargos === round(3100 * 0.10, 2));
    verificar('Y el total suma recargo y resta descuento',
        (float) $conBeca->monto_total === 1860.0, (string) $conBeca->monto_total);

    $registrador->registrar($matricula3->id, $efectivo, (float) $conBeca->monto_total);
    $conBeca->refresh();

    verificar('Un adeudo pagado ya no admite más recargos',
        $aplicador->recalcular($conBeca, CarbonImmutable::parse('2026-09-01')) === false);

    $mora->update(['activo' => false]);
    $beca->update(['activo' => false]);

    echo PHP_EOL.'7. Aplicación de pagos: orden, parciales y confirmación'.PHP_EOL;

    $abiertos = Adeudo::deMatricula($matricula->id)->porCobrar()->orderBy('fecha_vencimiento')->get();

    verificar('Hay cuatro colegiaturas abiertas', $abiertos->count() === 4, (string) $abiertos->count());

    // Un pago que no alcanza para la primera: debe quedar parcial.
    $registrador->registrar($matricula->id, $efectivo, 500.00);
    $primera = $abiertos->first()->fresh();

    verificar('Un pago insuficiente deja el cargo en PARCIAL',
        $primera->estatus === Adeudo::ESTATUS_PARCIAL, $primera->estatus);
    verificar('…con el saldo correcto', $primera->saldo() === 1500.0, (string) $primera->saldo());

    // Un pago que cubre de sobra: liquida el primero, abona al segundo.
    $registrador->registrar($matricula->id, $efectivo, 2500.00);

    verificar('El más vencido se liquida primero',
        $abiertos->first()->fresh()->estatus === Adeudo::ESTATUS_PAGADO);
    verificar('Y el excedente pasa al siguiente',
        $abiertos[1]->fresh()->estatus === Adeudo::ESTATUS_PARCIAL,
        $abiertos[1]->fresh()->estatus);

    // Aplicación dirigida: se paga el ÚLTIMO aunque haya más vencidos.
    $ultimo = $abiertos->last();
    $registrador->registrar($matricula->id, $efectivo, (float) $ultimo->monto_total, [$ultimo->id]);

    verificar('Marcando cargos se respeta el orden que eligió quien cobra',
        $ultimo->fresh()->estatus === Adeudo::ESTATUS_PAGADO);

    // Transferencia: no liquida hasta confirmarse.
    $tercero = $abiertos[2];
    $spei = $registrador->registrar($matricula->id, $transferencia, (float) $tercero->monto_total, [$tercero->id]);

    verificar('Una transferencia nace PENDIENTE', $spei->estatus === Pago::ESTATUS_PENDIENTE);
    verificar('Y el cargo NO se liquida con dinero sin confirmar',
        $tercero->fresh()->estatus !== Adeudo::ESTATUS_PAGADO, $tercero->fresh()->estatus);

    $registrador->confirmar($spei);

    verificar('Al confirmar, el cargo queda pagado',
        $tercero->fresh()->estatus === Adeudo::ESTATUS_PAGADO);

    $registrador->revertir($spei, Pago::ESTATUS_REEMBOLSADO);

    verificar('Reembolsar reabre el cargo', $tercero->fresh()->estatus !== Adeudo::ESTATUS_PAGADO,
        $tercero->fresh()->estatus);
    verificar('Pero la aplicación NO se borra: el intento es parte de la historia',
        $spei->fresh()->adeudos()->count() === 1);

    echo PHP_EOL.'8. Estado de cuenta'.PHP_EOL;

    $anticipo = $registrador->registrar($matricula2->id, $efectivo, 99999.00);
    $cuenta = $estadoCuenta->para($matricula2, CarbonImmutable::parse('2026-06-01'));

    verificar('Lo que sobra de un pago se reporta a favor',
        $cuenta['resumen']['a_favor'] > 0, (string) $cuenta['resumen']['a_favor']);
    verificar('Con todo cubierto, el saldo es cero', $cuenta['resumen']['saldo'] === 0.0,
        (string) $cuenta['resumen']['saldo']);

    $cuenta3 = $estadoCuenta->para($matricula, CarbonImmutable::parse('2026-07-01'));

    // 5 cargos (inscripción + 4 colegiaturas) y 5 pagos (inscripción, 500,
    // 2500, el dirigido al último y la transferencia).
    verificar('El estado de cuenta lista cargos y pagos',
        count($cuenta3['adeudos']) === 5 && count($cuenta3['pagos']) === 5,
        count($cuenta3['adeudos']).' cargos, '.count($cuenta3['pagos']).' pagos');
    verificar('Los pagos sin confirmar se reportan APARTE, no como cobrados',
        $cuenta3['resumen']['pagado'] === round(
            (float) Pago::where('matricula_oferta_id', $matricula->id)
                ->where('estatus', Pago::ESTATUS_COMPLETADO)->sum('monto'), 2
        ));

    echo PHP_EOL.'9. Una matrícula de baja deja de devengar'.PHP_EOL;

    $antes = Adeudo::deMatricula($matricula2->id)->count();
    $matricula2->update(['estatus' => 'baja']);

    $rBaja = $generador->generarPara($matricula2->fresh(), CarbonImmutable::parse('2026-12-31'));

    verificar('No se le generan más cargos', $rBaja['generados'] === 0);
    verificar('Y se dice por qué', $rBaja['motivos'] !== [], implode(' ', $rBaja['motivos']));
    verificar('Los que ya tenía se conservan', Adeudo::deMatricula($matricula2->id)->count() === $antes);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
