<?php

/**
 * El recibo que se entrega en ventanilla.
 *
 * `php scripts/prueba-recibo-de-caja.php` desde la raíz. Contra la BD real del
 * tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Un PDF no protesta: sale igual de bonito diciendo la verdad o diciendo otra
 * cosa. Lo que aquí importa es que DIGA que no es un comprobante fiscal —un
 * papel con el logo de la escuela, un folio y un importe se archiva creyendo que
 * se puede deducir— y que enseñe qué cargos cubrió, que es la pregunta que este
 * papel viene a evitar en el mostrador.
 *
 * Se comprueba sobre el HTML que se le entrega a mpdf y no sobre los bytes del
 * PDF: el subsetting de fuentes hace frágil buscar cadenas dentro del archivo.
 * Es la misma decisión que tomó `HistorialPdfTest` con su motor espía.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Documentos\DocumentoPdf;
use App\Documentos\ReciboDeCaja;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
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
        echo "  OK    {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/**
 * Un `DocumentoPdf` espía: se queda con el HTML y las opciones en vez de armar
 * el PDF.
 *
 * Así se puede afirmar QUÉ dice el recibo y con qué papel se manda a imprimir,
 * que es lo que de verdad se quiere comprobar. Buscar cadenas dentro de los
 * bytes del PDF depende del subsetting de fuentes y es frágil.
 */
class PdfEspia extends DocumentoPdf
{
    public string $html = '';

    /** @var array<string, mixed> */
    public array $opciones = [];

    public function generar(string $html, array $opciones = []): string
    {
        $this->html = $html;
        $this->opciones = $opciones;

        return '%PDF-espía';
    }
}

DB::beginTransaction();

