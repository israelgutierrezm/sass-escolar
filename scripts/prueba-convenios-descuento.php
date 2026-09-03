<?php

/**
 * Convenios de descuento con empresas, sindicatos y dependencias.
 *
 * `php scripts/prueba-convenios-descuento.php` desde la raíz. Contra la BD real
 * del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Lo que este convenio agrega sobre una beca, que es su única razón de ser: que
 * al TERMINARLO se cierren TODAS sus becas a la vez y los cargos pendientes se
 * recompongan. Un acuerdo terminado que siguiera descontando sería dinero que
 * la escuela deja de cobrar sin que nadie lo decidiera, y nadie lo notaría —el
 * descuento no reclama nada—.
 *
 * Y las dos reglas del otorgamiento: la justificación obligatoria (sin ella
 * nadie puede explicar por qué esa familia califica) y el tope de vigencia, que
 * es lo que hace que el mecanismo que ya existe —`aplicaEn()` mira
 * `vigente_hasta`— apague la beca solo.
 *
 * ── El escenario se construye ENTERO ───────────────────────────────────────
 * Los `use` van ARRIBA del arranque a propósito.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\BecaController;
use App\Http\Controllers\ConvenioDescuentoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\ConvenioDescuento;
use App\Models\Finanzas\Descuento;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Finanzas\ConvenioDeDescuento;
use App\Services\GeneradorAdeudos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

function motivoDe(callable $accion): ?string
{
    try {
        $accion();

        return null;
    } catch (AvisoParaElUsuario $e) {
        return $e->getMessage();
    }
}

DB::beginTransaction();

try {
    $servicio = app(ConvenioDeDescuento::class);
    $control = app(ConvenioDescuentoController::class);
    $becas = app(BecaController::class);
    $generador = app(GeneradorAdeudos::class);

    echo '0. Escenario'.PHP_EOL;

    $usuario = Usuario::findOrFail(1);
    Auth::login($usuario);

    $colegiatura = ConceptoPago::query()->where('clave', 'colegiatura')->firstOrFail();

    // El plan y su línea se construyen: los del demo apuntan a un plan que ya
    // no existe, y sin línea `recalcularPendientes` se salta el cargo.
    $ciclo = App\Models\ControlEscolar\Ciclo::query()->orderByDesc('id')->firstOrFail();

    $plan = App\Models\Finanzas\PlanCobro::create([
        'nombre' => 'Plan de prueba de convenio',
        'ciclo_id' => $ciclo->id,
        'tiene_fecha_limite' => true,
        'fecha_limite_modo' => 'dia_marcado',
        'aplica_recargos' => false,
        'afecta_estatus_deudor' => true,
        'moneda' => 'MXN',
        'vigente_desde' => '2026-01-01',
    ]);

    $linea = ConceptoPlan::create([
        'plan_cobro_id' => $plan->id,
        'concepto_id' => $colegiatura->id,
        'monto' => 4000.00,
        'aplica_recargos' => false,
    ]);

    $matricula = MatriculaOferta::query()->orderBy('id')->firstOrFail();

    $convenio = ConvenioDescuento::create([
        'nombre' => 'Prueba convenio empresa',
        'contraparte' => 'Industrias de Prueba, S.A. de C.V.',
        'rfc' => 'IPR260101AA1',
        'vigente_desde' => '2026-01-01',
        'vigente_hasta' => '2026-12-31',
    ]);

    verificar('El convenio nace vigente', $convenio->estaVigente());
    verificar('Y no vencido', ! $convenio->estaVencido('2026-06-01'));

    $beca = Beca::create([
        'clave' => 'PRB-CONV', 'nombre' => 'Descuento por convenio',
        'modo' => Beca::MODO_PORCENTAJE, 'valor' => 0.25,
        'por_ciclo' => false, 'requiere_renovacion' => false,
        'requiere_pago_puntual' => false, 'activo' => true,
        'convenio_descuento_id' => $convenio->id,
    ]);
    $beca->conceptos()->sync([$colegiatura->id]);

    verificar('La beca son los términos del convenio', $beca->esDeConvenio());
    verificar('Y el convenio la reconoce como suya', $convenio->becas()->count() === 1);

    echo PHP_EOL.'1. Otorgar bajo convenio pide justificación'.PHP_EOL;

    $peticionSinJust = Request::create('/', 'POST', [
        'matricula_oferta_id' => $matricula->id,
        'vigente_desde' => '2026-06-01',
    ]);
    $peticionSinJust->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionSinJust);

    $sinJust = motivoDe(fn () => $becas->otorgar($peticionSinJust, $beca));

    verificar('Sin justificación se rechaza', $sinJust !== null, (string) $sinJust);
    verificar('Y el mensaje dice qué escribir', str_contains((string) $sinJust, 'empleado'));

    // Y una beca NORMAL no la pide: la regla es del convenio, no de todas.
    $normal = Beca::create([
        'clave' => 'PRB-NORMAL', 'nombre' => 'Beca normal de prueba',
        'modo' => Beca::MODO_PORCENTAJE, 'valor' => 0.1,
        'por_ciclo' => false, 'requiere_renovacion' => false,
        'requiere_pago_puntual' => false, 'activo' => true,
    ]);
    $normal->conceptos()->sync([$colegiatura->id]);

    $otraMatricula = MatriculaOferta::query()->where('id', '!=', $matricula->id)->orderBy('id')->firstOrFail();

    $peticionNormal = Request::create('/', 'POST', [
        'matricula_oferta_id' => $otraMatricula->id,
        'vigente_desde' => '2026-06-01',
    ]);
    $peticionNormal->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionNormal);

    verificar('Una beca normal no pide justificación', motivoDe(fn () => $becas->otorgar($peticionNormal, $normal)) === null);

    echo PHP_EOL.'2. La vigencia se capa al fin del convenio'.PHP_EOL;

    verificar(
        'Pedir más allá del fin se capa',
        $servicio->topeDeVigencia($convenio, '2027-06-30') === '2026-12-31'
    );
    verificar(
        'Y pedir menos se respeta',
        $servicio->topeDeVigencia($convenio, '2026-08-31') === '2026-08-31'
    );
    verificar(
        'Sin pedir nada, se pone el fin del convenio',
        $servicio->topeDeVigencia($convenio, null) === '2026-12-31'
    );

    $peticion = Request::create('/', 'POST', [
        'matricula_oferta_id' => $matricula->id,
        'vigente_desde' => '2026-06-01',
        'vigente_hasta' => '2028-01-01',
        'justificacion' => 'Empleado 4471, María Pérez, madre.',
    ]);
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);

    $becas->otorgar($peticion, $beca);

    $otorgada = BecaAlumno::where('beca_id', $beca->id)->firstOrFail();

    verificar('El otorgamiento se creó', $otorgada->exists);
    verificar('Con su justificación guardada', str_contains((string) $otorgada->justificacion, '4471'), (string) $otorgada->justificacion);
    verificar(
        'Y su vigencia capada al fin del convenio',
        $otorgada->vigente_hasta?->toDateString() === '2026-12-31',
        (string) $otorgada->vigente_hasta?->toDateString()
    );
    verificar('Aplica dentro de la vigencia', $otorgada->aplicaEn('2026-09-01'));
    verificar('Y ya no después', ! $otorgada->aplicaEn('2027-01-05'), 'lo apaga `aplicaEn`, sin ninguna condición nueva');

    echo PHP_EOL.'3. El descuento se aplica de verdad'.PHP_EOL;

    /*
     * La línea base ANTES de crear el cargo. Otorgar ya recalculó los cargos
     * que la alumna tenía, y el 25 % se aplicó a todos sus cargos de
     * colegiatura —que es lo correcto—: contra cero, esta comprobación medía la
     * cartera del demo y no el efecto de este cargo.
     */
    $descontadoAntes = $servicio->panorama($convenio)['descontado'];

    $cargo = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'concepto_plan_id' => $linea->id,
        'periodo_etiqueta' => 'PRB-CONV-'.uniqid(),
        'monto' => 4000.00,
        'monto_total' => 4000.00,
        'fecha_generacion' => '2026-06-01',
        'fecha_vencimiento' => '2026-09-01',
        'estatus' => Adeudo::ESTATUS_PENDIENTE,
    ]);

    $generador->recalcularPendientes($matricula);
    $cargo->refresh();

    verificar('El cargo trae su ajuste de beca', $cargo->ajustes()->where('tipo', AdeudoAjuste::TIPO_BECA)->exists());
    verificar('Por el 25 %', abs((float) $cargo->monto_descuentos - 1000.0) < 0.005, (string) $cargo->monto_descuentos);

    $panorama = $servicio->panorama($convenio);

    verificar('El panorama cuenta su beneficiario', $panorama['beneficiarios'] === 1);
    verificar(
        'Y mide lo descontado por ESTE cargo',
        abs(($panorama['descontado'] - $descontadoAntes) - 1000.0) < 0.005,
        'antes '.$descontadoAntes.', ahora '.$panorama['descontado']
    );
    verificar(
        'Sin arrastrar la beca normal, que es de otro convenio',
        $panorama['becas'] === 1,
        'el convenio tiene una beca, no dos'
    );

    // La pantalla, con el convenio TODAVÍA vivo: más abajo el descontado es cero
    // y comparar cero contra cero no probaría nada.
    $peticionViva = Request::create('/', 'GET');
    $peticionViva->headers->set('X-Inertia', 'true');
    $peticionViva->headers->set('X-Inertia-Version', '');
    $peticionViva->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionViva);

    $vivo = collect(json_decode($control->index($peticionViva)->toResponse($peticionViva)->getContent(), true)['props']['convenios'])
        ->firstWhere('id', $convenio->id);

    verificar('La pantalla enseña lo descontado', $vivo['descontado'] > 0, (string) $vivo['descontado']);
    verificar(
        'Y es el mismo número que el servicio',
        abs($vivo['descontado'] - $panorama['descontado']) < 0.005
    );
    verificar('Con su beneficiario', $vivo['beneficiarios'] === 1);

    echo PHP_EOL.'4. Terminar el convenio cierra TODAS sus becas'.PHP_EOL;

    $cerrados = $servicio->terminar($convenio, 'La empresa no renovó.');
    $convenio->refresh();
    $otorgada->refresh();
    $cargo->refresh();

    verificar('Se cerró un otorgamiento', $cerrados === 1, "cerrados: {$cerrados}");
    verificar('El convenio queda terminado', $convenio->estatus === ConvenioDescuento::TERMINADO);
    verificar('Con su motivo y su fecha', $convenio->motivo_termino !== null && $convenio->terminado_en !== null);
    verificar('La beca del alumno queda perdida', $otorgada->estatus === BecaAlumno::PERDIDA, $otorgada->estatus);
    verificar(
        'Y el cargo pendiente se recompone SIN el descuento',
        abs((float) $cargo->monto_descuentos) < 0.005,
        (string) $cargo->monto_descuentos
    );
    verificar(
        'La beca de la OTRA matrícula no se tocó',
        BecaAlumno::where('beca_id', $normal->id)->first()?->estatus === BecaAlumno::ACTIVA,
        'no es de este convenio'
    );

    verificar('Un convenio terminado no se vuelve a terminar', motivoDe(fn () => $servicio->terminar($convenio->fresh(), 'Otra vez.')) !== null);
    verificar(
        'Y no se puede otorgar bajo él',
        $servicio->motivoParaNoOtorgar($convenio->fresh()) !== null,
        (string) $servicio->motivoParaNoOtorgar($convenio->fresh())
    );

    echo PHP_EOL.'5. Un convenio VENCIDO se cierra en el barrido'.PHP_EOL;

    $vencido = ConvenioDescuento::create([
        'nombre' => 'Convenio que ya venció',
        'contraparte' => 'Otra empresa',
        'vigente_desde' => '2025-01-01',
        'vigente_hasta' => '2025-12-31',
    ]);

    $becaVencida = Beca::create([
        'clave' => 'PRB-VENC', 'nombre' => 'Descuento vencido',
        'modo' => Beca::MODO_PORCENTAJE, 'valor' => 0.3,
        'por_ciclo' => false, 'requiere_renovacion' => false,
        'requiere_pago_puntual' => false, 'activo' => true,
        'convenio_descuento_id' => $vencido->id,
    ]);
    $becaVencida->conceptos()->sync([$colegiatura->id]);

    BecaAlumno::create([
        'matricula_oferta_id' => $matricula->id,
        'beca_id' => $becaVencida->id,
        'estatus' => BecaAlumno::ACTIVA,
        'vigente_desde' => '2025-01-01',
        'vigente_hasta' => '2025-12-31',
        'justificacion' => 'Empleado 999.',
    ]);

    verificar('Está vigente de estatus', $vencido->estaVigente());
    verificar('Pero vencido de fecha', $vencido->estaVencido('2026-06-01'), 'son dos preguntas distintas');
    verificar('Y el barrido lo ve', ConvenioDescuento::query()->porVencer('2026-06-01')->count() >= 1);

    $r = $servicio->cerrarVencidos('2026-06-01');

    verificar('El barrido lo cierra', $r['convenios'] >= 1, 'convenios: '.$r['convenios']);
    verificar('Con su beca', $r['becas'] >= 1, 'becas: '.$r['becas']);
    verificar('Queda terminado', $vencido->fresh()->estatus === ConvenioDescuento::TERMINADO);
    verificar('Con el motivo diciendo cuándo venció', str_contains((string) $vencido->fresh()->motivo_termino, '2025-12-31'));
    verificar(
        'Y ya no lo vuelve a tomar',
        ConvenioDescuento::query()->porVencer('2026-06-01')->where('id', $vencido->id)->count() === 0
    );

    echo PHP_EOL.'6. El tipo «manual» de descuento se retiró'.PHP_EOL;

    /*
     * La pantalla lo ofrecía y la validación lo aceptaba, pero
     * `CalculadorCargo` sólo lee `campana` y `pago_anticipado`: un descuento
     * «manual» no descontaba NADA.
     */
    verificar(
        'No queda ninguno en la base',
        Descuento::withTrashed()->where('tipo', 'manual')->count() === 0
    );
    verificar(
        'Y el catálogo ya no lo declara',
        ! defined(Descuento::class.'::TIPO_MANUAL'),
        'quedan pago anticipado y campaña, que son los que se leen'
    );

    echo PHP_EOL.'7. La pantalla'.PHP_EOL;

    $peticion = Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);

    $props = json_decode($control->index($peticion)->toResponse($peticion)->getContent(), true)['props'];
    $ids = collect($props['convenios'])->pluck('id');

    verificar('Trae los convenios', $ids->contains($convenio->id) && $ids->contains($vencido->id));

    $mio = collect($props['convenios'])->firstWhere('id', $convenio->id);

    verificar('Con sus términos', collect($mio['becas'])->contains('id', $beca->id));
    verificar('Su contraparte', $mio['contraparte'] === 'Industrias de Prueba, S.A. de C.V.');
    verificar(
        'Y su motivo de término',
        str_contains((string) $mio['motivo_termino'], 'no renovó'),
        (string) $mio['motivo_termino']
    );
    verificar(
        'Las becas libres no incluyen las que ya son de un convenio',
        ! collect($props['becasLibres'])->pluck('valor')->contains($beca->id)
    );
    verificar(
        'Pero sí las que no lo son',
        collect($props['becasLibres'])->pluck('valor')->contains($normal->id)
    );

    // Una beca ya atada no se le roba a otro convenio.
    $otro = ConvenioDescuento::create([
        'nombre' => 'Un tercer convenio', 'contraparte' => 'Sindicato de prueba',
        'vigente_desde' => '2026-01-01', 'vigente_hasta' => '2026-12-31',
    ]);

    $peticionAtar = Request::create('/', 'POST', ['beca_id' => $beca->id]);
    $peticionAtar->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticionAtar);

    $control->atarBeca($peticionAtar, $otro);

    verificar(
        'Una beca de otro convenio no se le roba',
        (int) $beca->fresh()->convenio_descuento_id === (int) $convenio->id,
        (string) $beca->fresh()->convenio_descuento_id
    );

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} catch (Throwable $e) {
    $fallos[] = 'la suite murió antes de terminar';
    echo '  FALLA la suite murió antes de terminar  ['.$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().']'.PHP_EOL;
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
