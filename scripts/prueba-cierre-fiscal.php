<?php

/**
 * El cierre del periodo fiscal.
 *
 * `php scripts/prueba-cierre-fiscal.php` desde la raíz. Contra la BD real del
 * tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Aquí una factura se emite SIEMPRE con la fecha de hoy, así que cerrar no
 * puede significar «que no entren comprobantes con fecha vieja»: eso no puede
 * pasar. Lo que sí puede es que alguien CANCELE un comprobante de un mes ya
 * declarado, y eso cambia hacia atrás un número que la escuela ya presentó.
 *
 * Y la otra mitad, que es la que hace útil todo esto: la nota de crédito SIGUE
 * permitida. Se fecha hoy y corrige el mes cerrado sin tocarlo. Un cierre que
 * bloqueara las dos cosas dejaría a la escuela sin ninguna forma de corregir.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\PeriodoFiscal;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\CierreFiscal;
use App\Services\EmisorFactura;
use App\Services\EmisorNotaCredito;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

config(['queue.default' => 'sync']);

$ok = 0;
$fallos = [];
$archivos = [];

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

/** Lo que se rechaza tiene que rechazarse POR SU RAZÓN. */
function seNiega(callable $accion, string $fragmento): array
{
    try {
        $accion();

        return [false, 'no se negó'];
    } catch (RuntimeException $e) {
        return [str_contains($e->getMessage(), $fragmento), $e->getMessage()];
    } catch (Throwable $e) {
        // Se atrapa para REPORTARLA, nunca para darla por buena.
        return [false, 'reventó con '.$e::class.': '.$e->getMessage()];
    }
}

$receptor = [
    'rfc' => 'GUME900101AB1',
    'razon_social' => 'MARIA GUTIERREZ MENDOZA',
    'uso_cfdi' => 'D10',
    'regimen_fiscal' => '605',
    'cp' => '44100',
];

DB::beginTransaction();

