<?php

/**
 * El desenlace del aspirante se DERIVA: `situaciones_aspirante` se retiró.
 *
 * Se corre con `php scripts/prueba-desenlace-aspirante.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. INSCRITO sale de tener matrícula PARA SU OFERTA DE INTERÉS, no de «tiene
 *     alguna matrícula»: quien ya estudia un programa académico y se postula a otra sigue
 *     siendo un prospecto abierto para esa segunda.
 *  2. DESCARTADO lleva fecha y motivo. El motivo es obligatorio: es lo que se
 *     pregunta meses después, y una fila de catálogo no podía darlo.
 *  3. A quien ya se inscribió NO se le descarta, ni desde la pantalla ni desde
 *     un seguimiento que cierra el embudo.
 *  4. Los tres scopes PARTEN el padrón: cada aspirante cae en uno y sólo uno.
 *  5. `resultados_seguimiento.cierra_el_embudo` descarta de verdad. Hasta ahora
 *     sólo se dibujaba.
 *  6. Reactivar deshace el descarte, y se lleva el motivo con él.
 */

use App\Http\Controllers\AspiranteController;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Captacion\ResultadoSeguimiento;
use App\Models\Captacion\TipoSeguimiento;
use App\Models\Tenant;
use App\Services\AgendaDelAspirante;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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

