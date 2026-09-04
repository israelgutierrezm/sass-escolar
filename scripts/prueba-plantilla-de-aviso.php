<?php

/**
 * La UNIDAD de un aviso sale de la métrica, no de la prosa. Con rollback.
 *
 * Se corre con `php scripts/prueba-plantilla-de-aviso.php` desde la raíz.
 *
 * ── El defecto que cierra ─────────────────────────────────────────────────
 * `{valor}` y `{umbral}` son números cuyo significado depende de lo que la
 * regla mide: 15 puede ser 15 %, 15 días o 15 sesiones. La unidad la escribía
 * quien redactaba la plantilla —«va en {valor} %»— y NADA comprobaba que casara
 * con la métrica.
 *
 * Así que una regla que cuenta días de atraso con esa plantilla le decía al
 * alumno «llevas 15 % y se pide 15 %». No falla, no avisa: dice otra cosa. Y no
 * era un descuido de quien la escribiera — **el marcador de la propia pantalla
 * lo enseñaba así**, con el «%» dentro del ejemplo.
 *
 * Ahora la unidad la pone `CatalogoMetricas`, que es donde vive, y escribirla a
 * mano se rehúsa al guardar.
 */

use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Tenant;
use App\Permanencia\CatalogoMetricas;
use App\Services\Permanencia\PlantillaDeAviso;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

require __DIR__.'/apoyo-permanencia.php';

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

function codigoDe(Throwable $e): int
{
    return app(Illuminate\Contracts\Debug\ExceptionHandler::class)
        ->render(Illuminate\Http\Request::create('/'), $e)
        ->getStatusCode();
}

const PREFIJO = 'ZZPLAN-';

$db = DB::connection('tenant');

$db->beginTransaction();