try {
    $cierre = app(CierreFiscal::class);
    $emisorFacturas = app(EmisorFactura::class);
    $notas = app(EmisorNotaCredito::class);
    $registrador = app(RegistradorPago::class);

    echo '1. El escenario: dos facturas del mes pasado'.PHP_EOL;

    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603', 'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $persona = Persona::create(['nombre' => 'Cierre', 'primer_apellido' => 'Fiscal', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();

    $emitir = function (float $monto) use ($matricula, $registrador, $efectivo, $colegiatura, $emisorFacturas, $receptor, &$archivos) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $colegiatura->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-01-01', 'fecha_vencimiento' => '2026-01-10',
        ]);
        $pago = $registrador->registrar($matricula, $efectivo, $monto, [$adeudo->id]);
        $f = $emisorFacturas->emitir($matricula->id, [$pago->id], $receptor)->refresh();

        foreach ([$f->xml_ruta, $f->pdf_ruta] as $ruta) {
            if ($ruta !== null) {
                $archivos[] = $ruta;
            }
        }

        return $f;
    };

    $mesPasado = Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(5);
    $anio = (int) $mesPasado->format('Y');
    $mes = (int) $mesPasado->format('n');

    $unaDelMesPasado = $emitir(1000.00);
    $otraDelMesPasado = $emitir(500.00);
    $cancelada = $emitir(300.00);

    // Se les mueve la fecha de timbrado al mes pasado: es el mes que se va a
    // cerrar, y el actual no se puede cerrar a propósito.
    foreach ([$unaDelMesPasado, $otraDelMesPasado, $cancelada] as $f) {
        $f->forceFill(['fecha_timbrado' => $mesPasado])->save();
    }

    // Una cancelada NO forma parte de lo declarado: sumarla haría que el cierre
    // afirmara un ingreso que la escuela nunca reportó.
    $cancelada->forceFill(['estatus' => Factura::ESTATUS_CANCELADA, 'cancelada_en' => now()])->save();

    // Una nota de crédito DENTRO del mes que se va a cerrar. Sin ella, «los
    // ingresos no incluyen los egresos» se cumpliría porque no hay ningún
    // egreso en ese mes, que es una prueba que pasa por no encontrar el caso.
    $notaDelMesPasado = $notas->emitir(
        $otraDelMesPasado,
        [['concepto_id' => $otraDelMesPasado->conceptos()->first()->id, 'importe' => 200.0]],
        'Ajuste dentro del mes',
    )->refresh();

    foreach ([$notaDelMesPasado->xml_ruta, $notaDelMesPasado->pdf_ruta] as $ruta) {
        if ($ruta !== null) {
            $archivos[] = $ruta;
        }
    }

    $notaDelMesPasado->forceFill(['fecha_timbrado' => $mesPasado])->save();

    echo PHP_EOL.'2. Los totales del mes'.PHP_EOL;

    $totales = $cierre->totales($anio, $mes);

    verificar('Cuenta las vigentes y no la cancelada', $totales['comprobantes'] === 3,
        (string) $totales['comprobantes']);
    // Los egresos NO se suman a los ingresos: son un dato propio del mes
    // —cuánto se acreditó— y fundirlos en un neto escondería la mitad de lo que
    // hay que declarar.
    verificar('Los ingresos son sólo los comprobantes de ingreso',
        $totales['ingresos'] === 1500.0, (string) $totales['ingresos']);
    verificar('Y los egresos van aparte', $totales['egresos'] === 200.0, (string) $totales['egresos']);

    echo PHP_EOL.'3. Un mes que no ha terminado no se cierra'.PHP_EOL;

    $hoy = Carbon::now();

    // Cerrarlo dejaría dentro las facturas que faltan por emitir este mes, y
    // esas caerían en un periodo que ya nadie puede corregir.
    [$bien, $mensaje] = seNiega(
        fn () => $cierre->cerrar((int) $hoy->format('Y'), (int) $hoy->format('n')),
        'todavía no termina',
    );
    verificar('El mes en curso se rehúsa', $bien, $mensaje);

    echo PHP_EOL.'4. El cierre'.PHP_EOL;

    $periodo = $cierre->cerrar($anio, $mes);

    verificar('Queda cerrado', $periodo->estaCerrado());
    verificar('Con la fecha del cierre', $periodo->cerrado_en !== null);
    // Congelados: un cierre es una afirmación fechada, y recalcularla al mirarla
    // haría que el cierre cambiara solo.
    verificar('Y con los totales CONGELADOS',
        (int) $periodo->comprobantes === 3
        && (float) $periodo->ingresos === 1500.0
        && (float) $periodo->egresos === 200.0,
        $periodo->comprobantes.' / '.$periodo->ingresos.' / '.$periodo->egresos);

    [$bien, $mensaje] = seNiega(fn () => $cierre->cerrar($anio, $mes), 'ya está cerrado');
    verificar('No se cierra dos veces', $bien, $mensaje);

    echo PHP_EOL.'5. Lo que el cierre IMPIDE'.PHP_EOL;

    // Cancelar cambia hacia atrás un número que la escuela ya declaró.
    [$bien, $mensaje] = seNiega(
        fn () => $emisorFacturas->cancelar($unaDelMesPasado, Factura::MOTIVO_SIN_RELACION),
        'periodo fiscal cerrado',
    );
    verificar('Cancelar un comprobante del mes cerrado se rehúsa', $bien, $mensaje);
    verificar('Y el mensaje nombra el periodo y la salida',
        str_contains($mensaje, $periodo->etiqueta()) && str_contains($mensaje, 'nota de crédito'), $mensaje);

    echo PHP_EOL.'6. Lo que el cierre NO impide, a propósito'.PHP_EOL;

    // Es la asimetría que hace útil el cierre: la nota se fecha HOY y pertenece
    // al periodo de hoy, así que corrige el mes cerrado sin tocarlo. Bloquearla
    // dejaría a la escuela sin ninguna forma de corregir.
    $nota = $notas->emitir(
        $unaDelMesPasado,
        [['concepto_id' => $unaDelMesPasado->conceptos()->first()->id, 'importe' => 200.0]],
        'Corrección de un mes ya cerrado',
    )->refresh();

    foreach ([$nota->xml_ruta, $nota->pdf_ruta] as $ruta) {
        if ($ruta !== null) {
            $archivos[] = $ruta;
        }
    }

    verificar('La nota de crédito SÍ se puede emitir', $nota->estatus === Factura::ESTATUS_TIMBRADA,
        $nota->estatus);
    verificar('Y cae en el periodo de HOY, no en el cerrado',
        $nota->fecha_timbrado->format('Y-n') === $hoy->format('Y-n'),
        $nota->fecha_timbrado->format('Y-n').' vs '.$hoy->format('Y-n'));
    verificar('Así que el mes cerrado sigue con sus mismos totales congelados',
        (float) $periodo->fresh()->ingresos === 1500.0);

    echo PHP_EOL.'7. Una factura de OTRO mes no se ve afectada'.PHP_EOL;

    $deHoy = $emitir(700.00);

    // El candado es por PERIODO: sin eso, cerrar un mes cualquiera bloquearía
    // la operación de todos los demás.
    verificar('No pertenece a un periodo cerrado', $cierre->periodoCerradoDe($deHoy) === null);

    $emisorFacturas->cancelar($deHoy, Factura::MOTIVO_SIN_RELACION);

    verificar('Y se puede cancelar sin problema',
        $deHoy->fresh()->estatus === Factura::ESTATUS_CANCELADA);

    // Un borrador no pertenece a ningún periodo: no es fiscal todavía.
    $borrador = Factura::create([
        'matricula_oferta_id' => $matricula->id,
        'emisor_id' => $emisorEscuela->id, 'emisor_rfc' => 'AAA010101AAA',
        'emisor_razon_social' => 'ESCUELA DEMO SC', 'emisor_regimen_fiscal' => '603', 'emisor_cp' => '44100',
        'receptor_rfc' => $receptor['rfc'], 'receptor_razon_social' => $receptor['razon_social'],
        'receptor_uso_cfdi' => 'D10', 'receptor_regimen_fiscal' => '605', 'receptor_cp' => '44100',
        'forma_pago_sat' => '01', 'subtotal' => 100, 'iva' => 0, 'total' => 100,
    ]);

    verificar('Un borrador no pertenece a ningún periodo',
        $cierre->periodoCerradoDe($borrador) === null);

    echo PHP_EOL.'8. Reabrir'.PHP_EOL;

    [$bien, $mensaje] = seNiega(
        fn () => $cierre->reabrir((int) $hoy->format('Y'), (int) $hoy->format('n'), 'x'),
        'no está cerrado',
    );
    verificar('No se reabre lo que no está cerrado', $bien, $mensaje);

    $reabierto = $cierre->reabrir($anio, $mes, 'Faltó una factura del corte');

    verificar('Queda abierto otra vez', ! $reabierto->estaCerrado());
    // Reabrir habilita cambiar un número ya declarado: dentro de un año esto es
    // lo único que lo explica.
    verificar('Con el motivo guardado', $reabierto->motivo_reapertura === 'Faltó una factura del corte');
    verificar('Y con la fecha de la reapertura', $reabierto->reabierto_en !== null);

    verificar('Y ya se puede cancelar lo de ese mes',
        $cierre->periodoCerradoDe($unaDelMesPasado->fresh()) === null);

    echo PHP_EOL.'9. Volver a cerrar limpia el rastro de la reapertura'.PHP_EOL;

    // El motivo describe por qué se volvió a ABRIR; con el mes cerrado otra vez
    // esa afirmación ya no vale, y dejarla haría leer «reabierto por…» sobre un
    // periodo cerrado.
    $decierre = $cierre->cerrar($anio, $mes);

    verificar('Vuelve a estar cerrado', $decierre->estaCerrado());
    verificar('Y el motivo de la reapertura se retira', $decierre->motivo_reapertura === null);
    // Los totales se vuelven a congelar CON lo que hay ahora: la nota de crédito
    // de hoy no entra —es de otro mes— pero la cancelación de una del mes sí se
    // reflejaría.
    verificar('Con los totales recalculados al momento del nuevo cierre',
        (int) $decierre->comprobantes === $cierre->totales($anio, $mes)['comprobantes']);

    echo PHP_EOL.'10. El panorama de la pantalla'.PHP_EOL;

    $panorama = $cierre->panorama(3);

    verificar('Trae los meses pedidos', count($panorama) === 3, (string) count($panorama));
    verificar('El primero es el mes en curso y se marca como tal',
        $panorama[0]['en_curso'] === true && (int) $panorama[0]['mes'] === (int) $hoy->format('n'));
    verificar('El mes cerrado viene marcado', ($panorama[1]['cerrado'] ?? null) === true);
    verificar('Con lo congelado al cerrar y lo de ahora, para poder compararlos',
        ($panorama[1]['al_cerrar']['comprobantes'] ?? null) !== null
        && ($panorama[1]['ahora']['comprobantes'] ?? null) !== null);

    // Un mes abierto no tiene nada congelado, y enseñar un cero se leería como
    // «se cerró con cero comprobantes».
    verificar('Un mes abierto no trae cifras congeladas', $panorama[0]['al_cerrar'] === null);

    echo PHP_EOL.'11. Un mes se cierra UNA vez'.PHP_EOL;

    verificar('Sólo hay una fila para ese periodo',
        PeriodoFiscal::query()->where('anio', $anio)->where('mes', $mes)->count() === 1);

} catch (Throwable $e) {
    // Que la suite muera a media corrida ES una falla, y hay que reportarla como
    // tal: sin esto, una regresión sale como un rastro de pila sin resumen y un
    // barrido que busca «Resultado:» la cuenta como una suite rota, no como una
    // prueba que detectó algo.
    $fallos[] = 'la suite murió antes de terminar';
    echo '  FALLA la suite murió antes de terminar  ['.$e::class.': '.$e->getMessage().']'.PHP_EOL;
} finally {
    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }

    DB::rollBack();

    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }
}

exit($fallos === [] ? 0 : 1);