function peticion(array $datos = []): Request
{
    $peticion = Request::create('/aspirantes', 'POST', $datos);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

try {
    echo '0. El catálogo ya no existe'.PHP_EOL;

    verificar('La tabla `situaciones_aspirante` se fue', ! Schema::hasTable('situaciones_aspirante'));
    verificar('Y la columna `aspirantes.situacion_id` también',
        ! Schema::hasColumn('aspirantes', 'situacion_id'));
    verificar('En su lugar están `descartado_en` y `motivo_descarte`',
        Schema::hasColumn('aspirantes', 'descartado_en') && Schema::hasColumn('aspirantes', 'motivo_descarte'));

    /*
     * El escenario se CONSTRUYE: en el demo no hay ningún descartado ni ningún
     * aspirante que ya se haya inscrito, y una prueba que se salta la
     * comprobación cuando no encuentra el caso es una prueba que se apaga sola
     * el día que cambian los datos.
     */
    $ofertas = Oferta::query()->take(2)->pluck('id')->all();

    if (count($ofertas) < 2) {
        echo 'Esta escuela no tiene dos ofertas; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    [$ofertaA, $ofertaB] = $ofertas;

    // Con matrícula de VERDAD: se toma una existente en vez de inventarle una
    // fila a mano, que es como se acaba probando contra un dato imposible.
    $conMatricula = MatriculaOferta::query()->whereNotNull('persona_id')->firstOrFail();
    $otraOferta = $conMatricula->oferta_id === $ofertaA ? $ofertaB : $ofertaA;

    $control = app(AspiranteController::class);

    echo PHP_EOL.'1. Inscrito se deriva de la matrícula DE SU OFERTA'.PHP_EOL;

    $mismoPrograma = Aspirante::create([
        'persona_id' => $conMatricula->persona_id,
        'oferta_interes_id' => $conMatricula->oferta_id,
    ]);

    verificar('Con matrícula de la oferta que pidió, sale inscrito',
        $mismoPrograma->desenlace() === 'inscrito', $mismoPrograma->desenlace());

    $otroPrograma = Aspirante::create([
        'persona_id' => $conMatricula->persona_id,
        'oferta_interes_id' => $otraOferta,
    ]);

    verificar('La MISMA persona pidiendo otra oferta sigue abierta',
        $otroPrograma->desenlace() === 'abierto', $otroPrograma->desenlace());

    echo PHP_EOL.'2. Descartar exige motivo, y guarda fecha'.PHP_EOL;

    $rechazoSinMotivo = false;

    try {
        $control->descartar(peticion([]), $otroPrograma);
    } catch (ValidationException $e) {
        $rechazoSinMotivo = $e->validator->errors()->has('motivo_descarte');
    }

    verificar('Sin motivo, la validación lo detiene', $rechazoSinMotivo);

    $control->descartar(peticion(['motivo_descarte' => 'Se fue a otra escuela']), $otroPrograma);
    $otroPrograma->refresh();

    verificar('Con motivo, queda descartado', $otroPrograma->desenlace() === 'descartado', $otroPrograma->desenlace());
    verificar('Con su fecha', $otroPrograma->descartado_en !== null);
    verificar('Y con su motivo tal cual se escribió',
        $otroPrograma->motivo_descarte === 'Se fue a otra escuela', (string) $otroPrograma->motivo_descarte);

    echo PHP_EOL.'3. Al inscrito no se le descarta'.PHP_EOL;

    verificar('`motivoParaNoDescartar` lo dice por su nombre',
        str_contains((string) $mismoPrograma->motivoParaNoDescartar(), 'inscrito'),
        (string) $mismoPrograma->motivoParaNoDescartar());

    $control->descartar(peticion(['motivo_descarte' => 'A ver si cuela']), $mismoPrograma);
    $mismoPrograma->refresh();

    verificar('Y el intento NO lo descarta', $mismoPrograma->descartado_en === null);
    verificar('Sigue contando como inscrito', $mismoPrograma->desenlace() === 'inscrito');

    echo PHP_EOL.'4. Los tres scopes PARTEN el padrón'.PHP_EOL;

    $total = Aspirante::query()->count();
    $abiertos = Aspirante::query()->abiertos()->count();
    $descartados = Aspirante::query()->descartados()->count();
    $inscritos = Aspirante::query()->inscritos()->count();

    verificar('Cada aspirante cae en uno y sólo uno',
        $abiertos + $descartados + $inscritos === $total,
        "{$abiertos} + {$descartados} + {$inscritos} vs {$total}");

    verificar('El descartado está en `descartados`',
        Aspirante::query()->descartados()->whereKey($otroPrograma->id)->exists());
    verificar('Y NO en `abiertos`',
        ! Aspirante::query()->abiertos()->whereKey($otroPrograma->id)->exists());
    verificar('El inscrito está en `inscritos` sin que se le tocara una sola columna',
        Aspirante::query()->inscritos()->whereKey($mismoPrograma->id)->exists());

    echo PHP_EOL.'5. Reactivar'.PHP_EOL;

    $control->reactivar($otroPrograma);
    $otroPrograma->refresh();

    verificar('Vuelve a estar abierto', $otroPrograma->desenlace() === 'abierto');
    verificar('Y se lleva el motivo: ya no dice por qué se perdió lo que no se perdió',
        $otroPrograma->motivo_descarte === null, (string) $otroPrograma->motivo_descarte);

    echo PHP_EOL.'6. Un seguimiento que CIERRA EL EMBUDO descarta de verdad'.PHP_EOL;

    $cierra = ResultadoSeguimiento::query()->where('cierra_el_embudo', true)->first();
    $noCierra = ResultadoSeguimiento::query()->where('cierra_el_embudo', false)->first();
    $tipo = TipoSeguimiento::query()->first();

    if ($cierra === null || $noCierra === null || $tipo === null) {
        verificar('Hay catálogo de resultados con las dos banderas', false, 'faltan filas');
    } else {
        $agenda = app(AgendaDelAspirante::class);

        $registrar = function (Aspirante $quien, ResultadoSeguimiento $resultado) use ($agenda, $tipo): void {
            $actividad = $agenda->agendar($quien, [
                'tipo_id' => $tipo->id,
                'nota' => 'De prueba',
                'programado_para' => now()->toDateString(),
                'responsable_id' => null,
            ], null);

            $agenda->cerrar($actividad, [
                'resultado_id' => $resultado->id,
                'respuesta' => 'De prueba',
            ], null);
        };

        $registrar($otroPrograma, $noCierra);
        $otroPrograma->refresh();

        verificar('Un resultado que NO cierra deja abierto al prospecto',
            $otroPrograma->desenlace() === 'abierto', $otroPrograma->desenlace());

        $registrar($otroPrograma, $cierra);
        $otroPrograma->refresh();

        verificar('Uno que SÍ cierra lo descarta',
            $otroPrograma->desenlace() === 'descartado', $otroPrograma->desenlace());
        verificar('Con el nombre del resultado como motivo: es la razón que ya se eligió',
            $otroPrograma->motivo_descarte === $cierra->nombre, (string) $otroPrograma->motivo_descarte);

        // Y al inscrito no lo toca ni por esta puerta.
        $registrar($mismoPrograma, $cierra);
        $mismoPrograma->refresh();

        verificar('Al inscrito no lo descarta ni un seguimiento que cierra el embudo',
            $mismoPrograma->descartado_en === null && $mismoPrograma->desenlace() === 'inscrito');
    }
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
