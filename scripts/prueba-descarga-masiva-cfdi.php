<?php

/**
 * El paquete mensual de comprobantes para contabilidad.
 *
 * `php scripts/prueba-descarga-masiva-cfdi.php` desde la raíz. Contra la BD
 * real del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Un ZIP no protesta. Si le faltan tres XML porque la descarga desde el PAC
 * falló, se ve exactamente igual que uno completo y se entrega a contabilidad
 * como si lo fuera. Por eso lo que de verdad se comprueba aquí no es que el
 * archivo exista, sino que el MANIFIESTO diga la verdad sobre lo que hay
 * dentro y sobre lo que falta.
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
use App\Services\DescargaMasivaCfdi;
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

config(['queue.default' => 'sync']);

$ok = 0;
$fallos = [];
$archivos = [];
$temporales = [];

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

/** @return array<int, string> los nombres dentro del ZIP */
function contenido(string $ruta): array
{
    $zip = new ZipArchive;
    $zip->open($ruta);
    $nombres = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nombres[] = $zip->getNameIndex($i);
    }

    $zip->close();

    return $nombres;
}

function leerDelZip(string $ruta, string $nombre): string
{
    $zip = new ZipArchive;
    $zip->open($ruta);
    $texto = (string) $zip->getFromName($nombre);
    $zip->close();

    return $texto;
}

$receptor = [
    'rfc' => 'GUME900101AB1',
    'razon_social' => '=MARIA GUTIERREZ MENDOZA',
    'uso_cfdi' => 'D10',
    'regimen_fiscal' => '605',
    'cp' => '44100',
];

DB::beginTransaction();