try {
    $espia = new PdfEspia;
    $recibo = new ReciboDeCaja($espia);
    $registrador = app(RegistradorPago::class);

    echo '1. El escenario'.PHP_EOL;

    $persona = Persona::create(['nombre' => 'Recibo', 'primer_apellido' => 'DePrueba', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $constancia = ConceptoPago::where('clave', 'constancia')->firstOrFail();
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $transferencia = MetodoPago::where('clave', 'transferencia')->firstOrFail();

    $cargar = function (ConceptoPago $concepto, float $monto, ?string $periodo = null) use ($matricula) {
        return Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $concepto->id,
            'monto' => $monto, 'monto_total' => $monto, 'periodo_etiqueta' => $periodo,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);
    };

    $unaColegiatura = $cargar($colegiatura, 1500.00, 'Marzo 2026');
    $unaConstancia = $cargar($constancia, 232.00);

    $pago = $registrador->registrar(
        $matricula, $efectivo, 1732.00, [$unaColegiatura->id, $unaConstancia->id], 'REF-123'
    );

    verificar('El pago nace cobrado', $pago->estaCobrado(), $pago->estatus);
    verificar('Y cubre los dos cargos', $pago->adeudos()->count() === 2);

    echo PHP_EOL.'2. Lo que dice el recibo'.PHP_EOL;

    $recibo->generar($pago);
    $html = $espia->html;

    verificar('Lleva el nombre de quien pagó', str_contains($html, $persona->nombreCompleto()));
    verificar('Y su matrícula', str_contains($html, (string) $matricula->matricula));
    verificar('El folio es el id del pago', str_contains($html, 'Folio '.$pago->id));
    verificar('Dice la forma de pago', str_contains($html, $efectivo->nombre));
    verificar('Y la referencia capturada', str_contains($html, 'REF-123'));

    // Un recibo que sólo diga el importe obliga a preguntar en ventanilla qué se
    // abonó, que es la conversación que este papel viene a evitar.
    verificar('Enumera los cargos que cubrió',
        str_contains($html, $colegiatura->nombre) && str_contains($html, $constancia->nombre));
    verificar('Con el periodo del cargo que lo tiene', str_contains($html, 'Marzo 2026'));
    verificar('Y con lo aplicado a cada uno',
        str_contains($html, '1,500.00') && str_contains($html, '232.00'));
    verificar('Más el total recibido', str_contains($html, 'Total recibido: $1,732.00'));

    // LA regla del documento.
    verificar('Y DICE que no es un comprobante fiscal',
        str_contains($html, 'no es un comprobante fiscal'));
    verificar('Remitiendo a la factura, que se pide aparte',
        str_contains($html, 'CFDI') && str_contains($html, 'solicítala'));

    echo PHP_EOL.'3. Con qué se manda a imprimir'.PHP_EOL;

    // «a5» tiene que estar en el mapa de `DocumentoPdf`: un nombre de papel que
    // no conoce cae a Letter EN SILENCIO, y el recibo saldría del tamaño
    // equivocado sin avisar. Lo dice el docblock de ese método.
    verificar('En media carta', ($espia->opciones['papel'] ?? null) === 'a5');
    verificar('Y vertical', ($espia->opciones['orientacion'] ?? null) === 'vertical');

    $reflexion = new ReflectionMethod(DocumentoPdf::class, 'formato');
    $reflexion->setAccessible(true);

    verificar('Y «a5» lo conoce el formateador, no cae a carta',
        $reflexion->invoke(new DocumentoPdf, 'a5', 'vertical') === 'A5');

    echo PHP_EOL.'4. Un anticipo se dice, no se calla'.PHP_EOL;

    // Un pago sin aplicar deja el recibo sin renglones: sin decirlo, saldría un
    // importe sin ninguna explicación.
    $anticipo = $registrador->registrar($matricula, $efectivo, 500.00, []);

    $recibo->generar($anticipo);

    verificar('El recibo de un anticipo lo nombra',
        str_contains($espia->html, 'Anticipo'), '');
    verificar('Y enseña su importe', str_contains($espia->html, '500.00'));

    echo PHP_EOL.'5. Sólo se imprime lo que de verdad entró'.PHP_EOL;

    // Una transferencia nace pendiente: imprimir su recibo le daría al alumno un
    // papel con el logo de la escuela por dinero que todavía no llegó.
    $pendiente = $registrador->registrar($matricula, $transferencia, 100.00, []);

    verificar('Una transferencia nace pendiente', ! $pendiente->estaCobrado(), $pendiente->estatus);

    $controlador = app(App\Http\Controllers\FinanzasController::class);
    $codigo = null;

    try {
        $controlador->recibo($pendiente, $recibo);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $codigo = $e->getStatusCode();
    }

    verificar('Y su recibo responde 404', $codigo === 404, (string) $codigo);

    // Confirmada, ya es dinero y sí se imprime.
    $registrador->confirmar($pendiente->fresh());
    $respuesta = $controlador->recibo($pendiente->fresh(), $recibo);

    verificar('Confirmada, el recibo sí sale', $respuesta->getStatusCode() === 200);
    verificar('Y va como PDF', str_contains((string) $respuesta->headers->get('Content-Type'), 'application/pdf'));

    echo PHP_EOL.'6. El PDF de verdad'.PHP_EOL;

    // Hasta aquí todo se midió sobre el HTML, que es lo que se puede afirmar
    // con precisión. Esto comprueba lo otro: que mpdf lo dibuje y quepa en UNA
    // hoja. Un recibo de mostrador que salga en dos es un recibo que se entrega
    // a medias, y ningún error lo avisa.
    $bytes = app(ReciboDeCaja::class)->generar($pago->fresh());

    verificar('Se genera un PDF', str_starts_with($bytes, '%PDF'));
    verificar('Y cabe en una sola hoja',
        substr_count($bytes, '/Type /Page') - substr_count($bytes, '/Type /Pages') === 1,
        (substr_count($bytes, '/Type /Page') - substr_count($bytes, '/Type /Pages')).' hoja(s)');

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} catch (Throwable $e) {
    $fallos[] = 'la suite murió antes de terminar';
    echo '  FALLA la suite murió antes de terminar  ['.$e::class.': '.$e->getMessage().']'.PHP_EOL;
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
