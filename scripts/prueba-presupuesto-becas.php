<?php

/**
 * Patrocinadores y presupuesto de becas.
 *
 * `php scripts/prueba-presupuesto-becas.php` desde la raíz. Contra la BD real
 * del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Que «cuánto llevamos becado» sea un HECHO y no una estimación. El ejercido
 * sale de `adeudo_ajustes` —un renglón por cada beca que movió un cargo—, así
 * que se puede auditar renglón a renglón; un número inventado, puesto al lado
 * de uno medido, se lee igual de cierto.
 *
 * Y que la bolsa de cada patrocinador no se mezcle con la de otro: si la suma
 * arrastrara becas ajenas, el aviso de «te pasaste» saldría sobre quien no se
 * pasó.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Patrocinador;
use App\Models\Finanzas\PresupuestoBeca;
use App\Models\Tenant;
use App\Services\PresupuestoDeBecas;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
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

DB::beginTransaction();

try {
    $presupuesto = app(PresupuestoDeBecas::class);

    echo '1. La bolsa propia viene sembrada'.PHP_EOL;

    $escuela = Patrocinador::where('clave', 'escuela')->first();

    verificar('«La escuela» existe', $escuela !== null);
    // Protegida: es el valor por omisión de toda beca nueva y hay becas
    // colgando de ella.
    verificar('Y está protegida', (bool) $escuela?->protegido);

    // Con `patrocinador_id` nulo, el único de la bolsa no serviría: MySQL
    // considera distintos dos NULL y se podrían crear dos presupuestos de la
    // escuela para el mismo ciclo.
    verificar('Toda beca tiene patrocinador',
        Beca::query()->whereNull('patrocinador_id')->doesntExist());

    echo PHP_EOL.'2. El escenario: dos bolsas y dos becas'.PHP_EOL;

    $fundacion = Patrocinador::create([
        'clave' => 'fund-prueba', 'nombre' => 'Fundación de prueba', 'activo' => true,
    ]);

    $ciclo = Ciclo::query()->firstOrFail();
    $concepto = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $matricula = DB::table('matricula_oferta')->value('id');

    $becaEscuela = Beca::create([
        'clave' => 'presu-escuela', 'nombre' => 'Beca de la escuela',
        'modo' => 'porcentaje', 'valor' => 0.5, 'patrocinador_id' => $escuela->id, 'activo' => true,
    ]);
    $becaFundacion = Beca::create([
        'clave' => 'presu-fundacion', 'nombre' => 'Beca de la fundación',
        'modo' => 'porcentaje', 'valor' => 0.5, 'patrocinador_id' => $fundacion->id, 'activo' => true,
    ]);

    $otorgar = function (Beca $beca) use ($matricula, $ciclo) {
        return BecaAlumno::create([
            'matricula_oferta_id' => $matricula,
            'beca_id' => $beca->id,
            'ciclo_id' => $ciclo->id,
            'estatus' => BecaAlumno::ACTIVA,
            'vigente_desde' => '2026-01-01',
        ]);
    };

    $deLaEscuela = $otorgar($becaEscuela);
    $deLaFundacion = $otorgar($becaFundacion);

    // Los ajustes son lo que de verdad se descontó. Van con signo NEGATIVO: el
    // total del cargo es `monto + SUM(ajustes)`.
    $descontar = function (BecaAlumno $otorgada, float $monto) use ($matricula, $concepto) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula, 'concepto_id' => $concepto->id,
            'monto' => 2000, 'monto_total' => 2000 - $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);

        return AdeudoAjuste::create([
            'adeudo_id' => $adeudo->id,
            'tipo' => AdeudoAjuste::TIPO_BECA,
            'origen_id' => $otorgada->id,
            'etiqueta' => $otorgada->beca->nombre,
            'monto' => -$monto,
        ]);
    };

    $descontar($deLaEscuela, 1000.0);
    $descontar($deLaEscuela, 500.0);
    $descontar($deLaFundacion, 800.0);

    echo PHP_EOL.'3. El ejercido se mide'.PHP_EOL;

    // Un número positivo: lo que se dejó de cobrar. Los ajustes van negativos.
    verificar('La bolsa de la escuela lleva 1 500',
        $presupuesto->ejercido($escuela->id, $ciclo->id) >= 1500.0,
        (string) $presupuesto->ejercido($escuela->id, $ciclo->id));
    verificar('Y la de la fundación 800',
        $presupuesto->ejercido($fundacion->id, $ciclo->id) === 800.0,
        (string) $presupuesto->ejercido($fundacion->id, $ciclo->id));

    // La comprobación que de verdad importa: si la suma arrastrara becas
    // ajenas, el aviso de «te pasaste» saldría sobre quien no se pasó.
    verificar('Cada bolsa cuenta SÓLO lo suyo',
        $presupuesto->ejercido($fundacion->id, $ciclo->id) === 800.0);

    /*
     * Y sólo los ajustes de BECA.
     *
     * `origen_id` apunta a tablas distintas según el `tipo` —una beca otorgada,
     * una regla de recargo, un descuento—, así que los ids CHOCAN todo el
     * tiempo. Sin filtrar por tipo, el recargo de abajo se sumaría a la bolsa de
     * la fundación por el simple hecho de tener el mismo número.
     */
    $conCargo = Adeudo::create([
        'matricula_oferta_id' => $matricula, 'concepto_id' => $concepto->id,
        'monto' => 1000, 'monto_total' => 1250,
        'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);

    AdeudoAjuste::create([
        'adeudo_id' => $conCargo->id,
        'tipo' => AdeudoAjuste::TIPO_RECARGO,
        // El MISMO número que la beca otorgada de la fundación, que es
        // exactamente lo que pasa en producción.
        'origen_id' => $deLaFundacion->id,
        'etiqueta' => 'Recargo por mora',
        'monto' => 250,
    ]);

    verificar('Un recargo con el mismo origen_id no entra en la bolsa',
        $presupuesto->ejercido($fundacion->id, $ciclo->id) === 800.0,
        (string) $presupuesto->ejercido($fundacion->id, $ciclo->id));

    // Y sólo lo de SU ciclo: la bolsa es de un ciclo, y arrastrar el gasto del
    // anterior haría que el aviso saliera el primer día del año.
    $otroCiclo = Ciclo::query()->where('id', '!=', $ciclo->id)->first();

    if ($otroCiclo !== null) {
        verificar('Y sólo lo de su ciclo',
            $presupuesto->ejercido($fundacion->id, $otroCiclo->id) === 0.0,
            (string) $presupuesto->ejercido($fundacion->id, $otroCiclo->id));
    } else {
        // Guardia RUIDOSA: sin un segundo ciclo esta regla no se ejercita, y
        // callarlo dejaría una comprobación que se apaga sola.
        verificar('Y sólo lo de su ciclo (hace falta un segundo ciclo en el demo)', false);
    }

    echo PHP_EOL.'4. El panorama'.PHP_EOL;

    PresupuestoBeca::create([
        'patrocinador_id' => $fundacion->id, 'ciclo_id' => $ciclo->id,
        'monto' => 1000.0, 'notas' => 'Convenio 2026',
    ]);

    $bolsas = collect($presupuesto->panorama($ciclo->id));
    $laFundacion = $bolsas->firstWhere('patrocinador_id', $fundacion->id);
    $laEscuela = $bolsas->firstWhere('patrocinador_id', $escuela->id);

    verificar('Sale la bolsa con lo asignado', ($laFundacion['asignado'] ?? null) === 1000.0);
    verificar('Con su ejercido', ($laFundacion['ejercido'] ?? null) === 800.0);
    verificar('Y lo disponible', ($laFundacion['disponible'] ?? null) === 200.0);
    verificar('Sin excederse todavía', ($laFundacion['excedido'] ?? null) === false);
    verificar('Y cuenta las becas otorgadas', ($laFundacion['otorgadas'] ?? null) === 1);

    // Salen TODOS los activos, tengan bolsa o no: uno sin presupuesto que ya
    // lleva becas dadas es exactamente lo que hay que ver.
    verificar('El patrocinador SIN bolsa asignada sale igual', $laEscuela !== null);
    // Y su disponible es NULL, no cero: «nadie ha dicho cuánto hay» es distinto
    // de «ya no queda».
    verificar('Con disponible nulo, que no es cero',
        array_key_exists('disponible', $laEscuela) && $laEscuela['disponible'] === null);
    verificar('Pero con su ejercido a la vista', ($laEscuela['ejercido'] ?? 0) >= 1500.0);

    echo PHP_EOL.'5. Pasarse se avisa'.PHP_EOL;

    $descontar($deLaFundacion, 400.0);

    $laFundacion = collect($presupuesto->panorama($ciclo->id))->firstWhere('patrocinador_id', $fundacion->id);

    verificar('El ejercido sube a 1 200', ($laFundacion['ejercido'] ?? null) === 1200.0,
        (string) ($laFundacion['ejercido'] ?? 0));
    verificar('Y la bolsa queda marcada como excedida', ($laFundacion['excedido'] ?? null) === true);
    // El disponible NEGATIVO es el dato: decir cuánto se pasó, no sólo que se
    // pasó.
    verificar('Con el disponible en negativo', ($laFundacion['disponible'] ?? null) === -200.0);

    echo PHP_EOL.'6. Una bolsa por patrocinador y ciclo'.PHP_EOL;

    $reventó = false;

    try {
        PresupuestoBeca::create([
            'patrocinador_id' => $fundacion->id, 'ciclo_id' => $ciclo->id, 'monto' => 5000.0,
        ]);
    } catch (UniqueConstraintViolationException) {
        $reventó = true;
    }

    // Con dos bolsas del mismo ciclo, ninguna de las dos cifras sería la buena y
    // el aviso dependería de cuál se leyera primero.
    verificar('La base impide la segunda bolsa del mismo ciclo', $reventó);

    echo PHP_EOL.'7. La pantalla, invocada de verdad'.PHP_EOL;

    // Comprobar el servicio no basta cuando la consulta que revienta la arma el
    // controlador. Es la lección de la rebanada de caja.
    $peticion = Illuminate\Http\Request::create('/', 'GET', ['ciclo' => $ciclo->id]);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    app()->instance('request', $peticion);

    $props = json_decode(
        app(App\Http\Controllers\BecaController::class)
            ->presupuesto($peticion, $presupuesto)
            ->toResponse($peticion)->getContent(),
        true
    )['props'];

    verificar('La pantalla responde con sus bolsas', count($props['bolsas'] ?? []) >= 2);
    verificar('Y con los patrocinadores', collect($props['patrocinadores'])->contains('clave', 'fund-prueba'));
    verificar('Y con los ciclos que se pueden presupuestar', count($props['ciclos'] ?? []) >= 1);

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
