<?php

/**
 * Movimientos escolares: la trayectoria administrativa de una matrícula.
 * Contra la base real del demo, con rollback.
 *
 * Se corre con `php scripts/prueba-movimientos-escolares.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué se caería si se rompe ─────────────────────────
 *  1. El movimiento cuelga de la MATRÍCULA, no de la persona: quien estudia dos
 *     programas tiene dos trayectorias y lo que pasa en una no aparece en la
 *     otra. Sin esto, un expediente contaría dos historias como si fueran una.
 *  2. La `referencia` con índice único impide el duplicado: un proceso que corre
 *     dos veces devuelve el MISMO movimiento, no dos. Lo detiene la base, no un
 *     `SELECT` previo que dos peticiones simultáneas pasan las dos.
 *  3. Los procesos existentes EMITEN solos —baja y reingreso desde
 *     `MatriculadorOferta`— y lo hacen con la situación ANTERIOR leída antes de
 *     sobrescribirla. Si se lee después, el par «de → a» dice lo mismo dos veces.
 *  4. Un tipo `solo_automatico` no se captura a mano: ofrecer «Alta» dejaría
 *     registrar dos altas de la misma matrícula.
 *  5. Sólo se guardan los campos que el TIPO declara pedir. Mandar un grupo en
 *     una baja temporal no lo guarda: ese dato ahí no significa nada.
 *  6. `situacion_anterior_id` sale de la MATRÍCULA, no de la petición: el
 *     navegador no puede decir de dónde venía el alumno.
 *  7. Corregir es un permiso APARTE de registrar. Sin él, 403.
 *  8. El alcance por campus se vuelve a comprobar sobre el registro concreto, y
 *     en los TRES caminos —leer, registrar y pedir catálogos—: la matrícula de
 *     otro plantel responde 403 aunque el id llegue por la URL.
 *  9. La fecha efectiva no puede ser futura.
 * 10. Inmutable: no existe ruta que edite ni que borre un movimiento.
 * 11. La línea de tiempo ordena por `fecha_efectiva` y no por `created_at`: una
 *     baja del día 3 capturada el día 10 va antes que algo del día 5.
 * 12. Un «cambio» sólo se pinta si de verdad lo hubo: los dos lados iguales no
 *     producen un renglón «— → —».
 * 13. El formulario sólo ofrece los tipos capturables.
 *
 * Comprobada mutando quince reglas; al quitarlas caen exactamente las
 * verificaciones que las vigilan. Dos de esas mutaciones destaparon huecos:
 *
 *  - Quitarle el 403 de campus al `store` y a `catalogos` NO tumbaba nada: la
 *    suite sólo comprobaba la lectura, que es justo el error contra el que
 *    `AcotaPorCampus` avisa —filtrar la lista no basta, el id viaja en la URL—.
 *  - «Los dos lados iguales no son un cambio» se comprobaba con un movimiento
 *    que no traía NINGUNO de los dos, y ahí el caso ni se ejercita. Con el
 *    escenario construido —una corrección que no movió la situación— la
 *    mutación por fin muere, y de paso quedó a la vista que el guard de «los
 *    dos vacíos» era dead code: dos nulos también son iguales. Se retiró.
 */

use App\Http\Controllers\MovimientoEscolarController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\MovimientoEscolar;
use App\Models\ControlEscolar\TipoMovimientoEscolar;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorMovimientos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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

/** Una petición con datos y con sesión, como la que arma la pantalla. */
function peticion(array $datos = [], string $metodo = 'POST', ?Usuario $como = null): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->headers->set('X-Inertia', 'true');

    if ($como !== null) {
        // Los dos: `Auth::user()` y `$peticion->user()`. Un controlador puede
        // usar cualquiera de los dos y sin ambos la prueba mide otro camino.
        auth()->setUser($como);
        $p->setUserResolver(fn () => $como);
    }

    return $p;
}

