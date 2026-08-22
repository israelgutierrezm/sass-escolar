<?php

/**
 * El acta de calificaciones impresa. Con rollback.
 *
 * Se corre con `php scripts/prueba-acta-impresa.php` desde la raíz.
 *
 * ── Lo que de verdad hay que vigilar aquí ─────────────────────────────────
 * Que un acta CORREGIDA siga imprimiendo sus alumnos. Al cerrar una corrección,
 * los renglones de historial académico de la original se dan de baja LÓGICA para que dejen
 * de contar, y las dos actas se conservan como documento. Sin `withTrashed()`,
 * imprimir la original daría un papel con folio, firma y CERO alumnos: se ve
 * perfecto y está vacío, que es la peor manera de fallar.
 *
 * Y lo segundo: que ese papel diga que ya no tiene efecto. Sin el aviso, las dos
 * actas se ven igual de válidas y quien tenga la vieja en la mano no tiene cómo
 * saber que las calificaciones que lee ya no son las que cuentan.
 *
 * El acta no se fabrica a mano: se cierra con `AsentadorActa`, que es lo que
 * corre de verdad. Una fila de `actas` insertada a pelo probaría una forma que
 * el sistema nunca produce.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Actas\ActaImprimible;
use App\Http\Controllers\ImpresionActaController;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Historial;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\AsentadorActa;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

/** Invoca el controlador y devuelve el HTML, o el código HTTP si abortó. */
function pedirActa(Usuario $usuario, AsignaturaGrupo $materia, Acta $acta): string|int
{
    $peticion = Request::create("/captura/{$materia->id}/actas/{$acta->id}/imprimir", 'GET');
    $peticion->setUserResolver(fn () => $usuario);

    try {
        return app(ImpresionActaController::class)($peticion, $materia, $acta)->render();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }
}

$db->beginTransaction();

try {
    $asentador = app(AsentadorActa::class);

    // ── Una materia del ciclo con alumnos y esquema completo ──
    $materia = AsignaturaGrupo::query()
        ->whereHas('inscripciones')
        ->with('planMateria')
        ->get()
        ->first(function (AsignaturaGrupo $m) use ($asentador) {
            $esquema = $asentador->esquema($m);

            return $esquema->isNotEmpty()
                && abs((float) $esquema->sum(fn ($c) => (float) $c->porcentaje) - 100.0) < 0.01
                && $asentador->inscripcionesCalificables($m)->isNotEmpty();
        });

    if ($materia === null) {
        echo 'Esta escuela no tiene ninguna materia con esquema al 100 % y alumnos; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    // Se rellena la captura que falte: cerrar exige que esté completa, y ésta
    // es la única forma de llegar a un acta REAL sobre la que probar.
    $esquema = $asentador->esquema($materia);

    foreach ($asentador->inscripcionesCalificables($materia) as $inscripcion) {
        foreach ($esquema as $componente) {
            CalificacionComponente::actualizarOReviver(
                ['inscripcion_id' => $inscripcion->id, 'esquema_evaluacion_id' => $componente->id],
                ['calificacion' => 9, 'capturado_en' => now()],
            );
        }
    }

    $firmante = (int) Usuario::query()->whereNotNull('persona_id')->value('persona_id');
    $original = $asentador->cerrar($asentador->actaDeTrabajo($materia), $firmante);

    echo PHP_EOL.'1. El acta firmada se imprime'.PHP_EOL;

    $datos = app(ActaImprimible::class)->armar($original);
    $alumnos = $asentador->inscripcionesCalificables($materia)->count();

    verificar('trae un renglón por alumno', $datos['renglones']->count() === $alumnos,
        $datos['renglones']->count().' de '.$alumnos);
    verificar('el resumen cuadra con los renglones',
        $datos['resumen']['total'] === $datos['renglones']->count()
        && $datos['resumen']['aprobados'] + $datos['resumen']['reprobados'] === $datos['resumen']['total'],
        json_encode($datos['resumen']));
    verificar('todavía no está sustituida', $datos['sustituida'] === null);

    $html = view('impresion.acta', $datos)->render();

    verificar('el HTML lleva el folio real', str_contains($html, (string) $original->folio), (string) $original->folio);
    verificar('y NO un folio de borrador', ! str_contains($html, 'BORRADOR-'));
    verificar('nombra a los alumnos',
        str_contains($html, (string) $datos['renglones']->first()['nombre']));

    echo PHP_EOL.'2. Un acta en borrador no se imprime'.PHP_EOL;

    $usuario = Usuario::query()->whereNotNull('persona_id')->where('usuario', 'demo')->firstOrFail();
    $borrador = $asentador->abrirCorreccion($original, 'Prueba de impresión');

    verificar('responde 404, no un papel con folio inventado',
        pedirActa($usuario, $materia, $borrador) === 404);

    echo PHP_EOL.'3. El acta de OTRA materia no se cuela por la ruta'.PHP_EOL;

    $otra = AsignaturaGrupo::query()->whereKeyNot($materia->id)->firstOrFail();

    verificar('responde 404 aunque el acta exista',
        pedirActa($usuario, $otra, $original) === 404);

    echo PHP_EOL.'4. Corregida, la original SIGUE imprimiendo sus alumnos'.PHP_EOL;

    $correccion = $asentador->cerrar($borrador, $firmante);

    verificar('sus renglones de historial quedaron dados de baja',
        Historial::query()->where('acta_id', $original->id)->count() === 0
        && Historial::withTrashed()->where('acta_id', $original->id)->count() === $alumnos);

    $datosOriginal = app(ActaImprimible::class)->armar($original->refresh());

    verificar('y aun así el acta impresa los trae',
        $datosOriginal['renglones']->count() === $alumnos,
        $datosOriginal['renglones']->count().' de '.$alumnos);

    echo PHP_EOL.'5. Y el papel avisa de que ya no tiene efecto'.PHP_EOL;

    verificar('sabe qué acta la sustituyó',
        $datosOriginal['sustituida']?->id === $correccion->id);

    $htmlOriginal = view('impresion.acta', $datosOriginal)->render();

    verificar('lo dice en el documento', str_contains($htmlOriginal, 'Acta sin efecto'));
    verificar('y nombra el folio de la que manda', str_contains($htmlOriginal, (string) $correccion->folio));

    $htmlCorreccion = view('impresion.acta', app(ActaImprimible::class)->armar($correccion))->render();

    verificar('la corrección dice a cuál sustituye',
        str_contains($htmlCorreccion, 'Acta de corrección')
        && str_contains($htmlCorreccion, (string) $original->folio));
    verificar('y esa NO lleva el aviso de sin efecto',
        ! str_contains($htmlCorreccion, 'Acta sin efecto'));
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