try {
    $emisorFacturas = app(EmisorFactura::class);
    $notas = app(EmisorNotaCredito::class);
    $registrador = app(RegistradorPago::class);
    $descarga = new DescargaMasivaCfdi;

    echo '1. El escenario'.PHP_EOL;

    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603', 'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $persona = Persona::create(['nombre' => 'Paquete', 'primer_apellido' => 'Mensual', 'sexo_id' => 2]);
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

    $normal = $emitir(1000.00);
    $cancelada = $emitir(1100.00);
    $sinXml = $emitir(1200.00);

    // Una nota de crédito, para comprobar que el manifiesto la distingue: su
    // importe RESTA y confundirla con una factura descuadra el mes.
    $nota = $notas->emitir(
        $normal,
        [['concepto_id' => $normal->conceptos()->first()->id, 'importe' => 100.0]],
        'Ajuste del periodo',
    )->refresh();

    foreach ([$nota->xml_ruta, $nota->pdf_ruta] as $ruta) {
        if ($ruta !== null) {
            $archivos[] = $ruta;
        }
    }

    $cancelada->update(['estatus' => Factura::ESTATUS_CANCELADA, 'cancelada_en' => now(), 'motivo_cancelacion' => '02']);

    // A ésta se le quita el archivo del disco dejando la ruta puesta: es lo que
    // pasa cuando la descarga desde el PAC falla, y el caso que el manifiesto
    // existe para delatar.
    Storage::disk('local')->delete((string) $sinXml->xml_ruta);

    // El PAC de prueba no devuelve PDF, así que el caso se CONSTRUYE, y se
    // construye ANTES de armar el primer paquete: comprobando «sin PDF si no se
    // piden» sobre un escenario sin un solo PDF, esa regla se cumple sola.
    $rutaPdf = 'facturas/prueba-'.$normal->id.'.pdf';
    Storage::disk('local')->put($rutaPdf, '%PDF-1.4 comprobante de prueba');
    $archivos[] = $rutaPdf;
    $normal->forceFill(['pdf_ruta' => $rutaPdf])->save();

    $hoy = now()->toDateString();

    verificar('Hay cuatro comprobantes fiscales del día',
        Factura::query()->whereNotNull('uuid')->whereDate('fecha_timbrado', $hoy)->count() === 4,
        (string) Factura::query()->whereNotNull('uuid')->whereDate('fecha_timbrado', $hoy)->count());

    echo PHP_EOL.'2. Lo que se rehúsa'.PHP_EOL;

    [$bien, $mensaje] = seNiega(
        fn () => $descarga->armar(Factura::query(), '2020-01-01', '2020-01-31'),
        'No hay comprobantes timbrados',
    );
    verificar('Un periodo sin nada se rehúsa en vez de dar un ZIP vacío', $bien, $mensaje);

    // Se REHÚSA en vez de recortar: un paquete truncado en silencio se entrega
    // como si fuera el mes completo.
    [$bien, $mensaje] = seNiega(
        fn () => (new DescargaMasivaCfdi(tope: 2))->armar(Factura::query(), $hoy, $hoy),
        'el tope por descarga es 2',
    );
    verificar('Pasarse del tope se rehúsa, no se recorta', $bien, $mensaje);
    verificar('Y el mensaje dice cuántos son y qué hacer',
        str_contains($mensaje, '4 comprobantes') && str_contains($mensaje, 'Parte el periodo'), $mensaje);

    // Los dos rechazos ocurren ANTES de crear el temporal: un intento fallido no
    // puede dejar basura en la partición.
    $antes = glob(sys_get_temp_dir().'/cfdi*') ?: [];
    seNiega(fn () => $descarga->armar(Factura::query(), '2020-01-01', '2020-01-31'), 'x');
    $despues = glob(sys_get_temp_dir().'/cfdi*') ?: [];

    verificar('Un rechazo no deja temporales', count($antes) === count($despues),
        count($antes).' -> '.count($despues));

    echo PHP_EOL.'3. El paquete'.PHP_EOL;

    $paquete = $descarga->armar(Factura::query(), $hoy, $hoy);
    $temporales[] = $paquete['ruta'];

    $nombres = contenido($paquete['ruta']);

    verificar('Cuenta los cuatro comprobantes', $paquete['comprobantes'] === 4, (string) $paquete['comprobantes']);
    verificar('Y avisa de que a uno le falta el XML', $paquete['sinArchivo'] === 1, (string) $paquete['sinArchivo']);
    verificar('El nombre del archivo dice el periodo',
        $paquete['nombre'] === "cfdi-{$hoy}-a-{$hoy}.zip", $paquete['nombre']);

    verificar('Lleva un XML por cada comprobante que lo tenía guardado',
        count(array_filter($nombres, fn ($n) => str_ends_with($n, '.xml'))) === 3,
        implode(' · ', $nombres));

    verificar('Cada uno se llama por su fecha y su folio fiscal',
        in_array($normal->fecha_timbrado->format('Y-m-d').'_'.$normal->uuid.'.xml', $nombres, true));

    // La contabilidad electrónica pide también las canceladas: dejarlas fuera
    // haría un paquete que no cuadra con lo que el SAT tiene registrado.
    verificar('La CANCELADA va dentro',
        in_array($cancelada->fecha_timbrado->format('Y-m-d').'_'.$cancelada->uuid.'.xml', $nombres, true));

    verificar('Sin PDF si no se piden',
        array_filter($nombres, fn ($n) => str_ends_with($n, '.pdf')) === []);

    $conPdf = $descarga->armar(Factura::query(), $hoy, $hoy, conPdf: true);
    $temporales[] = $conPdf['ruta'];

    verificar('Y con PDF si se piden',
        array_filter(contenido($conPdf['ruta']), fn ($n) => str_ends_with($n, '.pdf')) !== []);

    echo PHP_EOL.'4. El manifiesto, que es la mitad del entregable'.PHP_EOL;

    verificar('Va siempre dentro', in_array('manifiesto.csv', $nombres, true));

    $csv = leerDelZip($paquete['ruta'], 'manifiesto.csv');
    $lineas = array_values(array_filter(explode("\r\n", $csv)));

    verificar('Con una cabecera y un renglón por comprobante', count($lineas) === 5, (string) count($lineas));

    // Es lo que hace que la ausencia de un archivo deje de ser invisible: el
    // ZIP trae tres XML y el manifiesto dice que debería haber cuatro.
    verificar('El que NO tiene XML aparece igual, y dice que falta',
        str_contains($csv, $sinXml->uuid) && str_contains($csv, 'NO — el XML no está guardado'));

    verificar('La nota de crédito se distingue de una factura',
        str_contains($csv, 'Egreso (nota de crédito)'));
    verificar('Y la cancelada dice que lo está', str_contains($csv, 'cancelada'));
    verificar('Lo no conciliado se dice, en vez de dejarlo en blanco',
        str_contains($csv, 'sin conciliar'));

    // Excel toma como fórmula lo que empieza por `=`, y la razón social la
    // escribió alguien de fuera. Sin neutralizar, abrir el manifiesto ejecuta
    // lo que ese texto diga.
    verificar('La razón social que empieza con `=` sale neutralizada',
        str_contains($csv, '"\'=MARIA GUTIERREZ MENDOZA"'), substr($csv, 0, 0).'ver CSV');

    // Sin BOM, Excel abre el CSV en su codificación local y los acentos de las
    // razones sociales salen rotos.
    verificar('Y el CSV lleva BOM para que Excel no rompa los acentos',
        str_starts_with($csv, "\xEF\xBB\xBF"));

    echo PHP_EOL.'5. El periodo acota de verdad'.PHP_EOL;

    // Una factura de ayer: fuera del rango de hoy.
    $normal->forceFill(['fecha_timbrado' => now()->subDays(3)])->save();

    $recorte = $descarga->armar(Factura::query(), $hoy, $hoy);
    $temporales[] = $recorte['ruta'];

    verificar('Lo de fuera del periodo no entra', $recorte['comprobantes'] === 3,
        (string) $recorte['comprobantes']);
    verificar('Y su XML tampoco',
        ! in_array($normal->fecha_timbrado->format('Y-m-d').'_'.$normal->uuid.'.xml',
            contenido($recorte['ruta']), true));

    echo PHP_EOL.'6. La consulta que se le pasa manda'.PHP_EOL;

    // El controlador le entrega la consulta YA acotada por campus. Si el
    // servicio la ignorara, quien alcanza un solo plantel se llevaría en un ZIP
    // los CFDI de toda la escuela — justo lo que su alcance le niega en la
    // pantalla.
    $acotado = $descarga->armar(
        Factura::query()->whereKey($cancelada->id),
        $hoy, $hoy,
    );
    $temporales[] = $acotado['ruta'];

    verificar('Se respeta el recorte que trae la consulta', $acotado['comprobantes'] === 1,
        (string) $acotado['comprobantes']);

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }
} finally {
    DB::rollBack();

    // Ni la transacción ni el rollback alcanzan al sistema de archivos.
    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }

    foreach ($temporales as $ruta) {
        @unlink($ruta);
    }
}

exit($fallos === [] ? 0 : 1);