/** Un usuario propio con rol propio: nunca se toca el de nadie más. */
function usuarioConPermisos(array $permisos, ?int $campusId = null): Usuario
{
    $sufijo = random_int(100000, 999999);

    $rol = Rol::create([
        'name' => 'prueba_mov_'.$sufijo,
        'nombre' => 'Prueba movimientos '.$sufijo,
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rol->syncPermissions($permisos);

    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Movimientos',
        'segundo_apellido' => (string) $sufijo,
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_mov_'.$sufijo,
        'email' => 'prueba_mov_'.$sufijo.'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $rol->id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

$db->beginTransaction();

try {
    $controlador = app(MovimientoEscolarController::class);
    $registrador = app(RegistradorMovimientos::class);

    // ── El catálogo ────────────────────────────────────────────────────────
    echo PHP_EOL."\033[1mEl catálogo de tipos\033[0m".PHP_EOL;

    $tipos = TipoMovimientoEscolar::query()->activos()->get();
    verificar('la escuela tiene tipos sembrados', $tipos->count() >= 15, $tipos->count().' tipos');

    $alta = $tipos->firstWhere('clave', TipoMovimientoEscolar::ALTA);
    verificar('«Alta» es sólo automática', $alta !== null && $alta->solo_automatico === true);
    verificar(
        'y por eso no está entre los capturables',
        ! TipoMovimientoEscolar::query()->capturables()->pluck('clave')->contains(TipoMovimientoEscolar::ALTA),
    );

    $bajaTemporal = $tipos->firstWhere('clave', TipoMovimientoEscolar::BAJA_TEMPORAL);
    verificar(
        'la baja temporal declara qué campos pide, sin cablear su clave',
        $bajaTemporal?->pide_situacion === true
            && $bajaTemporal->pide_motivo === true
            && $bajaTemporal->pide_grupos === false,
    );

    $cambioGrupo = $tipos->firstWhere('clave', 'cambio_grupo');
    verificar('y el cambio de grupo pide los dos grupos', $cambioGrupo?->pide_grupos === true);

    // ── Dos matrículas de la MISMA persona ─────────────────────────────────
    echo PHP_EOL."\033[1mLa trayectoria es de la matrícula, no de la persona\033[0m".PHP_EOL;

    $conDos = $db->table('matricula_oferta')
        ->select('persona_id', DB::raw('count(*) as cuantas'))
        ->whereNull('deleted_at')
        ->groupBy('persona_id')
        ->havingRaw('count(*) >= 2')
        ->first();

    if ($conDos === null) {
        echo 'Esta escuela no tiene a nadie con dos matrículas; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $suyas = MatriculaOferta::with('oferta')
        ->where('persona_id', $conDos->persona_id)
        ->orderBy('id')
        ->get();

    $primera = $suyas[0];
    $segunda = $suyas[1];

    $enPrimera = $registrador->registrar($primera, 'otro', MovimientoEscolar::ORIGEN_MANUAL, null, [
        'observaciones' => 'Sólo de la primera trayectoria.',
    ]);

    verificar('el movimiento se anota', $enPrimera !== null);
    verificar(
        'sale en la trayectoria donde ocurrió',
        MovimientoEscolar::de($primera->id)->pluck('id')->contains($enPrimera->id),
    );
    verificar(
        'y NO en la otra matrícula de la misma persona',
        ! MovimientoEscolar::de($segunda->id)->pluck('id')->contains($enPrimera->id),
        'persona '.$conDos->persona_id,
    );

    // ── Idempotencia por referencia ────────────────────────────────────────
    echo PHP_EOL."\033[1mUn proceso repetido no deja dos movimientos\033[0m".PHP_EOL;

    $ref = 'prueba:'.random_int(1000000, 9999999);

    $uno = $registrador->registrar($primera, 'otro', MovimientoEscolar::ORIGEN_MATRICULACION, $ref);
    $otra = $registrador->registrar($primera, 'otro', MovimientoEscolar::ORIGEN_MATRICULACION, $ref);

    verificar('la segunda vez devuelve el MISMO movimiento', $uno !== null && $otra !== null && $uno->id === $otra->id);
    verificar(
        'y sólo hay una fila con esa referencia',
        MovimientoEscolar::where('matricula_oferta_id', $primera->id)->where('referencia', $ref)->count() === 1,
    );

    /*
     * Dos manuales SIN referencia sí conviven: MySQL permite repetir NULL en un
     * índice único. Si no fuera así, la segunda captura del día chocaría contra
     * la primera y nadie podría registrar dos movimientos a la misma matrícula.
     */
    $manualA = $registrador->registrar($primera, 'otro', MovimientoEscolar::ORIGEN_MANUAL, null, ['motivo' => 'uno']);
    $manualB = $registrador->registrar($primera, 'otro', MovimientoEscolar::ORIGEN_MANUAL, null, ['motivo' => 'dos']);
    verificar(
        'dos manuales sin referencia no se estorban',
        $manualA !== null && $manualB !== null && $manualA->id !== $manualB->id,
    );

    // ── Emisión automática desde la operación ──────────────────────────────
    echo PHP_EOL."\033[1mBaja y reingreso los emite la operación, no la pantalla\033[0m".PHP_EOL;

    $matriculador = app(MatriculadorOferta::class);

    $activa = MatriculaOferta::with('oferta')
        ->whereNull('deleted_at')
        ->where('estatus', 'activo')
        ->whereNotNull('situacion_id')
        ->first();

    verificar('hay una matrícula activa con la que probar', $activa !== null);

    $situacionOriginal = $activa->situacion_id;
    $antesDeBaja = MovimientoEscolar::where('matricula_oferta_id', $activa->id)->count();

    $situacionDeBaja = SituacionAlumno::query()->where('clave', 'baja_temporal')->first()
        ?? SituacionAlumno::query()->where('clave', 'like', 'baja%')->first();

    verificar('la escuela tiene una situación de baja', $situacionDeBaja !== null);

    $matriculador->darDeBaja($activa, $situacionDeBaja->id);

    $baja = MovimientoEscolar::where('matricula_oferta_id', $activa->id)
        ->orderByDesc('id')->first();

    verificar(
        'la baja deja su movimiento',
        MovimientoEscolar::where('matricula_oferta_id', $activa->id)->count() === $antesDeBaja + 1,
    );
    verificar('y lo marca como automático', $baja !== null && $baja->esAutomatico(), 'origen='.$baja?->origen);
    verificar(
        'con la situación ANTERIOR leída antes de sobrescribirla',
        $baja?->situacion_anterior_id === $situacionOriginal,
        'anterior='.var_export($baja?->situacion_anterior_id, true).' original='.var_export($situacionOriginal, true),
    );
    verificar(
        'y la nueva, que es otra',
        $baja?->situacion_nueva_id === $situacionDeBaja->id && $baja->situacion_anterior_id !== $baja->situacion_nueva_id,
    );

    $matriculador->reactivar($activa->fresh());

    $reingreso = MovimientoEscolar::where('matricula_oferta_id', $activa->id)
        ->orderByDesc('id')->first();

    verificar(
        'el reingreso también deja el suyo, con su tipo propio',
        $reingreso?->tipo?->clave === TipoMovimientoEscolar::REINGRESO,
        'tipo='.($reingreso?->tipo?->clave ?? 'null'),
    );
    verificar(
        'y viene de la situación de baja',
        $reingreso?->situacion_anterior_id === $situacionDeBaja->id,
    );

    /*
     * El segundo clic no es un segundo hecho.
     *
     * Se vio en el navegador: reactivar una matrícula ya activa dejaba un
     * reingreso con los dos lados iguales —un renglón permanente que no dice
     * nada—. Mientras esto sólo movía dos columnas daba igual; desde que deja
     * historia, no.
     */
    $trasReingreso = MovimientoEscolar::where('matricula_oferta_id', $activa->id)->count();

    $matriculador->reactivar($activa->fresh());
    verificar(
        'reactivar a quien ya está activo no deja otro reingreso',
        MovimientoEscolar::where('matricula_oferta_id', $activa->id)->count() === $trasReingreso,
    );

    $matriculador->darDeBaja($activa->fresh(), $situacionDeBaja->id);
    $trasBaja = MovimientoEscolar::where('matricula_oferta_id', $activa->id)->count();
    $matriculador->darDeBaja($activa->fresh(), $situacionDeBaja->id);
    verificar(
        'ni bajar dos veces a la misma situación deja dos bajas',
        MovimientoEscolar::where('matricula_oferta_id', $activa->id)->count() === $trasBaja,
    );

    /*
     * Pero pasar de temporal a definitiva SÍ es un hecho: la matrícula sigue
     * de baja y aun así cambió algo. Se construye porque el caso no aparece
     * solo, y sin él el guard de arriba podría estar comiéndose bajas reales.
     */
    $otraDeBaja = SituacionAlumno::query()->where('clave', 'like', 'baja%')
        ->where('id', '!=', $situacionDeBaja->id)->first();

    if ($otraDeBaja !== null) {
        $matriculador->darDeBaja($activa->fresh(), $otraDeBaja->id);
        verificar(
            'pero cambiar de una baja a otra sí se registra',
            MovimientoEscolar::where('matricula_oferta_id', $activa->id)->count() === $trasBaja + 1,
        );
    }

    // Se devuelve a como estaba para no estorbar a lo que sigue.
    $activa->fresh()->update(['estatus' => 'activo', 'situacion_id' => $situacionOriginal]);

    // ── El controlador: lo que acepta y lo que no ──────────────────────────
    echo PHP_EOL."\033[1mLa captura manual\033[0m".PHP_EOL;

    $captura = usuarioConPermisos(['ver-movimientos-escolares', 'registrar-movimiento-escolar']);

    // 4 · Un tipo sólo automático no se captura a mano.
    $motivo = null;
    try {
        $controlador->store(peticion([
            'tipo_id' => $alta->id,
            'fecha_efectiva' => now()->toDateString(),
        ], 'POST', $captura), $primera);
    } catch (HttpException $e) {
        $motivo = $e->getStatusCode();
    }
    verificar('un tipo sólo automático se rechaza con 422', $motivo === 422, 'estado='.var_export($motivo, true));

    // 9 · La fecha futura no pasa la validación.
    $rechazada = false;
    try {
        $controlador->store(peticion([
            'tipo_id' => $bajaTemporal->id,
            'fecha_efectiva' => now()->addDays(3)->toDateString(),
        ], 'POST', $captura), $primera);
    } catch (ValidationException $e) {
        $rechazada = array_key_exists('fecha_efectiva', $e->errors());
    }
    verificar('una fecha efectiva futura se rechaza en la validación', $rechazada);

    // 5 y 6 · Sólo los campos que el tipo pide; la situación anterior sale de
    // la matrícula.
    $unGrupo = $db->table('grupos')->whereNull('deleted_at')->value('id');
    $otraSituacion = SituacionAlumno::query()->where('id', '!=', $primera->situacion_id)->value('id');

    $controlador->store(peticion([
        'tipo_id' => $bajaTemporal->id,
        'fecha_efectiva' => now()->toDateString(),
        'situacion_nueva_id' => $otraSituacion,
        // Lo que el tipo NO pide, mandado a propósito:
        'grupo_nuevo_id' => $unGrupo,
        'periodo_nuevo' => 7,
        /*
         * Una situación REAL pero equivocada, no un id inexistente: con uno
         * inventado, creerle a la petición reventaría contra la foránea y la
         * prueba moriría en vez de reportar. Lo que se quiere ver es la fila
         * GUARDADA diciendo algo que el navegador escribió.
         */
        'situacion_anterior_id' => $otraSituacion,
        'motivo' => 'Motivo de la prueba',
    ], 'POST', $captura), $primera);

    $capturada = MovimientoEscolar::where('matricula_oferta_id', $primera->id)
        ->orderByDesc('id')->first();

    verificar('el movimiento capturado se guarda', $capturada?->tipo_id === $bajaTemporal->id);
    verificar('con origen manual', $capturada?->origen === MovimientoEscolar::ORIGEN_MANUAL);
    verificar(
        'el grupo que el tipo no pide NO se guarda',
        $capturada?->grupo_nuevo_id === null,
        'grupo='.var_export($capturada?->grupo_nuevo_id, true),
    );
    verificar(
        'el periodo que el tipo no pide tampoco',
        $capturada?->periodo_nuevo === null,
    );
    verificar(
        'la situación anterior sale de la MATRÍCULA, no de la petición',
        $capturada?->situacion_anterior_id === $primera->situacion_id,
        'guardada='.var_export($capturada?->situacion_anterior_id, true).' matrícula='.var_export($primera->situacion_id, true),
    );
    verificar('y el motivo, que sí pide, se conserva', $capturada?->motivo === 'Motivo de la prueba');
    verificar(
        'queda anotado quién lo registró',
        (int) $capturada?->created_by === $captura->id,
        'created_by='.var_export($capturada?->created_by, true),
    );

    // 7 · Corregir necesita su propio permiso.
    echo PHP_EOL."\033[1mCorregir es otro permiso\033[0m".PHP_EOL;

    $estado = null;
    try {
        $controlador->store(peticion([
            'tipo_id' => $tipos->firstWhere('clave', TipoMovimientoEscolar::CORRECCION)->id,
            'fecha_efectiva' => now()->toDateString(),
            'corrige_movimiento_id' => $capturada->id,
            'motivo' => 'La fecha estaba mal.',
        ], 'POST', $captura), $primera);
    } catch (HttpException $e) {
        $estado = $e->getStatusCode();
    }
    verificar('sin `corregir-movimiento-escolar` da 403', $estado === 403, 'estado='.var_export($estado, true));

    $corrector = usuarioConPermisos([
        'ver-movimientos-escolares', 'registrar-movimiento-escolar', 'corregir-movimiento-escolar',
    ]);

    $controlador->store(peticion([
        'tipo_id' => $tipos->firstWhere('clave', TipoMovimientoEscolar::CORRECCION)->id,
        'fecha_efectiva' => now()->toDateString(),
        'corrige_movimiento_id' => $capturada->id,
        'motivo' => 'La fecha estaba mal.',
    ], 'POST', $corrector), $primera);

    $correccion = MovimientoEscolar::where('matricula_oferta_id', $primera->id)
        ->orderByDesc('id')->first();

    verificar('con el permiso, la corrección se registra', $correccion?->corrige_movimiento_id === $capturada->id);
    verificar(
        'y el movimiento corregido SIGUE ahí, sin tocar',
        MovimientoEscolar::find($capturada->id)?->motivo === 'Motivo de la prueba',
    );

    // 10 · Inmutable: no hay ruta que edite ni borre.
    $rutas = collect(app('router')->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'movimientos'))
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri());

    verificar(
        'no existe ruta que edite ni borre un movimiento',
        $rutas->filter(fn (string $r) => str_contains($r, 'PUT') || str_contains($r, 'PATCH') || str_contains($r, 'DELETE'))->isEmpty(),
        $rutas->implode(' · '),
    );

    // ── Alcance por campus ─────────────────────────────────────────────────
    echo PHP_EOL."\033[1mEl alcance por campus se vuelve a comprobar\033[0m".PHP_EOL;

    $campusDeLaPrimera = $primera->oferta?->campus_id;
    $otroCampus = $db->table('campus')->whereNull('deleted_at')
        ->where('id', '!=', $campusDeLaPrimera)->value('id');

    verificar('la escuela tiene más de un campus', $otroCampus !== null);

    $acotado = usuarioConPermisos(['ver-movimientos-escolares', 'registrar-movimiento-escolar'], (int) $otroCampus);

    $estadoAcotado = null;
    try {
        $controlador->index(peticion([], 'GET', $acotado), $primera);
    } catch (HttpException $e) {
        $estadoAcotado = $e->getStatusCode();
    }
    verificar(
        'un rol de otro campus no ve esa trayectoria (403)',
        $estadoAcotado === 403,
        'estado='.var_export($estadoAcotado, true),
    );

    /*
     * Y no basta con acotar la LECTURA. Un POST se manda a mano, así que el
     * alta y los catálogos vuelven a comprobar: si sólo se filtrara lo que se
     * ve, cambiar el id en la URL se saltaría el candado. Lo destapó una
     * mutación: quitarle el 403 al `store` no tumbaba nada.
     */
    $estadoAlta = null;
    try {
        $controlador->store(peticion([
            'tipo_id' => $bajaTemporal->id,
            'fecha_efectiva' => now()->toDateString(),
        ], 'POST', $acotado), $primera);
    } catch (HttpException $e) {
        $estadoAlta = $e->getStatusCode();
    }
    verificar(
        'y tampoco puede REGISTRARLE un movimiento (403)',
        $estadoAlta === 403,
        'estado='.var_export($estadoAlta, true),
    );

    $estadoCatalogos = null;
    try {
        $controlador->catalogos(peticion([], 'GET', $acotado), $primera);
    } catch (HttpException $e) {
        $estadoCatalogos = $e->getStatusCode();
    }
    verificar(
        'ni pedir los catálogos de su formulario (403)',
        $estadoCatalogos === 403,
        'estado='.var_export($estadoCatalogos, true),
    );

    $global = usuarioConPermisos(['ver-movimientos-escolares']);
    $respuesta = $controlador->index(peticion([], 'GET', $global), $primera);
    verificar('un rol global sí la ve', $respuesta->getStatusCode() === 200);

    // ── La lectura: legible y ordenada ─────────────────────────────────────
    echo PHP_EOL."\033[1mLo que llega a la pantalla\033[0m".PHP_EOL;

    $lista = json_decode($respuesta->getContent(), true)['movimientos'];

    verificar('devuelve renglones', count($lista) > 0, count($lista).' movimientos');

    $renglonBaja = collect($lista)->firstWhere('id', $capturada->id);
    verificar('cada renglón trae el NOMBRE del tipo, no su id', is_string($renglonBaja['tipo'] ?? null));

    $cambioSituacion = collect($renglonBaja['cambios'])->firstWhere('que', 'Situación');
    verificar(
        'los cambios vienen con nombre y no con id',
        $cambioSituacion !== null
            && is_string($cambioSituacion['despues'])
            && ! ctype_digit((string) $cambioSituacion['despues']),
        json_encode($cambioSituacion, JSON_UNESCAPED_UNICODE),
    );

    // 12 · Un cambio inexistente no produce renglón. Son DOS casos y hay que
    // construir los dos: el que no trae ninguno de los dos lados, y el que trae
    // los dos IGUALES —una corrección que no movió la situación—. Con sólo el
    // primero, dejar de comparar los lados no tumbaba nada.
    $sinCambios = collect($lista)->firstWhere('id', $manualA->id);
    verificar(
        'un movimiento sin ninguno de los dos lados no inventa «— → —»',
        $sinCambios !== null && $sinCambios['cambios'] === [],
        json_encode($sinCambios['cambios'] ?? null),
    );

    $mismaSituacion = $registrador->registrar($primera, 'correccion', MovimientoEscolar::ORIGEN_MANUAL, null, [
        'situacion_anterior_id' => $primera->situacion_id,
        'situacion_nueva_id' => $primera->situacion_id,
        'motivo' => 'Se corrigió la fecha, no la situación.',
    ]);

    $renglonIgual = collect(json_decode(
        $controlador->index(peticion([], 'GET', $global), $primera)->getContent(), true
    )['movimientos'])->firstWhere('id', $mismaSituacion->id);

    verificar(
        'y con los dos lados IGUALES tampoco: eso no es un cambio',
        $renglonIgual !== null && collect($renglonIgual['cambios'])->firstWhere('que', 'Situación') === null,
        json_encode($renglonIgual['cambios'] ?? null, JSON_UNESCAPED_UNICODE),
    );

    // 11 · Ordena por fecha efectiva, no por captura.
    $vieja = $registrador->registrar($primera, 'otro', MovimientoEscolar::ORIGEN_MANUAL, null, [
        'fecha_efectiva' => now()->subYears(3)->toDateString(),
        'observaciones' => 'Capturada al final, ocurrió hace tres años.',
    ]);

    $orden = MovimientoEscolar::de($primera->id)->pluck('id')->all();

    verificar(
        'la más antigua queda al final aunque sea la última capturada',
        $orden !== [] && end($orden) === $vieja->id,
        'último='.end($orden).' esperado='.$vieja->id,
    );

    $fechas = MovimientoEscolar::de($primera->id)->pluck('fecha_efectiva')
        ->map(fn ($f) => $f->toDateString())->all();
    $descendente = $fechas;
    rsort($descendente);
    verificar('y la línea de tiempo va de lo más nuevo a lo más viejo', $fechas === $descendente);

    // ── Los catálogos que alimentan el formulario ──────────────────────────
    echo PHP_EOL."\033[1mEl formulario pide lo que el tipo declara\033[0m".PHP_EOL;

    $cat = json_decode($controlador->catalogos(peticion([], 'GET', $captura), $primera)->getContent(), true);

    verificar('los catálogos traen los tipos capturables', count($cat['tipos']) > 0);
    verificar(
        'y ninguno sólo automático',
        collect($cat['tipos'])->pluck('clave')->doesntContain(TipoMovimientoEscolar::ALTA),
    );
    verificar(
        'cada tipo viaja con sus banderas, para que la pantalla dibuje sólo esos campos',
        array_key_exists('pide_grupos', $cat['tipos'][0]) && array_key_exists('pide_motivo', $cat['tipos'][0]),
    );

    $planDeLaPrimera = $primera->oferta?->plan_id;
    verificar(
        'los grupos ofrecidos son del plan y campus de ESA matrícula',
        $planDeLaPrimera === null || collect($cat['grupos'])->isEmpty() || $db->table('grupos')
            ->whereIn('id', collect($cat['grupos'])->pluck('id'))
            ->where(fn ($q) => $q->where('plan_id', '!=', $planDeLaPrimera)->orWhereNull('plan_id'))
            ->count() === 0,
    );

    // ── El permiso existe de verdad ────────────────────────────────────────
    echo PHP_EOL."\033[1mLos permisos están sembrados\033[0m".PHP_EOL;

    foreach (['ver-movimientos-escolares', 'registrar-movimiento-escolar', 'corregir-movimiento-escolar'] as $permiso) {
        verificar(
            "el permiso `{$permiso}` existe en la tabla",
            $db->table('permissions')->where('name', $permiso)->exists(),
        );
        verificar(
            "y `{$permiso}` está declarado en el catálogo",
            App\Support\CatalogoPermisos::existe($permiso),
        );
    }

    echo PHP_EOL."Resultado: ".($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    $db->rollBack();
}

exit($fallidas > 0 ? 1 : 0);