try {
    $plantillas = app(PlantillaDeAviso::class);

    limpiarPermanencia(conReglas: false);

    $categoria = CategoriaSenal::query()->activas()->where('sensible', false)->firstOrFail();
    $matricula = App\Models\Admisiones\MatriculaOferta::query()->firstOrFail();

    /**
     * Una alerta de la métrica que se pida, con su valor y su umbral.
     *
     * El escenario se CONSTRUYE por métrica: lo que se prueba es que el mismo
     * texto salga distinto según lo que la regla mide, y eso no se puede
     * afirmar con una sola.
     */
    $alertaDe = function (string $metrica, float $valor, float $umbral) use ($categoria, $matricula) {
        $regla = ReglaAlerta::create([
            'nombre' => PREFIJO.$metrica,
            'categoria_id' => $categoria->id,
            'proveedor' => CatalogoMetricas::de($metrica)['proveedor'],
            'activa' => false,
        ]);

        $version = $regla->versiones()->create([
            'version' => 1,
            'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
            'metrica' => $metrica,
            'comparador' => CatalogoMetricas::comparadorSugerido($metrica),
            'umbral' => $umbral,
            'ventana_tipo' => 'ciclo',
            'cobertura_minima' => 1,
            'severidad' => 'medio',
            'peso' => 2,
            'frecuencia' => 'diaria',
            'cooldown_dias' => 14,
            'avisa_al_alumno' => true,
            'plantilla_aviso' => 'Vas en {valor} y se pide {umbral}.',
        ]);

        return Alerta::create([
            'matricula_oferta_id' => $matricula->id,
            'regla_id' => $regla->id,
            'regla_version_id' => $version->id,
            'categoria_id' => $categoria->id,
            'severidad' => 'medio',
            'estado_senal' => Alerta::ACTIVA,
            'estado_triage' => Alerta::VALIDADA,
            'valor_observado' => $valor,
            'umbral' => $umbral,
            'cobertura' => 10,
            'evidencia' => [],
            'primera_vez_en' => now(),
            'ultima_evaluacion_en' => now(),
        ]);
    };

    echo '1. El MISMO texto dice cosas distintas según lo que la regla mide'.PHP_EOL;

    /*
     * Es la comprobación central. Con la unidad en la prosa, estas dos frases
     * habrían salido idénticas —«Vas en 15 y se pide 15»— o, peor, con la
     * unidad de la otra si alguien copió la plantilla.
     */
    $porcentaje = $alertaDe('asistencia.porcentaje', 63, 80);
    $atraso = $alertaDe('finanzas.dias_de_atraso', 15, 15);

    $textoPorcentaje = $plantillas->rellenar('Vas en {valor} y se pide {umbral}.',
        $porcentaje->fresh(['version', 'matricula.persona', 'regla']));

    $textoAtraso = $plantillas->rellenar('Vas en {valor} y se pide {umbral}.',
        $atraso->fresh(['version', 'matricula.persona', 'regla']));

    verificar('La de porcentaje sale en %',
        $textoPorcentaje === 'Vas en 63 % y se pide 80 %.', $textoPorcentaje);

    verificar('Y la de atraso sale en días, con el MISMO texto de plantilla',
        $textoAtraso === 'Vas en 15 días y se pide 15 días.', $textoAtraso);

    echo PHP_EOL.'2. La unidad concuerda en número'.PHP_EOL;

    /*
     * «1 días» delata una frase armada a pedazos, y esto lo lee un alumno sobre
     * sí mismo: una plantilla mal cuadrada le quita autoridad a lo que dice.
     */
    $unDia = $alertaDe('expediente.dias_para_vencer', 1, 30);

    $texto = $plantillas->rellenar('Te queda {valor}.',
        $unDia->fresh(['version', 'matricula.persona', 'regla']));

    verificar('En singular dice «1 día», no «1 días»',
        $texto === 'Te queda 1 día.', $texto);

    $unaSesion = $alertaDe('asistencia.faltas_consecutivas', 1, 3);

    $texto = $plantillas->rellenar('Llevas {valor} seguida.',
        $unaSesion->fresh(['version', 'matricula.persona', 'regla']));

    verificar('Y «1 sesión», no «1 sesiones»',
        $texto === 'Llevas 1 sesión seguida.', $texto);

    echo PHP_EOL.'3. Y hay una unidad que NO se pega detrás'.PHP_EOL;

    /*
     * `calificación` está en el catálogo para explicar qué es el número, no
     * para escribirse detrás: «tu promedio va en 7.93» se lee bien y
     * «7.93 calificación» no se dice.
     */
    $promedio = $alertaDe('academico.promedio', 7.93, 8);

    $texto = $plantillas->rellenar('Tu promedio va en {valor}.',
        $promedio->fresh(['version', 'matricula.persona', 'regla']));

    verificar('El promedio sale desnudo, sin la palabra detrás',
        $texto === 'Tu promedio va en 7.93.', $texto);

    verificar('Y conserva sus decimales, sin rellenar con ceros',
        str_contains($texto, '7.93') && ! str_contains($texto, '7.930'));

    echo PHP_EOL.'4. Sin el dato, se dice con palabras'.PHP_EOL;

    /*
     * El aviso a la ESCUELA va a un ROL —o sea a varias personas, y algunas sin
     * el permiso de la categoría—, así que nunca lleva el número. Lo que no
     * puede es dejar un hueco: la frase tiene que seguir leyéndose.
     */
    $texto = $plantillas->rellenar('Vas en {valor} y se pide {umbral}.',
        $porcentaje->fresh(['version', 'matricula.persona', 'regla']), conElDato: false);

    verificar('Sin el dato no sale el número ni una unidad suelta',
        ! str_contains($texto, '63') && ! str_contains($texto, '%')
        && str_contains($texto, 'el valor registrado'), $texto);

    echo PHP_EOL.'5. Escribir la unidad a mano se REHÚSA'.PHP_EOL;

    /*
     * Y se rehúsa al GUARDAR, con quien la redacta delante. Descubrirlo en el
     * aviso de un alumno es descubrirlo tarde.
     */
    foreach ([
        'Tu asistencia va en {valor} % y se pide {umbral} %.' => '%',
        'Llevas {valor} días de atraso.' => 'días',
        'Tienes {valor}sesiones seguidas.' => 'sesiones',
    ] as $mala => $esperada) {
        verificar('Se caza «'.$esperada.'» detrás de la marca',
            $plantillas->unidadDeMas($mala) === $esperada,
            (string) $plantillas->unidadDeMas($mala));
    }

    verificar('Y la que NO la escribe pasa',
        $plantillas->unidadDeMas('Vas en {valor} y se pide {umbral}.') === null);

    /*
     * Un texto que menciona una unidad LEJOS de la marca no se rehúsa: «se pide
     * el 80 % de asistencia» es una frase legítima y rechazarla obligaría a
     * escribir avisos peores para contentar a la validación.
     */
    verificar('Y una unidad lejos de la marca tampoco estorba',
        $plantillas->unidadDeMas('Se pide el 80 % de asistencia y vas en {valor}.') === null);

    echo PHP_EOL.'6. El CONTROLADOR la rehúsa de verdad'.PHP_EOL;

    $global = App\Models\Identidad\Usuario::query()->where('usuario', 'demo')->firstOrFail();
    auth()->login($global);

    $regla = ReglaAlerta::query()->where('nombre', PREFIJO.'asistencia.porcentaje')->firstOrFail();

    $peticion = Illuminate\Http\Request::create('/', 'POST', [
        'vigente_desde' => CarbonImmutable::now()->addDay()->toDateString(),
        'metrica' => 'asistencia.porcentaje',
        'comparador' => '<',
        'umbral_fuente' => 'fijo',
        'umbral' => 80,
        'ventana_tipo' => 'ciclo',
        'cobertura_minima' => 1,
        'severidad' => 'medio',
        'peso' => 2,
        'frecuencia' => 'diaria',
        'cooldown_dias' => 14,
        'avisa_al_alumno' => true,
        'plantilla_aviso' => 'Tu asistencia va en {valor} % y se pide {umbral} %.',
    ]);

    $peticion->setUserResolver(fn () => $global);

    $rechazo = null;

    try {
        app(App\Http\Controllers\Permanencia\ReglaAlertaController::class)
            ->versionar($peticion, $regla);
    } catch (Throwable $e) {
        $rechazo = $e;
    }

    verificar('Guardar una plantilla con la unidad escrita se rehúsa con 422',
        $rechazo !== null && codigoDe($rechazo) === 422,
        $rechazo === null ? 'pasó' : (string) codigoDe($rechazo));

    /*
     * Y el mensaje dice QUÉ quitar y POR QUÉ. Un «no se puede» pelado manda a
     * quien redacta a adivinar, y lo que va a hacer es borrar la marca entera.
     */
    verificar('Y el mensaje nombra la unidad y dice que la pone el sistema',
        $rechazo !== null && str_contains($rechazo->getMessage(), '%')
        && str_contains($rechazo->getMessage(), 'la pone el sistema'),
        $rechazo === null ? '—' : mb_substr($rechazo->getMessage(), 0, 70));

    echo PHP_EOL.'7. Una unidad que el vocabulario no conozca REVIENTA'.PHP_EOL;

    /*
     * Es la guarda ruidosa: una métrica nueva con una unidad que esta tabla no
     * sabe poner en singular tiene que detenerse, no salir en el aviso con la
     * palabra en plural pegada a un 1.
     */
    $sufijo = (new ReflectionClass($plantillas))->getMethod('sufijoDe');
    $sufijo->setAccessible(true);

    $revento = false;

    try {
        $sufijo->invoke($plantillas, 'kilogramos', 3);
    } catch (Throwable) {
        $revento = true;
    }

    verificar('Una unidad desconocida detiene el aviso en vez de escribirlo mal', $revento);

    /*
     * Y las que el catálogo declara HOY tienen que estar todas: si una faltara,
     * el aviso de esa regla reventaría en producción y no aquí.
     */
    $sinVocabulario = [];

    foreach (CatalogoMetricas::todas() as $clave => $metrica) {
        try {
            $sufijo->invoke($plantillas, $metrica['unidad'], 2);
        } catch (Throwable) {
            $sinVocabulario[] = $clave.' ('.$metrica['unidad'].')';
        }
    }

    verificar('Y TODAS las unidades del catálogo están en el vocabulario',
        $sinVocabulario === [], implode(', ', $sinVocabulario));

    echo PHP_EOL.'8. La pantalla ya no enseña a escribirla'.PHP_EOL;

    /*
     * Ahí nacía el defecto: el marcador del formulario decía
     * «va en {valor} % y se pide {umbral} %», así que quien lo copiaba a una
     * regla de días obtenía «llevas 15 %». La validación sola no bastaba:
     * habría rechazado el ejemplo que la propia pantalla ofrece.
     */
    $vue = (string) file_get_contents(__DIR__.'/../resources/js/Pages/Permanencia/Reglas/Index.vue');

    /*
     * Se mira el MARCADOR y no el archivo entero: el comentario que explica por
     * qué se quitó la unidad cita el ejemplo malo, así que barriendo todo el
     * componente la comprobación se cazaría a sí misma. Es la tercera vez hoy
     * que una comprobación mide la prosa del autor en vez del código.
     */
    preg_match('/marcador="([^"]*)"/', $vue, $encontrado);

    verificar('El formulario tiene su marcador de ejemplo',
        ($encontrado[1] ?? '') !== '', $encontrado[1] ?? '—');

    verificar('Y ese ejemplo ya NO lleva la unidad escrita',
        $plantillas->unidadDeMas($encontrado[1] ?? '') === null,
        $encontrado[1] ?? '—');

    verificar('Y se dice con todas sus letras que no hay que escribirla',
        str_contains($vue, 'No escribas la unidad'));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
