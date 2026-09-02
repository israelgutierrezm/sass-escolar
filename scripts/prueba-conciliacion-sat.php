<?php

/**
 * Conciliación de los CFDI con el SAT.
 *
 * `php scripts/prueba-conciliacion-sat.php` desde la raíz. Contra la BD real
 * del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Un comprobante vive en dos sitios y se separan SOLOS: alguien lo cancela
 * desde el portal del PAC, o una cancelación pedida aquí se queda esperando que
 * el receptor la acepte. Ninguna de las dos falla ni avisa; las dos se
 * descubren en la declaración.
 *
 * Y vigila la regla que sostiene el resto: la conciliación **nunca escribe
 * `estatus`**. Moverlo desde un comando de madrugada liberaría los pagos de esa
 * factura —`vivas()` decide eso— y alguien podría refacturar el mismo dinero
 * sin haberlo pedido.
 *
 * ── El PAC se SUSTITUYE por un doble ───────────────────────────────────────
 * `PacFalso` no consulta al SAT a propósito, así que con él no hay
 * discrepancia posible. El doble hereda de él —para que timbrar siga
 * funcionando igual— y sólo cambia lo que el SAT contestaría.
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
use App\Services\Cfdi\EstadoEnElPac;
use App\Services\Cfdi\Pac;
use App\Services\Cfdi\PacFalso;
use App\Services\ConciliadorCfdi;
use App\Services\EmisorFactura;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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

/**
 * El PAC de mentiras que además contesta lo que el escenario necesite.
 *
 * Hereda de `PacFalso` para que timbrar siga comportándose igual: lo que se
 * está probando es la conciliación, no la emisión.
 */
class PacQueContesta extends PacFalso
{
    /** @var array<int, EstadoEnElPac> por id de factura */
    public static array $respuestas = [];

    public static bool $concilia = true;

    public static int $consultas = 0;

    /** @var array<int, int> ids consultados, para saber a QUIÉN se le preguntó */
    public static array $preguntadas = [];

    public function puedeConciliar(): bool
    {
        return self::$concilia;
    }

    /** @var array<int, bool> ids a los que el PAC les revienta encima */
    public static array $revientan = [];

