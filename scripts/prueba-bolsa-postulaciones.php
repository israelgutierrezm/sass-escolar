<?php

/**
 * Módulo 11 · Bolsa de trabajo — las postulaciones. Con rollback.
 *
 * Se corre con `php scripts/prueba-bolsa-postulaciones.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. El interruptor `bolsa.postulacion_autogestiva` gobierna SÓLO el camino
 *     del alumno. Apagado, postularse desde el portal responde 404 y la
 *     ventanilla sigue capturando; encendido, funcionan los dos. Un interruptor
 *     que también apagara la ventanilla dejaría la bolsa sin ningún camino.
 *  2. Nadie se postula dos veces a la misma vacante. El único de la base es la
 *     red de abajo, pero el mensaje tiene que llegar antes.
 *  3. Una vacante que ya no está VIGENTE no admite postulaciones ni por
 *     ventanilla: capturarla ahí citaría a alguien por una plaza cerrada.
 *  4. La bitácora se escribe en el alta —con la etapa de origen en null— y en
 *     cada movimiento. Sin el renglón del alta, el primer tiempo no tiene desde
 *     cuándo contarse.
 *  5. Mover a la MISMA etapa no anota nada: dos clics inflarían la bitácora con
 *     renglones de cero días y falsearían justo lo que existe para medir.
 *  6. La matrícula que se guarda es DE esa persona. Un id ajeno se rechaza, y
 *     sin id se resuelve sola cuando la persona tiene una sola.
 *  7. El CV de otro no se descarga: la ruta del alumno lleva id.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\Bolsa\MisVacantesController;
use App\Http\Controllers\Bolsa\PostulacionController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Bolsa\Empresa;
use App\Models\Bolsa\EtapaPostulacion;
use App\Models\Bolsa\Postulacion;
use App\Models\Bolsa\PostulacionBitacora;
use App\Models\Bolsa\SituacionEmpresa;
use App\Models\Bolsa\SituacionVacante;
use App\Models\Bolsa\Vacante;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Bolsa\Postulador;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

/**
 * Lo que la pantalla del alumno recibe, sin pasar por HTTP.
 *
 * La petición tiene que llevar `X-Inertia`: sin ella la respuesta se renderiza
 * como HTML y las props quedan enterradas en el marcado.
 */
function props($respuesta, Usuario $como): array
{
    $peticion = peticionDe($como, 'GET');
    $peticion->headers->set('X-Inertia-Version', '');

    return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'] ?? [];
}

$db->beginTransaction();

