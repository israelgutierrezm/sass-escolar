<?php

/**
 * La nota de crédito: corregir una factura sin cancelarla.
 *
 * `php scripts/prueba-nota-credito.php` desde la raíz. Contra la BD real del
 * tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Un documento que RESTA de lo declarado al SAT. Los errores aquí no revientan:
 * acreditar de más declara un ingreso negativo que nunca existió; acreditar
 * contra el renglón equivocado reversa un IVA que no se causó; y confundir la
 * nota con una sustitución liberaría los pagos de la factura original y el
 * mismo dinero se podría facturar dos veces.
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
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\Cfdi\FacturapiPac;
use App\Services\EmisorFactura;
use App\Services\EmisorNotaCredito;
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
        echo "  OK    {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/**
 * Lo que se rechaza tiene que rechazarse POR SU RAZÓN, no de cualquier forma.
 *
 * Las dos ramas hacen falta y no son lo mismo. La primera es el rechazo
 * previsto. La segunda existe porque al quitar una salvaguarda el código sigue
 * de largo y revienta más adelante con otra cosa —un TypeError sobre un null—:
 * sin atraparlo, la suite MUERE ahí y una regresión sale como un rastro de pila
 * en vez de como una falla. Se atrapa para REPORTARLA, nunca para darla por
 * buena: un `catch` que devolviera «se negó» convertiría la explosión en un
 * acierto, que es la trampa que este proyecto ya se cobró tres veces.
 */
