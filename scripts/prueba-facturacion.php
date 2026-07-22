<?php

/**
 * Prueba de integración de la facturación CFDI 4.0 (entrega 7.3): emisión
 * contra pagos cobrados, desglose de IVA por concepto, timbrado en cola,
 * rechazo del PAC, cancelación y refacturación. Con rollback.
 *
 * Se corre con `php scripts/prueba-facturacion.php` desde la raíz.
 *
 * Usa el PAC falso (`config('cfdi.driver')`), que es el que permite ejercer el
 * flujo completo sin mandar nada al SAT. La cola se pone en `sync` para que el
 * job de timbrado corra dentro de la prueba: se ejercita el job REAL, no una
 * imitación.
 *
 * No toca a ningún usuario existente ni le cambia el rol activo.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Jobs\TimbrarFactura;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\Cfdi\Pac;
use App\Services\EmisorFactura;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

// El job corre inline: se prueba el timbrado de verdad, no un mock.
config(['queue.default' => 'sync']);

$ok = 0;
$fallos = [];
$archivos = [];

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

$receptor = [
    'rfc' => 'GUME900101AB1',
    'razon_social' => 'MARIA GUTIERREZ MENDOZA',
    'uso_cfdi' => 'D10',
    'regimen_fiscal' => '605',
    'cp' => '44100',
];

DB::beginTransaction();

try {
    $emisor = app(EmisorFactura::class);
    $registrador = app(RegistradorPago::class);

    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $transferencia = MetodoPago::where('clave', 'transferencia')->firstOrFail();
    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $constancia = ConceptoPago::where('clave', 'constancia')->firstOrFail();

    verificar('La colegiatura está exenta y la constancia gravada al 16%',
        $colegiatura->gravado === false && $constancia->gravado === true
        && (float) $constancia->tasa_iva === 0.16);

    // Precondición desde que la escuela puede tener varias razones sociales:
    // sin una asignada, facturar se rechaza a propósito. Aquí basta la global.
    // La precedencia entre razones sociales se prueba en `prueba-emisores`.
    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA',
        'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603',
        'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $persona = Persona::create(['nombre' => 'María', 'primer_apellido' => 'Gutiérrez', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    // Dos cargos, uno exento y uno gravado, cada uno con su pago.
    $adeudoColegiatura = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'Marzo 2026',
        'monto' => 2000, 'monto_total' => 2000,
        'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);

    $adeudoConstancia = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $constancia->id,
        'monto' => 232, 'monto_total' => 232,
        'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);

    $pagoColegiatura = $registrador->registrar($matricula->id, $efectivo, 2000.00, [$adeudoColegiatura->id]);
    $pagoConstancia = $registrador->registrar($matricula->id, $efectivo, 232.00, [$adeudoConstancia->id]);

    echo '1. Emitir contra pagos cobrados'.PHP_EOL;

    verificar('Los dos pagos aparecen como facturables',
        $emisor->facturables($matricula->id)->count() === 2);

    $factura = $emisor->emitir($matricula->id, [$pagoColegiatura->id, $pagoConstancia->id], $receptor);
    $factura->refresh();

    verificar('El job corrió y la factura quedó timbrada',
        $factura->estatus === Factura::ESTATUS_TIMBRADA, $factura->estatus);
    verificar('Con folio fiscal', $factura->uuid !== null, (string) $factura->uuid);
    verificar('Y con fecha de timbrado', $factura->fecha_timbrado !== null);
    verificar('Queda registrado qué PAC la timbró', $factura->pac === 'falso');

    if ($factura->xml_ruta !== null) {
        $archivos[] = $factura->xml_ruta;
    }
    verificar('El XML se guardó en disco PRIVADO, nunca en public/',
        $factura->xml_ruta !== null && Storage::disk('local')->exists($factura->xml_ruta),
        (string) $factura->xml_ruta);

    echo PHP_EOL.'2. El IVA se desglosa por concepto, no sobre el total'.PHP_EOL;

    $renglones = $factura->conceptos()->orderBy('id')->get();

    verificar('Un renglón por pago', $renglones->count() === 2);

    $rColegiatura = $renglones->firstWhere('pago_id', $pagoColegiatura->id);
    $rConstancia = $renglones->firstWhere('pago_id', $pagoConstancia->id);

    verificar('La colegiatura exenta va sin IVA',
        (float) $rColegiatura->iva === 0.0 && (float) $rColegiatura->importe === 2000.0,
        $rColegiatura->importe.' + '.$rColegiatura->iva);

    // 232 con IVA incluido = 200 de base + 32 de impuesto.
    verificar('La constancia gravada se desglosa hacia atrás desde lo cobrado',
        (float) $rConstancia->importe === 200.0 && (float) $rConstancia->iva === 32.0,
        $rConstancia->importe.' + '.$rConstancia->iva);

    verificar('El total de la factura es exactamente lo que entró',
        (float) $factura->total === 2232.0, (string) $factura->total);
    verificar('Y subtotal + IVA cuadran con el total',
        round((float) $factura->subtotal + (float) $factura->iva, 2) === (float) $factura->total);

    verificar('La descripción y la clave del SAT se COPIARON del catálogo',
        $rColegiatura->descripcion === $colegiatura->nombre
        && $rColegiatura->clave_sat === $colegiatura->clave_sat);

    echo PHP_EOL.'3. No se factura dos veces el mismo dinero'.PHP_EOL;

    verificar('Los pagos facturados salen de la lista de facturables',
        $emisor->facturables($matricula->id)->count() === 0);

    $repetida = false;
    $mensaje = '';
    try {
        $emisor->emitir($matricula->id, [$pagoColegiatura->id], $receptor);
    } catch (RuntimeException $e) {
        $repetida = true;
        $mensaje = $e->getMessage();
    }
    verificar('Y refacturarlos se rechaza con explicación', $repetida, $mensaje);

    echo PHP_EOL.'4. Solo se factura dinero cobrado'.PHP_EOL;

    $adeudoAbril = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'Abril 2026',
        'monto' => 2000, 'monto_total' => 2000,
        'fecha_generacion' => '2026-04-01', 'fecha_vencimiento' => '2026-04-10',
    ]);

    $spei = $registrador->registrar($matricula->id, $transferencia, 2000.00, [$adeudoAbril->id]);

    verificar('Una transferencia sin confirmar NO aparece como facturable',
        $emisor->facturables($matricula->id)->count() === 0);

    $sinCobrar = false;
    try {
        $emisor->emitir($matricula->id, [$spei->id], $receptor);
    } catch (RuntimeException $e) {
        $sinCobrar = true;
        $mensaje = $e->getMessage();
    }
    verificar('Y facturarla se rechaza: es una promesa, no dinero', $sinCobrar, $mensaje);

    $registrador->confirmar($spei);

    verificar('Al confirmarse ya se puede facturar',
        $emisor->facturables($matricula->id)->count() === 1);

    echo PHP_EOL.'5. El PAC rechaza y se explica, no revienta'.PHP_EOL;

    $mala = $emisor->emitir($matricula->id, [$spei->id], array_merge($receptor, ['rfc' => 'NOESUNRFC']));
    $mala->refresh();

    verificar('Un RFC inválido deja la factura en error',
        $mala->estatus === Factura::ESTATUS_ERROR, $mala->estatus);
    verificar('Sin folio fiscal', $mala->uuid === null);
    verificar('Con el motivo guardado para que alguien lo corrija',
        $mala->ultimo_error !== null && str_contains((string) $mala->ultimo_error, 'RFC'),
        (string) $mala->ultimo_error);
    verificar('Y contando el intento', $mala->intentos === 1, (string) $mala->intentos);
    verificar('Una factura rechazada SÍ es editable: nunca fue fiscal', $mala->esEditable());

    // Se corrige el dato y se reintenta, que es el camino real.
    $mala->update(['receptor_rfc' => $receptor['rfc'], 'estatus' => Factura::ESTATUS_BORRADOR]);
    (new TimbrarFactura($mala->id))->handle(app(Pac::class));
    $mala->refresh();

    verificar('Corregido el RFC, el reintento sí timbra',
        $mala->estatus === Factura::ESTATUS_TIMBRADA && $mala->uuid !== null, $mala->estatus);
    verificar('Y ya no es editable: ahora es un documento fiscal', ! $mala->esEditable());

    if ($mala->xml_ruta !== null) {
        $archivos[] = $mala->xml_ruta;
    }

    echo PHP_EOL.'6. El doble timbrado se atrapa'.PHP_EOL;

    $uuidOriginal = $mala->uuid;
    (new TimbrarFactura($mala->id))->handle(app(Pac::class));

    verificar('Volver a correr el job no emite otro comprobante',
        $mala->fresh()->uuid === $uuidOriginal, (string) $mala->fresh()->uuid);
    verificar('Ni suma un intento', $mala->fresh()->intentos === 2, (string) $mala->fresh()->intentos);

    echo PHP_EOL.'7. Cancelar: motivo 01 exige sustituta'.PHP_EOL;

    $sinSustituta = false;
    try {
        $emisor->cancelar($factura, Factura::MOTIVO_CON_RELACION);
    } catch (RuntimeException $e) {
        $sinSustituta = true;
        $mensaje = $e->getMessage();
    }
    verificar('El motivo 01 sin sustituta se rechaza', $sinSustituta, $mensaje);
    verificar('Y la factura sigue vigente', $factura->fresh()->estaVigente());

    echo PHP_EOL.'8. Cancelar sin relación libera los pagos'.PHP_EOL;

    $emisor->cancelar($factura, Factura::MOTIVO_SIN_RELACION);
    $factura->refresh();

    verificar('La factura queda cancelada', $factura->estatus === Factura::ESTATUS_CANCELADA);
    verificar('Con su momento y su motivo',
        $factura->cancelada_en !== null && $factura->motivo_cancelacion === '02');
    verificar('El folio fiscal NO se borra: es el respaldo de lo que se declaró',
        $factura->uuid !== null);
    verificar('Sus conceptos siguen ahí', $factura->conceptos()->count() === 2);

    verificar('Cancelar LIBERA sus pagos: vuelven a ser facturables',
        $emisor->facturables($matricula->id)->pluck('id')->sort()->values()->all()
        === collect([$pagoColegiatura->id, $pagoConstancia->id])->sort()->values()->all());

    $refactura = $emisor->emitir(
        $matricula->id,
        [$pagoColegiatura->id, $pagoConstancia->id],
        array_merge($receptor, ['razon_social' => 'MARIA GUTIERREZ MENDOZA SA DE CV'])
    );
    $refactura->refresh();

    verificar('La refactura se timbra con folio propio',
        $refactura->uuid !== null && $refactura->uuid !== $factura->uuid);
    verificar('Y ampara el mismo importe', (float) $refactura->total === 2232.0);
    verificar('Las DOS quedan: la cancelada no se borra',
        Factura::whereIn('id', [$factura->id, $refactura->id])->count() === 2);

    if ($refactura->xml_ruta !== null) {
        $archivos[] = $refactura->xml_ruta;
    }

    echo PHP_EOL.'9. Refacturar: la sustituta nace ANTES de cancelar'.PHP_EOL;

    // Mientras la original vive, sus pagos están ocupados.
    $ocupados = false;
    try {
        $emisor->emitir($matricula->id, [$pagoColegiatura->id], $receptor);
    } catch (RuntimeException $e) {
        $ocupados = true;
        $mensaje = $e->getMessage();
    }
    verificar('Un pago amparado por una factura VIVA no se vuelve a facturar', $ocupados, $mensaje);

    // El camino correcto: refacturar declara la sustitución al emitir, así que
    // la nueva puede tomar los pagos sin que la vieja desaparezca todavía.
    $sustituta = $emisor->refacturar($refactura, array_merge($receptor, ['cp' => '06600']));
    $sustituta->refresh();

    if ($sustituta->xml_ruta !== null) {
        $archivos[] = $sustituta->xml_ruta;
    }

    verificar('La sustituta se timbra', $sustituta->estatus === Factura::ESTATUS_TIMBRADA);
    verificar('Y nace ligada a la que viene a reemplazar',
        $sustituta->factura_sustituye_id === $refactura->id);
    verificar('La original sigue timbrada mientras tanto: nunca hay un hueco sin comprobante',
        $refactura->fresh()->estaVigente());
    verificar('Refacturar dos veces la misma se rechaza',
        (function () use ($emisor, $refactura, $receptor) {
            try {
                $emisor->refacturar($refactura->fresh(), $receptor);

                return false;
            } catch (RuntimeException) {
                return true;
            }
        })());

    // Ahora sí, el segundo paso: cancelar la original citando a la sustituta.
    $emisor->cancelar($refactura->fresh(), Factura::MOTIVO_CON_RELACION, $sustituta);

    verificar('Cancelar con motivo 01 y sustituta timbrada funciona',
        $refactura->fresh()->estatus === Factura::ESTATUS_CANCELADA);
    verificar('Con el motivo 01 guardado', $refactura->fresh()->motivo_cancelacion === '01');
    verificar('Y se puede navegar de la nueva a la vieja',
        $sustituta->fresh()->sustituye?->id === $refactura->id);
    verificar('Y de la vieja a la nueva',
        $refactura->fresh()->sustituida->pluck('id')->contains($sustituta->id));

    // Una sustituta ajena no vale para el motivo 01.
    $ajena = false;
    try {
        $emisor->cancelar($sustituta->fresh(), Factura::MOTIVO_CON_RELACION, $sustituta->fresh());
    } catch (RuntimeException $e) {
        $ajena = true;
        $mensaje = $e->getMessage();
    }
    verificar('Citar una factura que no se emitió para sustituir a ésta se rechaza', $ajena, $mensaje);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();

    // Los archivos del PAC falso no viven en la transacción: se limpian a mano.
    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }

    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