    public function consultarEstado(Factura $factura): EstadoEnElPac
    {
        self::$consultas++;
        self::$preguntadas[] = $factura->id;

        // Un PAC de verdad se cae: la red, un 500, un token vencido. No es lo
        // mismo que contestar «no sé», y hay que comprobar las dos.
        if (self::$revientan[$factura->id] ?? false) {
            throw new RuntimeException('El PAC se cayó a media consulta.');
        }

        return self::$respuestas[$factura->id]
            ?? EstadoEnElPac::desconocido('El escenario no le puso respuesta a esta factura.');
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
    // El doble se registra ANTES de resolver nada: `EmisorFactura` recibe el PAC
    // por el constructor, así que resolverlo antes se llevaría el de verdad.
    app()->singleton(Pac::class, fn () => new PacQueContesta);

    $emisorFacturas = app(EmisorFactura::class);
    $conciliador = app(ConciliadorCfdi::class);
    $registrador = app(RegistradorPago::class);

    echo '1. El escenario: tres facturas timbradas'.PHP_EOL;

    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603', 'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $persona = Persona::create(['nombre' => 'Sat', 'primer_apellido' => 'Concilia', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();

    $emitir = function (float $monto) use ($matricula, $registrador, $efectivo, $colegiatura, $emisorFacturas, $receptor, &$archivos) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $colegiatura->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
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

    $cuadra = $emitir(1000.00);       // el SAT dirá lo mismo que nosotros
    $canceladaFuera = $emitir(1100.00); // el SAT la tiene cancelada; aquí, vigente
    $sinCompletar = $emitir(1200.00);   // aquí cancelada; el SAT la tiene vigente

    verificar('Las tres se timbraron',
        [$cuadra->estatus, $canceladaFuera->estatus, $sinCompletar->estatus]
        === array_fill(0, 3, Factura::ESTATUS_TIMBRADA));

    // Se cancela SOLO en la base, que es exactamente el desajuste que se quiere
    // probar: la escuela cree que canceló y el CFDI sigue vivo.
    $sinCompletar->update(['estatus' => Factura::ESTATUS_CANCELADA, 'cancelada_en' => now(), 'motivo_cancelacion' => '02']);

    // Un borrador, para comprobar que ni se le pregunta.
    $borrador = Factura::create([
        'matricula_oferta_id' => $matricula->id,
        'emisor_id' => $emisorEscuela->id, 'emisor_rfc' => 'AAA010101AAA',
        'emisor_razon_social' => 'ESCUELA DEMO SC', 'emisor_regimen_fiscal' => '603', 'emisor_cp' => '44100',
        'receptor_rfc' => $receptor['rfc'], 'receptor_razon_social' => $receptor['razon_social'],
        'receptor_uso_cfdi' => 'D10', 'receptor_regimen_fiscal' => '605', 'receptor_cp' => '44100',
        'forma_pago_sat' => '01', 'subtotal' => 100, 'iva' => 0, 'total' => 100,
    ]);

    echo PHP_EOL.'2. Un PAC que no consulta no inventa una conciliación'.PHP_EOL;

    PacQueContesta::$concilia = false;
    PacQueContesta::$consultas = 0;

    $r = $conciliador->conciliar();

    verificar('Se dice UNA vez, no factura por factura', $r['omitido'] !== null, (string) $r['omitido']);
    verificar('Y no se le pregunta a ninguna', PacQueContesta::$consultas === 0);
    // Es lo que impide que el modo de prueba deje la columna escrita y luego
    // alguien lea «conciliada» donde no se concilió nada.
    verificar('Ni se le escribe nada a la factura', $cuadra->fresh()->sat_consultado_en === null);

    echo PHP_EOL.'3. La conciliación de verdad'.PHP_EOL;

    PacQueContesta::$concilia = true;
    PacQueContesta::$preguntadas = [];
    PacQueContesta::$respuestas = [
        $cuadra->id => EstadoEnElPac::vigente(),
        $canceladaFuera->id => EstadoEnElPac::cancelada(EstadoEnElPac::CANCELACION_ACEPTADA),
        $sinCompletar->id => EstadoEnElPac::vigente(EstadoEnElPac::CANCELACION_PENDIENTE),
    ];

    $r = $conciliador->conciliar();

    // Un borrador no existe para el SAT: preguntarle por él sería una llamada
    // —que se cobra— por cada intento fallido de la escuela.
    verificar('Al borrador ni se le pregunta',
        ! in_array($borrador->id, PacQueContesta::$preguntadas, true));

    $motivos = collect($r['discrepancias'])->keyBy('id');

    verificar('Se reportan exactamente dos discrepancias', count($r['discrepancias']) === 2,
        (string) count($r['discrepancias']));
    verificar('La que cuadra no se reporta', ! $motivos->has($cuadra->id));
    verificar('La cancelada por fuera sí, y lo dice',
        str_contains($motivos[$canceladaFuera->id]['motivo'] ?? '', 'CANCELADA'),
        $motivos[$canceladaFuera->id]['motivo'] ?? 'sin motivo');
    verificar('Y la cancelación que no se completó también',
        str_contains($motivos[$sinCompletar->id]['motivo'] ?? '', 'VIGENTE'),
        $motivos[$sinCompletar->id]['motivo'] ?? 'sin motivo');

    // Una cancelación pedida y sin resolver no es discrepancia POR SÍ SOLA,
    // pero aquí además la base ya dice «cancelada», así que sí lo es. Lo que se
    // comprueba es que el conteo la registre como en espera.
    verificar('La cancelación pendiente se cuenta aparte', $r['enEspera'] === 1, (string) $r['enEspera']);

    echo PHP_EOL.'4. Lo que se guarda, y lo que NO se toca'.PHP_EOL;

    $cuadra->refresh();
    $canceladaFuera->refresh();

    verificar('Se anota lo que dijo el SAT', $cuadra->sat_estado === EstadoEnElPac::VIGENTE);
    verificar('Y cuándo se preguntó', $cuadra->sat_consultado_en !== null);
    verificar('El estado de la cancelación va en su propia columna',
        $canceladaFuera->sat_estado_cancelacion === EstadoEnElPac::CANCELACION_ACEPTADA);

    // LA REGLA. Sin ella, un comando de madrugada liberaría los pagos de esta
    // factura y alguien podría volver a facturar el mismo dinero.
    verificar('El estatus NUESTRO no se toca aunque el SAT diga otra cosa',
        $canceladaFuera->estatus === Factura::ESTATUS_TIMBRADA, $canceladaFuera->estatus);
    verificar('Ni al revés', $sinCompletar->fresh()->estatus === Factura::ESTATUS_CANCELADA);

    echo PHP_EOL.'5. Que el PAC no conteste NO es que todo esté bien'.PHP_EOL;

    PacQueContesta::$respuestas[$cuadra->id] = EstadoEnElPac::desconocido('El PAC no responde.');

    $r = $conciliador->conciliar();
    $cuadra->refresh();

    verificar('Se cuenta como sin respuesta', $r['sinRespuesta'] === 1, (string) $r['sinRespuesta']);
    verificar('Y NO como discrepancia', ! collect($r['discrepancias'])->contains('id', $cuadra->id));
    verificar('El motivo queda en la factura', $cuadra->sat_error === 'El PAC no responde.');
    // Dejar el estado anterior sería peor que borrarlo: se leería como una
    // consulta buena de hoy cuando la de hoy falló.
    verificar('Y el estado viejo se borra en vez de quedarse', $cuadra->sat_estado === null);
    verificar('Sin estado, no hay discrepancia que reportar', $cuadra->discrepanciaSat() === null);

    echo PHP_EOL.'5 bis. Lo que no se ha consultado no discrepa'.PHP_EOL;

    $nuncaConsultada = Factura::create([
        'matricula_oferta_id' => $matricula->id,
        'emisor_id' => $emisorEscuela->id, 'emisor_rfc' => 'AAA010101AAA',
        'emisor_razon_social' => 'ESCUELA DEMO SC', 'emisor_regimen_fiscal' => '603', 'emisor_cp' => '44100',
        'receptor_rfc' => $receptor['rfc'], 'receptor_razon_social' => $receptor['razon_social'],
        'receptor_uso_cfdi' => 'D10', 'receptor_regimen_fiscal' => '605', 'receptor_cp' => '44100',
        'forma_pago_sat' => '01', 'subtotal' => 100, 'iva' => 0, 'total' => 100,
        'uuid' => 'NUNCA-CONSULTADA', 'estatus' => Factura::ESTATUS_CANCELADA,
        'fecha_timbrado' => now()->subDay(), 'cancelada_en' => now(),
    ]);

    // Es el caso peligroso: cancelada aquí y sin dato del SAT. Sin el guard, el
    // modelo concluiría «el SAT la tiene vigente» sin haberle preguntado a
    // nadie, y la escuela iría a revisar un desajuste inventado.
    verificar('Una cancelada que nunca se consultó no reporta discrepancia',
        $nuncaConsultada->discrepanciaSat() === null, (string) $nuncaConsultada->discrepanciaSat());
    verificar('Ni aparece en el filtro',
        ! Factura::query()->conDiscrepanciaSat()->pluck('id')->contains($nuncaConsultada->id));

    echo PHP_EOL.'5 ter. Un PAC que se cae no es un PAC que dice «vigente»'.PHP_EOL;

    PacQueContesta::$revientan = [$nuncaConsultada->id => true];
    $r = $conciliador->conciliar();
    $nuncaConsultada->refresh();

    verificar('La excepción se anota como sin respuesta', $r['sinRespuesta'] >= 1, (string) $r['sinRespuesta']);
    verificar('Con su mensaje en la factura',
        str_contains((string) $nuncaConsultada->sat_error, 'se cayó'), (string) $nuncaConsultada->sat_error);
    // Darla por vigente convertiría una caída del proveedor en una afirmación
    // sobre el SAT —y ésta, además, en una discrepancia inventada—.
    verificar('Y NO se da por vigente', $nuncaConsultada->sat_estado === null,
        (string) $nuncaConsultada->sat_estado);
    verificar('Ni se inventa una discrepancia', $nuncaConsultada->discrepanciaSat() === null);

    PacQueContesta::$revientan = [];

    echo PHP_EOL.'5 quater. Sólo se le pregunta por lo FISCAL'.PHP_EOL;

    // Fila artificial a propósito: con fecha de timbrado y sin folio fiscal. La
    // aplicación no la produce —el job escribe las dos a la vez— y por eso es la
    // única forma de comprobar que lo que acota la consulta es el FOLIO y no la
    // fecha. Sin ella, las dos condiciones se tapan entre sí y una podría
    // borrarse sin que nada lo notara.
    $sinFolio = Factura::create([
        'matricula_oferta_id' => $matricula->id,
        'emisor_id' => $emisorEscuela->id, 'emisor_rfc' => 'AAA010101AAA',
        'emisor_razon_social' => 'ESCUELA DEMO SC', 'emisor_regimen_fiscal' => '603', 'emisor_cp' => '44100',
        'receptor_rfc' => $receptor['rfc'], 'receptor_razon_social' => $receptor['razon_social'],
        'receptor_uso_cfdi' => 'D10', 'receptor_regimen_fiscal' => '605', 'receptor_cp' => '44100',
        'forma_pago_sat' => '01', 'subtotal' => 100, 'iva' => 0, 'total' => 100,
        'estatus' => Factura::ESTATUS_ERROR, 'fecha_timbrado' => now()->subDay(),
    ]);

    PacQueContesta::$preguntadas = [];
    $conciliador->conciliar();

    verificar('A una sin folio fiscal no se le pregunta',
        ! in_array($sinFolio->id, PacQueContesta::$preguntadas, true));

    $sinFolio->forceDelete();
    $nuncaConsultada->forceDelete();

    echo PHP_EOL.'6. El filtro del listado'.PHP_EOL;

    // Se devuelve a un estado conocido para medir el filtro.
    PacQueContesta::$respuestas[$cuadra->id] = EstadoEnElPac::vigente();
    $conciliador->conciliar();

    $conDiscrepancia = Factura::query()->conDiscrepanciaSat()->pluck('id')->all();

    verificar('Trae exactamente las dos que no cuadran',
        count($conDiscrepancia) === 2
        && in_array($canceladaFuera->id, $conDiscrepancia, true)
        && in_array($sinCompletar->id, $conDiscrepancia, true),
        implode(', ', $conDiscrepancia));

    // La precondición de la comprobación siguiente, escrita: sin estos dos
    // estados exactos, el `or` suelto no tiene por dónde escaparse y la prueba
    // pasaría sin comprobar nada.
    $sinCompletar->refresh();
    verificar('El escenario tiene una cancelada aquí y vigente allá, que es por donde se escapa el or',
        $sinCompletar->estatus === Factura::ESTATUS_CANCELADA
        && $sinCompletar->sat_estado === EstadoEnElPac::VIGENTE,
        $sinCompletar->estatus.' / '.(string) $sinCompletar->sat_estado);

    // Las dos condiciones del scope van entre paréntesis: un `or` suelto se
    // llevaría por delante el resto de los filtros del listado y devolvería
    // facturas que sí cuadran. Es la trampa que este proyecto ya se cobró.
    $soloTimbradas = Factura::query()
        ->where('estatus', Factura::ESTATUS_TIMBRADA)
        ->conDiscrepanciaSat()
        ->pluck('id')->all();

    verificar('Y combinado con otro filtro no se lo lleva por delante',
        $soloTimbradas === [$canceladaFuera->id], implode(', ', $soloTimbradas));

    echo PHP_EOL.'7. El comando'.PHP_EOL;

    // `Tenant::run()` conserva la transacción abierta —comprobado—, así que el
    // comando se puede correr aquí sin dejar basura en el demo.
    $codigo = Artisan::call('finanzas:conciliar-cfdi', ['--tenant' => ['demo']]);

    // Un comando programado que termina en verde teniendo comprobantes que no
    // cuadran es exactamente cómo esto se queda sin mirar durante meses.
    verificar('Sale con ERROR cuando hay discrepancias', $codigo !== 0, 'código '.$codigo);
    verificar('Y nombra las facturas concretas en su salida',
        str_contains(Artisan::output(), (string) $canceladaFuera->id));

    PacQueContesta::$respuestas = [
        $cuadra->id => EstadoEnElPac::vigente(),
        $canceladaFuera->id => EstadoEnElPac::vigente(),
        $sinCompletar->id => EstadoEnElPac::cancelada(),
    ];

    $codigo = Artisan::call('finanzas:conciliar-cfdi', ['--tenant' => ['demo']]);

    verificar('Y con todo cuadrando sale en verde', $codigo === 0, 'código '.$codigo);
    verificar('Con las facturas ya sin discrepancia',
        Factura::query()->conDiscrepanciaSat()->count() === 0);

    echo PHP_EOL.'8. La ventana de días'.PHP_EOL;

    // Timbrada hace tres meses: fuera de la ventana por omisión.
    $cuadra->forceFill(['fecha_timbrado' => now()->subDays(120)])->save();
    PacQueContesta::$preguntadas = [];

    $conciliador->conciliar(90);

    verificar('Lo viejo queda fuera de la ventana',
        ! in_array($cuadra->id, PacQueContesta::$preguntadas, true));

    PacQueContesta::$preguntadas = [];
    $conciliador->conciliar(365);

    verificar('Y entra si se pide más historia',
        in_array($cuadra->id, PacQueContesta::$preguntadas, true));

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }
} finally {
    DB::rollBack();

    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }
}

exit($fallos === [] ? 0 : 1);