function seNiega(callable $accion, string $fragmento): array
{
    try {
        $accion();

        return [false, 'no se negó'];
    } catch (RuntimeException $e) {
        return [str_contains($e->getMessage(), $fragmento), $e->getMessage()];
    } catch (Throwable $e) {
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
    $emisorFacturas = app(EmisorFactura::class);
    $notas = app(EmisorNotaCredito::class);
    $registrador = app(RegistradorPago::class);

    echo '1. El escenario: una factura con dos renglones de distinta tasa'.PHP_EOL;

    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA',
        'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603',
        'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $persona = Persona::create(['nombre' => 'Nota', 'primer_apellido' => 'Crédito', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();  // exenta
    $constancia = ConceptoPago::where('clave', 'constancia')->firstOrFail();    // 16 %
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();

    $cargar = function (ConceptoPago $concepto, float $monto) use ($matricula, $registrador, $efectivo) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $concepto->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);

        return $registrador->registrar($matricula, $efectivo, $monto, [$adeudo->id]);
    };

    $guardarArchivos = function (Factura $f) use (&$archivos): void {
        foreach ([$f->xml_ruta, $f->pdf_ruta] as $ruta) {
            if ($ruta !== null) {
                $archivos[] = $ruta;
            }
        }
    };

    $pagoColegiatura = $cargar($colegiatura, 2000.00);
    $pagoConstancia = $cargar($constancia, 232.00);

    $factura = $emisorFacturas
        ->emitir($matricula->id, [$pagoColegiatura->id, $pagoConstancia->id], $receptor)
        ->refresh();
    $guardarArchivos($factura);

    $renglones = $factura->conceptos()->orderBy('id')->get();
    $rColegiatura = $renglones->firstWhere('pago_id', $pagoColegiatura->id);
    $rConstancia = $renglones->firstWhere('pago_id', $pagoConstancia->id);

    verificar('La factura se timbró', $factura->estatus === Factura::ESTATUS_TIMBRADA, $factura->estatus);
    verificar('Y nació como comprobante de INGRESO', $factura->tipo === Factura::TIPO_INGRESO);
    verificar('Con la colegiatura exenta y la constancia gravada',
        (float) $rColegiatura->iva === 0.0 && (float) $rConstancia->iva === 32.0,
        $rColegiatura->iva.' / '.$rConstancia->iva);
    verificar('Sin nada acreditado todavía', $factura->acreditado() === 0.0);
    verificar('Y su valor efectivo es el total', $factura->totalEfectivo() === (float) $factura->total);

    echo PHP_EOL.'2. Lo que no se puede acreditar'.PHP_EOL;

    $borrador = Factura::create([
        'matricula_oferta_id' => $matricula->id,
        'emisor_id' => $emisorEscuela->id, 'emisor_rfc' => 'AAA010101AAA',
        'emisor_razon_social' => 'ESCUELA DEMO SC', 'emisor_regimen_fiscal' => '603', 'emisor_cp' => '44100',
        'receptor_rfc' => $receptor['rfc'], 'receptor_razon_social' => $receptor['razon_social'],
        'receptor_uso_cfdi' => 'D10', 'receptor_regimen_fiscal' => '605', 'receptor_cp' => '44100',
        'forma_pago_sat' => '01', 'subtotal' => 100, 'iva' => 0, 'total' => 100,
    ]);

    [$bien, $mensaje] = seNiega(
        fn () => $notas->emitir($borrador, [['concepto_id' => $rColegiatura->id, 'importe' => 10.0]], 'x'),
        'timbrada y vigente',
    );
    // Un borrador se corrige antes de timbrarlo: acreditarlo emitiría un CFDI
    // de egreso contra un comprobante que el SAT nunca recibió.
    verificar('Un borrador no se acredita', $bien, $mensaje);

    echo PHP_EOL.'3. Acreditar de más se rechaza, renglón por renglón'.PHP_EOL;

    [$bien, $mensaje] = seNiega(
        fn () => $notas->emitir($factura, [['concepto_id' => $rConstancia->id, 'importe' => 500.0]], 'de más'),
        'solo admite acreditar hasta',
    );
    // El tope es DEL RENGLÓN y no del total de la factura: con 2 200 de sobra en
    // la colegiatura, comparar contra el total dejaría acreditar 500 de una
    // constancia de 200 y reversaría un IVA que no se causó.
    verificar('No se acredita más de lo que tiene el renglón', $bien, $mensaje);
    verificar('Y el mensaje nombra el concepto y el tope',
        str_contains($mensaje, $rConstancia->descripcion) && str_contains($mensaje, '200.00'), $mensaje);

    [$bien, $mensaje] = seNiega(
        fn () => $notas->emitir($factura, [['concepto_id' => 999999999, 'importe' => 10.0]], 'ajeno'),
        'no pertenece a la factura',
    );
    // El id del renglón viaja en la petición: sin comprobarlo se acreditaría
    // contra el concepto de la factura de otra persona.
    verificar('Un renglón que no es de esta factura se rechaza', $bien, $mensaje);

    [$bien, $mensaje] = seNiega(
        fn () => $notas->emitir($factura, [['concepto_id' => $rColegiatura->id, 'importe' => 0.0]], 'nada'),
        'al menos un concepto',
    );
    verificar('Una nota de cero no se emite', $bien, $mensaje);

    echo PHP_EOL.'4. La nota parcial'.PHP_EOL;

    $nota = $notas->emitir($factura, [
        ['concepto_id' => $rColegiatura->id, 'importe' => 500.0],
        ['concepto_id' => $rConstancia->id, 'importe' => 0.0],
    ], 'Beca autorizada después de facturar');
    $nota->refresh();
    $guardarArchivos($nota);

    verificar('Se timbró', $nota->estatus === Factura::ESTATUS_TIMBRADA, $nota->estatus);
    verificar('Y es un comprobante de EGRESO', $nota->tipo === Factura::TIPO_EGRESO);
    verificar('Que apunta a la factura que reduce', $nota->factura_origen_id === $factura->id);
    verificar('Con el motivo guardado', $nota->motivo_egreso === 'Beca autorizada después de facturar');
    verificar('Por el importe acreditado', (float) $nota->total === 500.0, (string) $nota->total);
    // La colegiatura es exenta: acreditarla no reversa impuesto. Con la tasa
    // tomada del catálogo de hoy en vez del renglón timbrado, esto cambiaría el
    // día que la escuela corrija el concepto.
    verificar('Sin IVA, porque el renglón acreditado era exento', (float) $nota->iva === 0.0);

    $renglonNota = $nota->conceptos()->first();
    verificar('Su renglón dice cuál acredita', $renglonNota->concepto_origen_id === $rColegiatura->id);
    // Con `pago_id` puesto, la nota figuraría en `pagosOcupados` y ese dinero
    // quedaría marcado como facturado por segunda vez.
    verificar('Y NO ampara ningún pago', $renglonNota->pago_id === null);

    verificar('El emisor se copia del original, no se vuelve a resolver',
        $nota->emisor_id === $factura->emisor_id && $nota->emisor_rfc === $factura->emisor_rfc);
    verificar('Y el receptor también', $nota->receptor_rfc === $factura->receptor_rfc);

    $factura->refresh();
    verificar('La factura queda acreditada en 500', $factura->acreditado() === 500.0);
    verificar('Y vale hoy 1 732', $factura->totalEfectivo() === 1732.0, (string) $factura->totalEfectivo());
    // Es lo que separa una nota de crédito de una cancelación: el comprobante
    // original sigue siendo el que se timbró.
    verificar('Pero su total timbrado NO cambia', (float) $factura->total === 2232.0);
    verificar('Y sigue VIGENTE', $factura->estaVigente());

    echo PHP_EOL.'5. La nota NO libera los pagos de la factura'.PHP_EOL;

    // Es la diferencia con la sustitución, y la razón de que sean dos columnas:
    // una factura con sustituta viva deja de amparar sus pagos para que la nueva
    // los tome. Si la nota hiciera lo mismo, el mismo dinero se facturaría dos
    // veces.
    $facturables = $emisorFacturas->facturables($matricula->id)->pluck('id')->all();

    verificar('Los pagos siguen amparados por la original',
        ! in_array($pagoColegiatura->id, $facturables, true)
        && ! in_array($pagoConstancia->id, $facturables, true),
        'facturables: '.(implode(', ', $facturables) ?: 'ninguno'));

    echo PHP_EOL.'6. Lo acreditado se descuenta del tope'.PHP_EOL;

    $disponible = $notas->disponiblePorConcepto($factura);

    verificar('A la colegiatura le quedan 1 500', ($disponible[$rColegiatura->id] ?? null) === 1500.0,
        (string) ($disponible[$rColegiatura->id] ?? 'sin dato'));
    verificar('Y a la constancia sus 200 intactos', ($disponible[$rConstancia->id] ?? null) === 200.0);

    [$bien, $mensaje] = seNiega(
        fn () => $notas->emitir($factura, [['concepto_id' => $rColegiatura->id, 'importe' => 1600.0]], 'de más'),
        'solo admite acreditar hasta',
    );
    verificar('Y ya no admite acreditar los 2 000 completos', $bien, $mensaje);

    echo PHP_EOL.'7. Una nota cancelada deja de reducir'.PHP_EOL;

    $nota->update(['estatus' => Factura::ESTATUS_CANCELADA, 'cancelada_en' => now()]);
    $factura->refresh();

    // Si una cancelada siguiera contando, seguiría restando importe de una
    // factura que volvió a valer por completo, y ese renglón no se podría
    // acreditar nunca más.
    verificar('Lo acreditado vuelve a cero', $factura->acreditado() === 0.0);
    verificar('Y el renglón admite otra vez sus 2 000',
        ($notas->disponiblePorConcepto($factura)[$rColegiatura->id] ?? null) === 2000.0);

    $nota->update(['estatus' => Factura::ESTATUS_TIMBRADA, 'cancelada_en' => null]);

    echo PHP_EOL.'8. Una nota de crédito no se acredita a su vez'.PHP_EOL;

    [$bien, $mensaje] = seNiega(
        fn () => $notas->emitir($nota->fresh(), [['concepto_id' => $renglonNota->id, 'importe' => 10.0]], 'x'),
        'no ampara ingreso',
    );
    verificar('Se niega, y por su razón', $bien, $mensaje);

    echo PHP_EOL.'9. Lo que se le manda al PAC'.PHP_EOL;

    $pac = new FacturapiPac;
    $cuerpo = $pac->cuerpoDe($nota->fresh());

    verificar('Va declarada como EGRESO', ($cuerpo['type'] ?? null) === 'E');
    verificar('Y relacionada con el CFDI que reduce',
        ($cuerpo['related_documents'][0]['documents'][0] ?? null) === $factura->uuid,
        json_encode($cuerpo['related_documents'] ?? [], JSON_UNESCAPED_UNICODE));
    // Sin la relación 01, el SAT recibe un egreso suelto que no rebaja nada: un
    // documento válido que no corrige la factura que se quería corregir.
    verificar('Con la relación 01 del SAT',
        ($cuerpo['related_documents'][0]['relationship'] ?? null) === Factura::RELACION_NOTA_CREDITO);

    $cuerpoFactura = $pac->cuerpoDe($factura->fresh());
    verificar('Y una factura de ingreso no manda ni el tipo ni la relación',
        ! array_key_exists('type', $cuerpoFactura) && ! array_key_exists('related_documents', $cuerpoFactura));

    echo PHP_EOL.'10. El IVA se reversa con la tasa del renglón timbrado'.PHP_EOL;

    $conIva = $notas->emitir($factura->fresh(), [
        ['concepto_id' => $rConstancia->id, 'importe' => 100.0],
    ], 'Cobro de más en la constancia');
    $conIva->refresh();
    $guardarArchivos($conIva);

    // 100 de base al 16 % son 16 de impuesto: la mitad exacta de lo que se
    // timbró en ese renglón (200 + 32).
    verificar('100 de base reversan 16 de IVA', (float) $conIva->iva === 16.0, (string) $conIva->iva);
    verificar('Y el total de la nota son 116', (float) $conIva->total === 116.0, (string) $conIva->total);
    verificar('El renglón conserva la clave del SAT del original',
        $conIva->conceptos()->first()->clave_sat === $rConstancia->clave_sat);

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }
} finally {
    DB::rollBack();

    // Los XML/PDF del PAC falso se escriben en disco y el rollback no se los
    // lleva: la transacción no alcanza al sistema de archivos.
    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }
}

exit($fallos === [] ? 0 : 1);
