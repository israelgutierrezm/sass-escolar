<?php

/**
 * El SIMULADOR de reglas de permanencia (previsualizar sin encender). Con rollback.
 *
 * Se corre con `php scripts/prueba-simulador-reglas.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **NO ESCRIBE NADA.** Ni una alerta, ni una corrida. Es una
 *     previsualización, como `GeneradorMatricula::previsualizar` no consume folio.
 *  2. **El umbral DRIVE el conteo.** Un umbral imposible no marca a nadie; uno
 *     amplio marca a todos los que tienen dato.
 *  3. **La cobertura mínima manda a SIN DATOS.** Subida al máximo, nada dispara.
 *  4. **Concuerda con el motor.** El conteo del simulador es el mismo que
 *     recorriendo la población y preguntando a `veredictoDe` —la misma decisión
 *     que el motor de madrugada, sin el enfriamiento que es historia—.
 *  5. **Se acota por campus** de quien simula.
 *  6. **Una categoría SENSIBLE da el conteo pero NO los nombres.**
 */

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\CorridaEvaluacion;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\Tenant;
use App\Permanencia\RegistroProveedores;
use App\Services\Permanencia\MotorDeEvaluacion;
use App\Services\Permanencia\SimuladorDeReglas;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$db = DB::connection('tenant');

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;

    $verificaciones++;
    $ok || $fallidas++;

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

const PREF = 'ZZSIM-';

$db->beginTransaction();

