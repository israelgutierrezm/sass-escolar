<?php

/**
 * Módulo 11 · Bolsa de trabajo — colocaciones e indicador de empleabilidad.
 * Con rollback.
 *
 * Se corre con `php scripts/prueba-bolsa-colocaciones.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. La etapa que declara la contratación y la colocación son EL MISMO HECHO:
 *     no se puede mover a esa etapa sin registrar la colocación. Si se pudiera,
 *     la pantalla diría «contratado» y el indicador contaría cero, y nadie se
 *     enteraría hasta que la acreditadora pidiera el número.
 *  2. Una postulación produce UNA colocación. Dos serían el mismo hecho contado
 *     dos veces y el porcentaje saldría inflado.
 *  3. Deshacer devuelve la postulación a la etapa de la que venía —leída de la
 *     bitácora, no adivinada— y deja volver a colocarla.
 *  4. Una colocación puede NO venir de una postulación: es el seguimiento de
 *     egresados, y sin él el indicador mediría el trabajo de vinculación en vez
 *     del destino de los egresados.
 *  5. La matrícula de una colocación directa es DE esa persona: con la de otro,
 *     el porcentaje de una carrera ajena subiría sin que nada fallara.
 *  6. El denominador sale del CATÁLOGO (`cuenta_como_egresado`), no de una lista
 *     de claves: apagar una situación tiene que mover el número.
 *  7. Se cuenta por MATRÍCULA y con DISTINCT: quien cambió de trabajo dos veces
 *     sigue siendo un egresado colocado, no dos, y el porcentaje no puede pasar
 *     del 100 %.
 *  8. Las colocaciones sin carrera señalada NO entran en ningún renglón por
 *     programa: se reportan aparte.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\Bolsa\ColocacionController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Bolsa\Colocacion;
use App\Models\Bolsa\Empresa;
use App\Models\Bolsa\EtapaPostulacion;
use App\Models\Bolsa\PostulacionBitacora;
use App\Models\Bolsa\SituacionEmpresa;
use App\Models\Bolsa\SituacionVacante;
use App\Models\Bolsa\Vacante;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Bolsa\IndicadorEmpleabilidad;
use App\Services\Bolsa\Postulador;
use App\Services\Bolsa\RegistradorColocacion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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

function peticionDe(Usuario $usuario, string $metodo = 'POST', array $datos = []): Request
{
    $peticion = Request::create('/prueba', $metodo, $datos);
    $peticion->setUserResolver(fn () => $usuario);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

try {
    $registrador = app(RegistradorColocacion::class);
    $postulador = app(Postulador::class);
    $indicador = app(IndicadorEmpleabilidad::class);
    $control = app(ColocacionController::class);

    $staff = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    $quien = (int) $staff->persona_id;

    echo PHP_EOL.'0. El catálogo dice qué etapa coloca y qué situación es egreso'.PHP_EOL;

    $queColoca = EtapaPostulacion::query()->activos()->queColocan()->first();
    $finales = EtapaPostulacion::query()->finales()->pluck('clave');

    verificar('hay exactamente una etapa que declara la contratación',
        EtapaPostulacion::query()->activos()->queColocan()->count() === 1, (string) $queColoca?->clave);
    verificar('y las que cierran el proceso son tres',
        $finales->count() === 3, $finales->implode(','));
    verificar('la que coloca también cierra', (bool) $queColoca?->es_final);

    $deEgreso = SituacionAlumno::query()->deEgresados()->pluck('clave');

    verificar('el denominador sale del catálogo', $deEgreso->count() >= 1, $deEgreso->implode(','));

    echo PHP_EOL.'1. El escenario'.PHP_EOL;

    $activa = SituacionEmpresa::query()->where('clave', 'activa')->firstOrFail();
    $abierta = SituacionVacante::query()->where('clave', 'abierta')->firstOrFail();

    $empresa = Empresa::create(['razon_social' => 'Empleador de colocaciones', 'situacion_id' => $activa->id]);
    $otraEmpresa = Empresa::create(['razon_social' => 'Otro empleador', 'situacion_id' => $activa->id]);

    $vacante = Vacante::create([
        'empresa_id' => $empresa->id,
        'titulo' => 'Analista de prueba',
        'descripcion' => 'De prueba.',
        'vacantes_disponibles' => 3,
        'fecha_publicacion' => now()->toDateString(),
        'situacion_id' => $abierta->id,
    ]);

    // Egresados de verdad del demo: son el denominador y no se inventan.
    $egresados = MatriculaOferta::query()
        ->whereIn('situacion_id', SituacionAlumno::query()->deEgresados()->pluck('id'))
        ->get();

    verificar('el demo tiene egresados con los que medir', $egresados->count() >= 3,
        (string) $egresados->count());

    /*
     * ── Se mide POR DIFERENCIA, no contra cero ────────────────────────────
     * La primera versión afirmaba «hay dos colocados» dando por hecho que el
     * demo no tenía ninguna. Pasó aislada y se cayó con diez fallas en el
     * barrido, en cuanto la escuela de ejemplo tuvo colocaciones sembradas. Lo
     * que esta prueba comprueba son los EFECTOS de lo que ella misma hace, así
     * que se guarda la línea base y se compara contra ella.
     */
    $base = $indicador->resumen();

    // Y los protagonistas se eligen SIN colocación previa: si no, «suma un
    // colocado» no sumaría nada y se leería como que el conteo está roto.
    $yaColocadas = Colocacion::query()->whereNotNull('matricula_oferta_id')->pluck('matricula_oferta_id');
    $libres = $egresados->reject(fn ($m) => $yaColocadas->contains($m->id))->values();

    verificar('hay al menos tres egresados sin colocar con los que trabajar',
        $libres->count() >= 3, (string) $libres->count());

    $unoA = $libres[0];
    $unoB = $libres[1];

    /*
     * La matrícula de una TERCERA persona, para la comprobación de la ajena.
     *
     * Tiene que ser distinta de las DOS que se usan arriba: con la de `$unoB`
     * —a quien se le registra la colocación— la matrícula sería suya, el
     * rechazo no ocurriría y la prueba lo leería como que la salvaguarda no
     * existe. Se descubrió al correrla la primera vez.
     */
    $deOtro = $libres->first(fn ($m) => (int) $m->persona_id !== (int) $unoA->persona_id
        && (int) $m->persona_id !== (int) $unoB->persona_id);

    verificar('y hay una tercera persona entre ellos', $deOtro !== null);

    $baseEmpleo = [
        'puesto' => 'Analista junior',
        'fecha_ingreso' => now()->toDateString(),
        'salario' => 15000,
        'relacionado_con_carrera' => true,
    ];

    echo PHP_EOL.'2. A la etapa que coloca no se entra sin la colocación'.PHP_EOL;

    $postulacion = $postulador->registrar($vacante, (int) $unoA->persona_id, (int) $unoA->id, capturadaPor: $quien);

    $rebotado = null;

    try {
        $postulador->mover($postulacion, (int) $queColoca->id, $quien);
    } catch (RuntimeException $e) {
        $rebotado = $e->getMessage();
    }

    verificar('mover a «contratado» a secas se rechaza', $rebotado !== null);
    verificar('con un mensaje que dice qué hace falta',
        str_contains((string) $rebotado, 'registrar la colocación'));
    verificar('y la postulación NO se movió',
        (int) $postulacion->refresh()->etapa_id !== (int) $queColoca->id);
    verificar('ni quedó colocación suelta', Colocacion::query()->where('postulacion_id', $postulacion->id)->doesntExist());

    // A otra etapa cualquiera sí se mueve: el candado es sólo el de colocar.
    $entrevista = EtapaPostulacion::query()->where('clave', 'entrevista')->firstOrFail();
    $postulador->mover($postulacion, (int) $entrevista->id, $quien);

    verificar('a otra etapa sí se mueve', (int) $postulacion->refresh()->etapa_id === (int) $entrevista->id);

    echo PHP_EOL.'3. Registrar la contratación hace las dos cosas'.PHP_EOL;

    $colocacion = $registrador->desdePostulacion($postulacion, $baseEmpleo, $quien);

    verificar('quedó la colocación', $colocacion->exists);
    verificar('y la postulación pasó a la etapa que coloca',
        (int) $postulacion->refresh()->etapa_id === (int) $queColoca->id);
    verificar('la colocación hereda la matrícula de la postulación',
        (int) $colocacion->matricula_oferta_id === (int) $unoA->id);
    verificar('y la empresa de la vacante cuando no se dice otra',
        (int) $colocacion->empresa_id === (int) $empresa->id);
    verificar('salió de la bolsa', $colocacion->salioDeLaBolsa());
    verificar('y el movimiento quedó en la bitácora',
        PostulacionBitacora::query()->where('postulacion_id', $postulacion->id)
            ->where('etapa_destino_id', $queColoca->id)->exists());

    echo PHP_EOL.'4. Una postulación, una colocación'.PHP_EOL;

    /*
     * Y el rechazo tiene que ser el NUESTRO.
     *
     * `QueryException` desciende de `RuntimeException`: con un `catch` pelado, la
     * explosión del índice único de la base pasa por comprobación buena y la
     * prueba sigue en verde con la regla quitada —lo que llegaría a la pantalla
     * sería un error de SQL—. Es la segunda vez que muerde en este módulo.
     */
    $mensaje = null;
    $porLaBase = false;

    try {
        $registrador->desdePostulacion($postulacion->refresh(), $baseEmpleo, $quien);
    } catch (QueryException $e) {
        $porLaBase = true;
        $mensaje = $e->getMessage();
    } catch (RuntimeException $e) {
        $mensaje = $e->getMessage();
    }

    verificar('la segunda se rechaza', $mensaje !== null);
    verificar('y la detiene el servicio, no el índice único', ! $porLaBase, (string) $mensaje);
    verificar('con un mensaje que se puede enseñar',
        str_contains((string) $mensaje, 'ya tenía una colocación'));
    verificar('y sigue habiendo una sola',
        Colocacion::query()->where('postulacion_id', $postulacion->id)->count() === 1);

    echo PHP_EOL.'5. Deshacer devuelve la postulación a donde estaba'.PHP_EOL;

    $registrador->deshacer($colocacion->refresh(), $quien);

    verificar('la colocación ya no está',
        Colocacion::query()->where('postulacion_id', $postulacion->id)->doesntExist());
    verificar('la postulación volvió a la etapa de la que venía',
        (int) $postulacion->refresh()->etapa_id === (int) $entrevista->id,
        'quedó en '.$postulacion->etapa_id);

    // Y se puede volver a colocar: con borrado lógico, el único de la tabla lo
    // habría impedido para siempre.
    $reintento = $registrador->desdePostulacion($postulacion->refresh(), $baseEmpleo, $quien);

    verificar('y se puede volver a colocar', $reintento->exists);

    echo PHP_EOL.'6. Colocación directa: seguimiento de egresados'.PHP_EOL;

    $directa = $registrador->directa([
        'persona_id' => (int) $unoB->persona_id,
        'matricula_oferta_id' => (int) $unoB->id,
        'empresa_id' => $otraEmpresa->id,
        'puesto' => 'Coordinador',
        'fecha_ingreso' => now()->subMonths(3)->toDateString(),
        'relacionado_con_carrera' => false,
    ]);

    verificar('queda registrada sin postulación', $directa->postulacion_id === null);
    verificar('y NO cuenta como salida de la bolsa', ! $directa->salioDeLaBolsa());

    // Con la matrícula de otra persona se rechaza: si no, esta colocación
    // sumaría al porcentaje de una carrera que no es la suya.
    $ajena = false;

    try {
        $registrador->directa([
            'persona_id' => (int) $unoB->persona_id,
            'matricula_oferta_id' => (int) $deOtro->id,
            'empresa_id' => $otraEmpresa->id,
            'puesto' => 'Lo que sea',
            'fecha_ingreso' => now()->toDateString(),
        ]);
    } catch (RuntimeException) {
        $ajena = true;
    }

    verificar('con la matrícula de otro se rechaza', $ajena,
        'matrícula '.$deOtro?->id.' es de la persona '.$deOtro?->persona_id);

    echo PHP_EOL.'7. El indicador'.PHP_EOL;

    $resumen = $indicador->resumen();

    verificar('el denominador son los egresados', $resumen['egresados'] === $egresados->count(),
        $resumen['egresados'].' de '.$egresados->count());
    verificar('subieron dos colocados', $resumen['colocados'] - $base['colocados'] === 2,
        $base['colocados'].' → '.$resumen['colocados']);
    verificar('uno de su área y uno de otra',
        $resumen['en_su_area'] - $base['en_su_area'] === 1
        && $resumen['fuera_de_su_area'] - $base['fuera_de_su_area'] === 1,
        $resumen['en_su_area'].' / '.$resumen['fuera_de_su_area']);
    verificar('uno vino de la bolsa y otro de seguimiento',
        $resumen['de_la_bolsa'] - $base['de_la_bolsa'] === 1
        && $resumen['de_seguimiento'] - $base['de_seguimiento'] === 1,
        $resumen['de_la_bolsa'].' / '.$resumen['de_seguimiento']);

    /*
     * Y ese desglose se cuenta sobre LOS MISMOS colocados del porcentaje.
     *
     * Contando todas las colocaciones de la escuela, la pantalla ponía «1 por la
     * bolsa» al lado de «0 de 14 colocados» —dos universos distintos pegados—.
     * Se vio en el navegador; ninguna prueba lo miraba.
     */
    verificar('el desglose por origen suma los colocados',
        $resumen['colocados'] === $resumen['de_la_bolsa'] + $resumen['de_seguimiento'],
        $resumen['de_la_bolsa'].'+'.$resumen['de_seguimiento'].' vs '.$resumen['colocados']);
    verificar('el porcentaje cuadra con lo contado',
        abs($resumen['porcentaje'] - round($resumen['colocados'] * 100 / $resumen['egresados'], 1)) < 0.05,
        (string) $resumen['porcentaje']);

    /*
     * DISTINCT por matrícula: la misma persona con DOS colocaciones —cambió de
     * trabajo— sigue siendo un egresado colocado. Sin el distinct el numerador
     * subiría solo y el porcentaje podría pasar del 100 %.
     */
    $registrador->directa([
        'persona_id' => (int) $unoB->persona_id,
        'matricula_oferta_id' => (int) $unoB->id,
        'empresa_id' => $empresa->id,
        'puesto' => 'Se cambió de trabajo',
        'fecha_ingreso' => now()->toDateString(),
        'relacionado_con_carrera' => false,
    ]);

    verificar('dos empleos de la misma matrícula siguen siendo UN colocado',
        $indicador->resumen()['colocados'] === $resumen['colocados'],
        $resumen['colocados'].' → '.$indicador->resumen()['colocados']);

    echo PHP_EOL.'8. Lo que no señala carrera se cuenta aparte'.PHP_EOL;

    $sinCarrera = $registrador->directa([
        'persona_id' => (int) $unoA->persona_id,
        'matricula_oferta_id' => null,
        'empresa_id' => $empresa->id,
        'puesto' => 'Sin señalar carrera',
        'fecha_ingreso' => now()->toDateString(),
    ]);

    $conSuelta = $indicador->resumen();

    verificar('se reporta como sin carrera señalada',
        $conSuelta['sin_carrera_senalada'] === $base['sin_carrera_senalada'] + 1,
        $base['sin_carrera_senalada'].' → '.$conSuelta['sin_carrera_senalada']);
    verificar('y NO mueve el número de colocados', $conSuelta['colocados'] === $resumen['colocados'],
        $resumen['colocados'].' → '.$conSuelta['colocados']);

    $sumaPorCarrera = collect($indicador->porCarrera())->sum('colocados');

    verificar('la suma por carrera tampoco la incluye', $sumaPorCarrera === $conSuelta['colocados'],
        $sumaPorCarrera.' vs '.$conSuelta['colocados']);

    /*
     * Y una colocación de quien TODAVÍA NO EGRESA tampoco entra: se cuenta
     * aparte y con su razón. Sin decirlo, la diferencia entre lo registrado y lo
     * contado es un misterio que hace desconfiar del indicador entero.
     */
    $activa = MatriculaOferta::query()
        ->whereNotIn('situacion_id', SituacionAlumno::query()->deEgresados()->pluck('id'))
        ->whereNotNull('persona_id')
        ->firstOrFail();

    $antesDeLaPractica = $indicador->resumen();

    $registrador->directa([
        'persona_id' => (int) $activa->persona_id,
        'matricula_oferta_id' => (int) $activa->id,
        'empresa_id' => $empresa->id,
        'puesto' => 'Práctica profesional',
        'fecha_ingreso' => now()->toDateString(),
        'relacionado_con_carrera' => true,
    ]);

    $conLaPractica = $indicador->resumen();

    verificar('la de quien no ha egresado NO sube el porcentaje',
        $conLaPractica['colocados'] === $antesDeLaPractica['colocados'],
        $antesDeLaPractica['colocados'].' → '.$conLaPractica['colocados']);
    verificar('pero se reporta con su razón',
        $conLaPractica['de_quien_no_ha_egresado'] === $antesDeLaPractica['de_quien_no_ha_egresado'] + 1,
        (string) $conLaPractica['de_quien_no_ha_egresado']);

    echo PHP_EOL.'9. El denominador obedece al catálogo'.PHP_EOL;

    $situacion = SituacionAlumno::query()->deEgresados()->orderBy('id')->firstOrFail();
    $cuantosDeEsa = MatriculaOferta::query()->where('situacion_id', $situacion->id)->count();

    $situacion->update(['cuenta_como_egresado' => false]);
    app(IndicadorEmpleabilidad::class); // el servicio no memoriza; se relee

    $sinEsa = $indicador->resumen();

    verificar('apagar una situación baja el denominador',
        $sinEsa['egresados'] === $egresados->count() - $cuantosDeEsa,
        $sinEsa['egresados'].' vs '.($egresados->count() - $cuantosDeEsa));

    $situacion->update(['cuenta_como_egresado' => true]);

    verificar('y volver a encenderla lo restituye',
        $indicador->resumen()['egresados'] === $egresados->count());

    echo PHP_EOL.'10. Los filtros mueven las dos cifras'.PHP_EOL;

    $porGeneracion = $indicador->porGeneracion();
    $conAlguno = collect($porGeneracion)->firstWhere(fn ($g) => $g['colocados'] > 0);

    verificar('hay una generación con colocados', $conAlguno !== null);

    if ($conAlguno !== null) {
        $filtrado = $indicador->resumen(['generacion' => $conAlguno['generacion']]);

        verificar('filtrando por esa generación, el denominador es el suyo',
            $filtrado['egresados'] === $conAlguno['egresados'],
            $filtrado['egresados'].' vs '.$conAlguno['egresados']);
        verificar('y el numerador también',
            $filtrado['colocados'] === $conAlguno['colocados']);
        verificar('así que el porcentaje nunca pasa del 100',
            $filtrado['porcentaje'] <= 100.0, (string) $filtrado['porcentaje']);
    }

    echo PHP_EOL.'11. La pantalla'.PHP_EOL;

    $props = json_decode(
        $control->index(peticionDe($staff, 'GET'))->toResponse(peticionDe($staff, 'GET'))->getContent(),
        true,
    )['props'];

    verificar('el listado trae las colocaciones', count($props['colocaciones']['data']) >= 3,
        (string) count($props['colocaciones']['data']));

    $suelta = collect($props['colocaciones']['data'])->firstWhere('id', $sinCarrera->id);

    /*
     * `array_key_exists` y no `?? 'x'`: el coalescente reemplaza justamente el
     * null que se quiere comprobar, así que la condición era falsa pasara lo que
     * pasara. Estaba mal escrita y se vio al correrla.
     */
    verificar('la que no señala carrera llegó a la pantalla', $suelta !== null);
    verificar('se ve sin carrera',
        $suelta !== null && array_key_exists('carrera', $suelta) && $suelta['carrera'] === null);
    verificar('y sin el dato de si es de su área',
        $suelta !== null && array_key_exists('relacionado', $suelta) && $suelta['relacionado'] === null);

    $ind = json_decode(
        $control->indicadores(peticionDe($staff, 'GET'))->toResponse(peticionDe($staff, 'GET'))->getContent(),
        true,
    )['props'];

    verificar('la pantalla del indicador trae el resumen', isset($ind['resumen']['porcentaje']));
    verificar('y las dos aperturas',
        count($ind['por_carrera']) > 0 && count($ind['por_generacion']) > 0);

    echo PHP_EOL.'12. «No se preguntó» no es «no es de su área»'.PHP_EOL;

    /*
     * Hace falta una colocación CON matrícula y SIN el dato: la de arriba que no
     * señala carrera nunca llega al desglose por área —no se une contra ningún
     * egresado— así que con ella sola la comprobación sería vacua. Se vio
     * mutando: sumar el hueco a los de otra área no tumbaba nada.
     */
    $tercero = $libres->first(fn ($m) => (int) $m->persona_id !== (int) $unoA->persona_id
        && (int) $m->persona_id !== (int) $unoB->persona_id);

    $antes = $indicador->resumen();

    $registrador->directa([
        'persona_id' => (int) $tercero->persona_id,
        'matricula_oferta_id' => (int) $tercero->id,
        'empresa_id' => $otraEmpresa->id,
        'puesto' => 'No se le preguntó',
        'fecha_ingreso' => now()->toDateString(),
        // A propósito sin `relacionado_con_carrera`.
    ]);

    $despues = $indicador->resumen();

    verificar('suma un colocado', $despues['colocados'] === $antes['colocados'] + 1,
        $antes['colocados'].' → '.$despues['colocados']);
    verificar('que cae en «sin ese dato»', $despues['sin_ese_dato'] === $antes['sin_ese_dato'] + 1,
        $antes['sin_ese_dato'].' → '.$despues['sin_ese_dato']);
    verificar('y NO en «de otra área»', $despues['fuera_de_su_area'] === $antes['fuera_de_su_area'],
        $antes['fuera_de_su_area'].' → '.$despues['fuera_de_su_area']);
    verificar('las tres cifras suman los colocados',
        $despues['colocados'] === $despues['en_su_area'] + $despues['fuera_de_su_area'] + $despues['sin_ese_dato'],
        $despues['en_su_area'].'+'.$despues['fuera_de_su_area'].'+'.$despues['sin_ese_dato']
            .' vs '.$despues['colocados']);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