try {
    $ajustes = app(Ajustes::class);
    $postulador = app(Postulador::class);
    $control = app(PostulacionController::class);
    $portal = app(MisVacantesController::class);

    $staff = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    echo PHP_EOL.'0. El interruptor nace APAGADO'.PHP_EOL;

    // Por omisión falso a propósito: mientras la escuela no lo encienda, la
    // bolsa se opera en ventanilla, que es lo que el cliente pidió.
    $ajustes->olvidar();
    verificar('`bolsa.postulacion_autogestiva` por omisión está apagado',
        $ajustes->bool(CatalogoAjustes::BOLSA_AUTOGESTIVA) === false);

    /*
     * El escenario: una empresa, una vacante abierta a todos los programas académicos, y
     * DOS alumnos con matrícula. Se crea todo aquí para no depender de que el
     * demo tenga bolsa sembrada ni tocar sus datos.
     */
    $activa = SituacionEmpresa::query()->where('clave', 'activa')->firstOrFail();
    $abierta = SituacionVacante::query()->where('clave', 'abierta')->firstOrFail();
    $cerrada = SituacionVacante::query()->where('clave', 'cerrada')->firstOrFail();

    $empresa = Empresa::create(['razon_social' => 'Empleador de postulaciones', 'situacion_id' => $activa->id]);

    $vacante = Vacante::create([
        'empresa_id' => $empresa->id,
        'titulo' => 'Practicante de prueba',
        'descripcion' => 'Vacante de prueba.',
        'vacantes_disponibles' => 3,
        'fecha_publicacion' => now()->toDateString(),
        'situacion_id' => $abierta->id,
    ]);

    verificar('la vacante nace vigente', Vacante::query()->vigentes()->whereKey($vacante->id)->exists());

    /*
     * Dos alumnos con UNA SOLA matrícula, y uno con varias.
     *
     * Lo de «una sola» no es un detalle de armado: es lo que hace comprobable la
     * resolución automática del perfil académico. Y en el demo NO existe ese
     * caso —los quince alumnos con matrícula tienen dos o tres, porque la
     * multiprograma se sembró a propósito—, así que se construye aquí: se toman
     * dos personas sin ninguna matrícula y se les da una, dentro de la
     * transacción. Buscar «alguien con una sola» y saltar la comprobación si no
     * aparece sería una prueba que se apaga sola el día que cambien los datos.
     */
    $porPersona = MatriculaOferta::query()
        ->whereNotNull('persona_id')
        ->get()
        ->groupBy('persona_id');

    $variasDeUna = $porPersona->first(fn ($m) => $m->count() > 1);

    $molde = MatriculaOferta::query()->firstOrFail();

    $sinMatricula = Persona::query()
        ->whereNotIn('id', $porPersona->keys()->all())
        ->limit(4)
        ->get();

    verificar('hay cuatro personas sin matrícula con las que armar el caso',
        $sinMatricula->count() === 4, (string) $sinMatricula->count());

    $nueva = fn (Persona $persona, string $clave) => MatriculaOferta::create([
        'persona_id' => $persona->id,
        'oferta_id' => $molde->oferta_id,
        'matricula' => 'PRUEBA-BOLSA-'.$clave.'-'.$persona->id,
        'generacion' => $molde->generacion,
        'periodo_actual' => $molde->periodo_actual,
        'fecha_ingreso' => $molde->fecha_ingreso,
        'situacion_id' => $molde->situacion_id,
        'estatus' => $molde->estatus,
    ]);

    $matriculaA = $nueva($sinMatricula[0], 'A');
    $matriculaB = $nueva($sinMatricula[1], 'B');
    $personaA = (int) $matriculaA->persona_id;
    $personaB = (int) $matriculaB->persona_id;

    /*
     * La tercera y la cuarta NO se postulan a nada hasta el final.
     *
     * Están aquí porque la comprobación de «vacante cerrada» necesita gente
     * LIMPIA: con alguien que ya se postuló, el rechazo lo produce la regla de
     * no repetir y la de vigencia nunca llega a evaluarse. Se descubrió mutando
     * —quitar la comprobación de vigencia no tumbaba nada—. Y hacen falta DOS
     * porque los dos caminos se prueban por separado: si el del servicio gasta
     * a la persona limpia, el de la ventanilla vuelve a chocar contra la regla
     * de repetido y queda tapado igual.
     */
    $matriculaC = $nueva($sinMatricula[2], 'C');
    $personaC = (int) $matriculaC->persona_id;
    $matriculaD = $nueva($sinMatricula[3], 'D');
    $personaD = (int) $matriculaD->persona_id;

    verificar('cada uno quedó con una sola matrícula',
        MatriculaOferta::query()->where('persona_id', $personaA)->count() === 1
        && MatriculaOferta::query()->where('persona_id', $personaB)->count() === 1);

    echo PHP_EOL.'1. Apagado: el portal NO deja postularse, la ventanilla SÍ'.PHP_EOL;

    $alumnoA = Usuario::query()->where('persona_id', $personaA)->first();

    if ($alumnoA === null) {
        // Sin cuenta no se puede recorrer el camino del portal; se le arma una
        // dentro de la transacción, que el rollback se lleva.
        $alumnoA = Usuario::create([
            'persona_id' => $personaA,
            'usuario' => 'prueba.bolsa.'.$personaA,
            'password' => bcrypt(bin2hex(random_bytes(20))),
            'activo' => true,
        ]);
    }

    $rebotado = false;

    try {
        $portal->postularme(peticionDe($alumnoA, 'POST', []), $vacante);
    } catch (NotFoundHttpException) {
        $rebotado = true;
    }

    verificar('con el interruptor apagado, postularse desde el portal responde 404', $rebotado);
    verificar('y no quedó ninguna postulación', Postulacion::query()->where('vacante_id', $vacante->id)->count() === 0);

    // La ventanilla no depende del interruptor: es el otro camino, y con el
    // interruptor apagado es el único.
    $control->capturar(peticionDe($staff, 'POST', ['persona_id' => $personaA]), $vacante);

    $deA = Postulacion::query()->where('vacante_id', $vacante->id)->where('persona_id', $personaA)->first();

    verificar('la ventanilla sí registra con el interruptor apagado', $deA !== null);
    verificar('y queda marcada como capturada por alguien',
        $deA !== null && (int) $deA->capturada_por === (int) $staff->persona_id);
    verificar('o sea, NO es autogestiva', $deA !== null && $deA->esAutogestiva() === false);

    echo PHP_EOL.'2. La matrícula que se guarda es de esa persona'.PHP_EOL;

    // Sin id explícito y con una sola matrícula, se resuelve sola.
    verificar('sin señalar matrícula, se resolvió la suya',
        $deA !== null && (int) $deA->matricula_oferta_id === (int) $matriculaA->id,
        'guardó '.var_export($deA?->matricula_oferta_id, true).', suya '.$matriculaA->id);

    // Y con el id de OTRA persona, se rechaza en vez de colgarle la
    // postulación al perfil académico equivocado.
    $control->capturar(
        peticionDe($staff, 'POST', ['persona_id' => $personaB, 'matricula_oferta_id' => $matriculaA->id]),
        $vacante,
    );

    verificar('con la matrícula de otro, no se registró',
        Postulacion::query()->where('vacante_id', $vacante->id)->where('persona_id', $personaB)->doesntExist());

    /*
     * Y con DOS programas académicos no se adivina: se deja sin señalar. Colgarla de la
     * primera que aparezca torcería los indicadores por programa académico sin que nada
     * fallara, que es la peor forma de equivocarse.
     */
    if ($variasDeUna !== null) {
        $ambigua = (int) $variasDeUna->first()->persona_id;

        $control->capturar(peticionDe($staff, 'POST', ['persona_id' => $ambigua]), $vacante);

        $deAmbigua = Postulacion::query()
            ->where('vacante_id', $vacante->id)->where('persona_id', $ambigua)->first();

        verificar('con dos programas académicos, se registra igual', $deAmbigua !== null);
        verificar('pero sin señalar con cuál', $deAmbigua?->matricula_oferta_id === null);
    } else {
        verificar('el demo tiene alguien con dos matrículas para probar la ambigüedad', false);
    }

    /*
     * Y la ventanilla SÍ puede elegir, con el endpoint que le dice cuáles son.
     * Sin él, la pantalla no tendría de dónde sacar la lista: el buscador de
     * alumnos entrega personas y deduplica a propósito.
     */
    if ($variasDeUna !== null) {
        $ambigua = (int) $variasDeUna->first()->persona_id;

        $suyas = json_decode(
            $control->matriculasDe(Persona::findOrFail($ambigua))->getContent(),
            true,
        );

        verificar('el endpoint devuelve sus dos programas académicos', count($suyas) === $variasDeUna->count(),
            count($suyas).' de '.$variasDeUna->count());
        verificar('y ninguna es de otra persona',
            collect($suyas)->pluck('id')->diff($variasDeUna->pluck('id'))->isEmpty());

        // Elegida a propósito, se guarda esa y no otra.
        $elegida = (int) $variasDeUna->last()->id;

        $control->capturar(
            peticionDe($staff, 'POST', ['persona_id' => $ambigua, 'matricula_oferta_id' => $elegida]),
            $otraParaElegir = Vacante::create([
                'empresa_id' => $empresa->id,
                'titulo' => 'Vacante para elegir programa académico',
                'descripcion' => 'Otra.',
                'vacantes_disponibles' => 1,
                'fecha_publicacion' => now()->toDateString(),
                'situacion_id' => $abierta->id,
            ]),
        );

        $conProgramaAcademico = Postulacion::query()
            ->where('vacante_id', $otraParaElegir->id)->where('persona_id', $ambigua)->first();

        verificar('el programa académico elegida se respeta',
            $conProgramaAcademico !== null && (int) $conProgramaAcademico->matricula_oferta_id === $elegida);
    }

    echo PHP_EOL.'3. La bitácora se abre en el alta'.PHP_EOL;

    $primeros = PostulacionBitacora::query()->where('postulacion_id', $deA->id)->get();

    verificar('el alta dejó exactamente un renglón', $primeros->count() === 1, (string) $primeros->count());
    verificar('sin etapa de origen', $primeros->first()?->etapa_origen_id === null);
    verificar('y con la etapa inicial de destino',
        (int) $primeros->first()?->etapa_destino_id === (int) EtapaPostulacion::inicial()?->id);

    echo PHP_EOL.'4. Nadie se postula dos veces a la misma vacante'.PHP_EOL;

    /*
     * Y el rechazo tiene que ser el NUESTRO.
     *
     * `QueryException` desciende de `RuntimeException`, así que un `catch`
     * pelado da por buena la explosión del índice único de la base: la prueba
     * pasaba igual con la comprobación quitada, y lo que llegaba a la pantalla
     * era un error de SQL en vez de «esa persona ya se había postulado». Se
     * mira el tipo Y el mensaje.
     */
    $mensaje = null;
    $porLaBase = false;

    try {
        $postulador->registrar($vacante, $personaA, capturadaPor: (int) $staff->persona_id);
    } catch (QueryException $e) {
        $porLaBase = true;
        $mensaje = $e->getMessage();
    } catch (RuntimeException $e) {
        $mensaje = $e->getMessage();
    }

    verificar('la segunda postulación se rechaza', $mensaje !== null);
    verificar('y la detiene el servicio, no el índice único de la base', ! $porLaBase, (string) $mensaje);
    verificar('con un mensaje que se puede enseñar',
        str_contains((string) $mensaje, 'ya se había postulado'));
    verificar('y sigue habiendo una sola',
        Postulacion::query()->where('vacante_id', $vacante->id)->where('persona_id', $personaA)->count() === 1);

    echo PHP_EOL.'5. Encendido: el alumno se postula solo'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::BOLSA_AUTOGESTIVA => true]);

    verificar('el servicio lee el interruptor encendido', $postulador->autogestivaEncendida());

    $alumnoB = Usuario::query()->where('persona_id', $personaB)->first()
        ?? Usuario::create([
            'persona_id' => $personaB,
            'usuario' => 'prueba.bolsa.'.$personaB,
            'password' => bcrypt(bin2hex(random_bytes(20))),
            'activo' => true,
        ]);

    $portal->postularme(peticionDe($alumnoB, 'POST', ['carta_presentacion' => 'Me interesa mucho.']), $vacante);

    $deB = Postulacion::query()->where('vacante_id', $vacante->id)->where('persona_id', $personaB)->first();

    verificar('quedó registrada', $deB !== null);
    verificar('sin nadie que la capturara —o sea, autogestiva—',
        $deB !== null && $deB->capturada_por === null && $deB->esAutogestiva());
    verificar('con su carta', $deB?->carta_presentacion === 'Me interesa mucho.');

    echo PHP_EOL.'6. El tablero del alumno'.PHP_EOL;

    $vista = props($portal->index(peticionDe($alumnoB, 'GET')), $alumnoB);

    verificar('el tablero dice que la autogestión está encendida', ($vista['autogestiva'] ?? null) === true);

    $enTablero = collect($vista['vacantes'] ?? [])->firstWhere('id', $vacante->id);

    verificar('la vacante sin programas académicos señaladas le aparece', $enTablero !== null);
    verificar('marcada como ya postulada', ($enTablero['ya_postulado'] ?? false) === true);
    verificar('y aparece entre sus postulaciones',
        collect($vista['postulaciones'] ?? [])->contains(fn ($p) => (int) $p['id'] === (int) $deB->id));

    // Apagarlo NO le quita el tablero: sirve para enterarse y luego ir a
    // ventanilla. Lo que se va es el botón.
    $ajustes->guardar([CatalogoAjustes::BOLSA_AUTOGESTIVA => false]);
    $apagado = props($portal->index(peticionDe($alumnoB, 'GET')), $alumnoB);

    verificar('apagado, el tablero se sigue viendo', count($apagado['vacantes'] ?? []) > 0);
    verificar('pero avisado de que no puede postularse solo', ($apagado['autogestiva'] ?? null) === false);

    $ajustes->guardar([CatalogoAjustes::BOLSA_AUTOGESTIVA => true]);

    echo PHP_EOL.'7. Mover de etapa deja rastro; repetir la etapa NO'.PHP_EOL;

    $entrevista = EtapaPostulacion::query()->where('clave', 'entrevista')->firstOrFail();
    $rechazado = EtapaPostulacion::query()->where('clave', 'rechazado')->firstOrFail();

    $antes = PostulacionBitacora::query()->where('postulacion_id', $deB->id)->count();

    $control->mover(
        peticionDe($staff, 'PUT', ['etapa_id' => $entrevista->id, 'nota' => 'Citado el jueves.']),
        $vacante,
        $deB,
    );

    $deB->refresh();

    verificar('la postulación quedó en entrevista', (int) $deB->etapa_id === (int) $entrevista->id);
    verificar('y se anotó el movimiento',
        PostulacionBitacora::query()->where('postulacion_id', $deB->id)->count() === $antes + 1);

    $ultimo = PostulacionBitacora::query()->where('postulacion_id', $deB->id)->orderByDesc('id')->first();

    verificar('con la etapa de la que venía', $ultimo?->etapa_origen_id !== null);
    verificar('y con la nota', $ultimo?->nota === 'Citado el jueves.');

    // Volver a poner la MISMA no anota: dos clics no son dos movimientos.
    $conUno = PostulacionBitacora::query()->where('postulacion_id', $deB->id)->count();

    $postulador->mover($deB, (int) $entrevista->id, (int) $staff->persona_id, 'otra vez');

    verificar('mover a la misma etapa no escribe nada',
        PostulacionBitacora::query()->where('postulacion_id', $deB->id)->count() === $conUno);

    $postulador->mover($deB->refresh(), (int) $rechazado->id, (int) $staff->persona_id);

    verificar('y moverla de verdad sí',
        PostulacionBitacora::query()->where('postulacion_id', $deB->id)->count() === $conUno + 1);

    echo PHP_EOL.'8. La postulación de otra vacante no se mueve desde ésta'.PHP_EOL;

    $otra = Vacante::create([
        'empresa_id' => $empresa->id,
        'titulo' => 'Otra vacante',
        'descripcion' => 'Otra.',
        'vacantes_disponibles' => 1,
        'fecha_publicacion' => now()->toDateString(),
        'situacion_id' => $abierta->id,
    ]);

    $cruzado = false;

    try {
        $control->mover(peticionDe($staff, 'PUT', ['etapa_id' => $entrevista->id]), $otra, $deB);
    } catch (NotFoundHttpException) {
        $cruzado = true;
    }

    verificar('mover con la vacante equivocada responde 404', $cruzado);

    echo PHP_EOL.'9. Una vacante que ya no está vigente no admite a nadie'.PHP_EOL;

    $vacante->update(['situacion_id' => $cerrada->id]);

    verificar('dejó de estar vigente', ! Vacante::query()->vigentes()->whereKey($vacante->id)->exists());

    $tarde = false;

    try {
        $postulador->registrar($vacante->refresh(), $personaC, capturadaPor: (int) $staff->persona_id);
    } catch (RuntimeException) {
        $tarde = true;
    }

    verificar('ni el servicio la deja', $tarde);

    // Y por ventanilla tampoco: es el mismo camino, y si lo dejara, alguien
    // citaría a un postulante por una plaza que ya se cerró.
    $antesVentanilla = Postulacion::query()->where('vacante_id', $vacante->id)->count();
    $control->capturar(peticionDe($staff, 'POST', ['persona_id' => $personaD]), $vacante);

    verificar('ni la ventanilla',
        Postulacion::query()->where('vacante_id', $vacante->id)->count() === $antesVentanilla);

    echo PHP_EOL.'10. El CV es de quien lo subió'.PHP_EOL;

    $deB->update(['cv_ruta' => 'cv/'.$personaB.'/prueba.pdf']);

    $ajeno = false;

    try {
        $portal->descargarCv(peticionDe($alumnoA, 'GET'), $deB->refresh());
    } catch (NotFoundHttpException) {
        $ajeno = true;
    }

    verificar('pedir el currículum de otro responde 404', $ajeno);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
} finally {
    // El ajuste vive en la base del tenant, así que el rollback también se lo
    // lleva; olvidar la memoria evita que el proceso siguiente lea lo de aquí.
    app(Ajustes::class)->olvidar();
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