try {
    $simulador = app(SimuladorDeReglas::class);
    $motor = app(MotorDeEvaluacion::class);
    $proveedores = app(RegistroProveedores::class);

    $noSensible = CategoriaSenal::query()->where('sensible', false)->firstOrFail();
    $sensible = CategoriaSenal::query()->where('sensible', true)->first();

    $regla = ReglaAlerta::create([
        'nombre' => PREF.'Promedio bajo',
        'categoria_id' => $noSensible->id,
        'proveedor' => 'academico',
        'activa' => false,
    ]);
    $regla->setRelation('categoria', $noSensible);

    /** Arma una versión candidata en memoria y simula. */
    $simular = function (float $umbral, string $comparador = '<', int $cobertura = 0, ?array $campus = null) use ($simulador, $regla) {
        $version = new ReglaAlertaVersion([
            'metrica' => 'academico.promedio',
            'comparador' => $comparador,
            'umbral' => $umbral,
            'umbral_fuente' => ReglaAlertaVersion::FUENTE_FIJA,
            'ventana_tipo' => 'ciclo',
            'cobertura_minima' => $cobertura,
        ]);

        return $simulador->simular($regla, $version, $campus);
    };

    // ── 1. No escribe nada ──────────────────────────────────────────────────
    echo PHP_EOL.'1. El simulador no escribe nada'.PHP_EOL;

    $alertasAntes = Alerta::query()->count();
    $corridasAntes = CorridaEvaluacion::query()->count();

    $amplio = $simular(100);
    $simular(-1);
    $simular(100, '<', 99999);

    verificar('No creó ninguna alerta', Alerta::query()->count() === $alertasAntes, (string) Alerta::query()->count());
    verificar('No creó ninguna corrida', CorridaEvaluacion::query()->count() === $corridasAntes);

    // ── 2. El umbral manda el conteo ────────────────────────────────────────
    echo PHP_EOL.'2. El umbral candidato cambia a quién marca'.PHP_EOL;

    $cero = $simular(-1);
    verificar('Con un umbral imposible (promedio < -1), nadie dispara', $cero['dispara'] === 0, (string) $cero['dispara']);
    verificar('Con un umbral amplio (promedio < 100), dispara alguien', $amplio['dispara'] > 0, (string) $amplio['dispara']);
    verificar('El amplio marca MÁS que el imposible', $amplio['dispara'] > $cero['dispara']);
    verificar('dispara + no_dispara + sin_datos = mediciones',
        $amplio['dispara'] + $amplio['no_dispara'] + $amplio['sin_datos'] === $amplio['mediciones']);
    verificar('Cuenta alumnos_marcados distintos', $amplio['alumnos_marcados'] > 0 && $amplio['alumnos_marcados'] <= $amplio['dispara']);

    // ── 3. La cobertura mínima manda a SIN DATOS ────────────────────────────
    echo PHP_EOL.'3. Cobertura mínima altísima ⇒ todo sin datos'.PHP_EOL;

    $sinDatos = $simular(100, '<', 99999);
    verificar('Nada dispara y nada no-dispara', $sinDatos['dispara'] === 0 && $sinDatos['no_dispara'] === 0);
    verificar('Todo cae en sin_datos', $sinDatos['sin_datos'] === $sinDatos['mediciones'] && $sinDatos['mediciones'] > 0);
    verificar('sin_datos_pct = 100', $sinDatos['sin_datos_pct'] === 100);

    // ── 4. Concuerda con el motor (recorrido directo) ───────────────────────
    echo PHP_EOL.'4. El conteo concuerda con veredictoDe recorriendo a mano'.PHP_EOL;

    $version = new ReglaAlertaVersion([
        'metrica' => 'academico.promedio', 'comparador' => '<', 'umbral' => 100,
        'umbral_fuente' => ReglaAlertaVersion::FUENTE_FIJA, 'ventana_tipo' => 'ciclo', 'cobertura_minima' => 0,
    ]);
    $prov = $proveedores->de('academico');
    $mano = [MotorDeEvaluacion::DISPARA => 0, MotorDeEvaluacion::NO_DISPARA => 0, MotorDeEvaluacion::SIN_DATOS => 0];

    MatriculaOferta::query()->whereHas('oferta')
        ->with(['oferta:id,campus_id,programa_academico_id,plan_id,modalidad', 'oferta.programaAcademico:id,nivel_estudios_id'])
        ->orderBy('id')->chunkById(200, function ($lote) use (&$mano, $regla, $version, $prov, $motor) {
            foreach ($lote as $m) {
                if (! $regla->alcanzaA($m)) {
                    continue;
                }
                $umbral = $motor->umbralDe($version, $m);
                foreach ($prov->medir($m, $version->metrica, $version) as $medicion) {
                    $mano[$motor->veredictoDe($version, $medicion, $umbral)]++;
                }
            }
        });

    verificar('dispara coincide', $mano[MotorDeEvaluacion::DISPARA] === $amplio['dispara'], "mano={$mano[MotorDeEvaluacion::DISPARA]} sim={$amplio['dispara']}");
    verificar('no_dispara coincide', $mano[MotorDeEvaluacion::NO_DISPARA] === $amplio['no_dispara']);
    verificar('sin_datos coincide', $mano[MotorDeEvaluacion::SIN_DATOS] === $amplio['sin_datos']);

    // ── 5. Se acota por campus ──────────────────────────────────────────────
    echo PHP_EOL.'5. El alcance por campus recorta la población'.PHP_EOL;

    $unCampus = MatriculaOferta::query()->whereHas('oferta')->with('oferta:id,campus_id')->get()
        ->pluck('oferta.campus_id')->filter()->unique()->values();

    if ($unCampus->count() >= 1) {
        $acotado = $simular(100, '<', 0, [$unCampus->first()]);
        verificar('Acotado a un campus, mide MENOS o IGUAL que global',
            $acotado['mediciones'] <= $amplio['mediciones']);
        verificar('Un campus inexistente no mide nada',
            $simular(100, '<', 0, [-999])['mediciones'] === 0);
    } else {
        verificar('Hay al menos un campus con matrículas', false, 'ninguno');
    }

    // ── 6. Categoría sensible: conteo sí, nombres no ────────────────────────
    echo PHP_EOL.'6. Una categoría sensible da el conteo pero no los nombres'.PHP_EOL;

    verificar('La categoría NO sensible sí trae muestra', count($amplio['muestra']) > 0);
    verificar('Y cada renglón trae alumno y valor',
        isset($amplio['muestra'][0]['alumno'], $amplio['muestra'][0]['valor']));

    if ($sensible !== null) {
        $regla->categoria_id = $sensible->id;
        $regla->setRelation('categoria', $sensible);
        $sens = $simular(100);
        verificar('Sensible: sigue disparando (conteo presente)', $sens['dispara'] > 0);
        verificar('Sensible: la muestra viene VACÍA', $sens['muestra'] === [] && $sens['sensible'] === true);
    } else {
        verificar('(No hay categoría sensible en el demo para esta comprobación)', true);
    }

    // ── 7. El controlador devuelve el flash y no escribe ────────────────────
    echo PHP_EOL.'7. El controlador: flash con la simulación, sin escribir'.PHP_EOL;

    $regla->categoria_id = $noSensible->id;
    $regla->save();

    $control = app(App\Http\Controllers\Permanencia\ReglaAlertaController::class);
    $peticion = Illuminate\Http\Request::create('/', 'POST', [
        'metrica' => 'academico.promedio', 'comparador' => '<', 'umbral' => 100,
        'umbral_fuente' => 'fijo', 'ventana_tipo' => 'ciclo', 'cobertura_minima' => 0,
    ]);
    $peticion->setLaravelSession(app('session')->driver());

    $alertasAntes2 = Alerta::query()->count();
    $respuesta = $control->simular($peticion, $regla->fresh(), $simulador);
    $flash = $peticion->session()->get('simulacion');

    verificar('Responde con una redirección', $respuesta instanceof Illuminate\Http\RedirectResponse);
    verificar('El flash trae la simulación de ESTA regla',
        is_array($flash) && ($flash['regla_id'] ?? null) === $regla->id && ($flash['dispara'] ?? -1) >= 0);
    verificar('El controlador tampoco escribió una alerta', Alerta::query()->count() === $alertasAntes2);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
