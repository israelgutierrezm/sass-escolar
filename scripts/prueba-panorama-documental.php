<?php

/**
 * Control de documentación: cuántos tienen cada papel y a cuántos les falta.
 * Contra la base real del demo, con rollback.
 *
 * Se corre con `php scripts/prueba-panorama-documental.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué se caería si se rompe ─────────────────────────
 *  1. «Faltan» se cuenta contra un UNIVERSO. Sin denominador la cifra no
 *     significa nada, y es la mitad de lo que se viene a preguntar.
 *  2. El universo del alumno es por PERSONA, no por matrícula: quien estudia
 *     dos programas entrega UN acta de nacimiento. Contarlo dos veces infla el
 *     «faltan» justo en las escuelas con multiprograma.
 *  3. Sólo los ACTIVOS por omisión, y se puede quitar: a quien se dio de baja
 *     hace tres años no se le va a pedir el comprobante.
 *  4. ENTREGADO no es ACEPTADO ni es VÁLIDO: cinco cifras distintas, no una.
 *  5. Los estados se cuentan por CLAVE y no por id: `estados_documento` es
 *     catálogo de cada escuela.
 *  6. El detalle de «falta» sale del `leftJoin`: los que NO tienen fila son
 *     justo los que interesan, y con un join normal desaparecerían.
 *  7. El alcance por campus se CRUZA con el filtro: un rol acotado que escriba
 *     otro campus en la URL sigue viendo el suyo.
 *  8. Cada ámbito mira SU tabla y SU universo; el del tutor va sin campus.
 *  9. Un ámbito desconocido no devuelve la escuela entera: devuelve nada.
 *
 * Comprobada mutando cada una de esas reglas.
 */

use App\Http\Controllers\PanoramaDocumentalController;
use App\Models\Academico\Campus;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\DocumentoTutor;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Expedientes\PanoramaDocumental;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

/** Un usuario propio con rol propio, opcionalmente acotado a un campus. */
function usuarioCon(array $permisos, ?int $campusId = null): Usuario
{
    $sufijo = random_int(100000, 999999);

    $rol = Rol::create([
        'name' => 'prueba_pan_'.$sufijo,
        'nombre' => 'Prueba panorama '.$sufijo,
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rol->syncPermissions($permisos);

    $persona = Persona::create([
        'nombre' => 'Prueba', 'primer_apellido' => 'Panorama',
        'segundo_apellido' => (string) $sufijo, 'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_pan_'.$sufijo,
        'email' => 'prueba_pan_'.$sufijo.'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $rol->id, 'activo' => true, 'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Las props de la pantalla, invocando al CONTROLADOR como hace la ruta. */
function props(Usuario $como, array $query = []): array
{
    $peticion = Request::create('/documentacion', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    auth()->setUser($como);
    $peticion->setUserResolver(fn () => $como);

    return json_decode(
        app(PanoramaDocumentalController::class)->index($peticion)->toResponse($peticion)->getContent(),
        true,
    )['props'];
}

$db->beginTransaction();

try {
    $panorama = app(PanoramaDocumental::class);

    $pendiente = EstadoDocumento::query()->where('clave', 'pendiente')->value('id');
    $aceptado = EstadoDocumento::query()->where('clave', 'aceptado')->value('id');
    $rechazado = EstadoDocumento::query()->where('clave', 'rechazado')->value('id');

    $tipo = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)->first();
    verificar('la escuela pide algún documento a sus alumnos', $tipo !== null);

    /*
     * Se PARTE DE CERO, dentro de la transacción.
     *
     * Lo que se prueba aquí es aritmética —cuántos tienen, cuántos faltan— y
     * eso sólo se puede afirmar sabiendo qué hay. Sin vaciar, la suite pasaba
     * corriéndola sola y se caía en cuanto el demo tenía documentos sembrados:
     * es la lección que este proyecto ya se cobró dos veces —una prueba que
     * mide contra cero no prueba nada el día que alguien mete datos—. El
     * `rollBack` del final lo devuelve todo.
     */
    $db->table('documentos_alumno')->delete();
    $db->table('documentos_tutor')->delete();

    // ── 1 y 2 · El universo ────────────────────────────────────────────────
    echo PHP_EOL."\033[1mEl universo: contra qué se mide\033[0m".PHP_EOL;

    $personasActivas = (int) $db->table('matricula_oferta')
        ->whereNull('deleted_at')->where('estatus', 'activo')
        ->distinct()->count('persona_id');

    $matriculasActivas = (int) $db->table('matricula_oferta')
        ->whereNull('deleted_at')->where('estatus', 'activo')->count();

    $base = $panorama->resumen(DocumentoRequerido::AMBITO_ALUMNO);

    verificar(
        'el universo del alumno son PERSONAS, no matrículas',
        $base['total'] === $personasActivas,
        'servicio='.$base['total'].' personas='.$personasActivas.' matrículas='.$matriculasActivas,
    );
    verificar(
        'y en esta escuela las dos cifras SÍ se distinguen, o no se probaría nada',
        $matriculasActivas > $personasActivas,
        $matriculasActivas.' matrículas para '.$personasActivas.' personas',
    );

    $suyo = collect($base['documentos'])->firstWhere('id', $tipo->id);
    verificar('sin entregas, faltan todos', $suyo['faltan'] === $personasActivas && $suyo['entregados'] === 0);

    /*
     * ── 3 · Sólo activos, y se puede quitar ────────────────────────────────
     *
     * La baja se CONSTRUYE: en el demo las 18 matrículas están activas, así que
     * sin darle de baja a alguien las dos cifras salen iguales y apagar el
     * interruptor no se comprobaría. Se elige a quien tenga UNA sola matrícula,
     * para que darla de baja lo saque de verdad del universo —quien estudia dos
     * programas seguiría contando por el otro—.
     */
    $unaSola = MatriculaOferta::query()->whereNull('deleted_at')->where('estatus', 'activo')
        ->get()->groupBy('persona_id')->first(fn ($m) => $m->count() === 1)?->first();

    verificar('hay un alumno de un solo programa al que dar de baja', $unaSola !== null);

    $unaSola->update(['estatus' => 'baja']);

    $soloActivos = $panorama->resumen(DocumentoRequerido::AMBITO_ALUMNO);
    $todas = $panorama->resumen(DocumentoRequerido::AMBITO_ALUMNO, ['solo_activos' => false]);

    verificar(
        'la baja sale del universo por omisión',
        $soloActivos['total'] === $personasActivas - 1,
        'ahora='.$soloActivos['total'].' antes='.$personasActivas,
    );
    verificar(
        'y con «sólo activos» apagado vuelve a entrar',
        $todas['total'] === $personasActivas,
        'todas='.$todas['total'].' activas='.$soloActivos['total'],
    );

    $unaSola->update(['estatus' => 'activo']);

    // ── 4 y 5 · Las cinco cifras ───────────────────────────────────────────
    echo PHP_EOL."\033[1mEntregado no es aceptado ni es válido\033[0m".PHP_EOL;

    /*
     * El escenario se CONSTRUYE: el demo no tiene un solo documento de alumno,
     * así que sin sembrarlo todas las cifras serían cero y cualquier mutación
     * de la aritmética pasaría sin tumbar nada.
     */
    $activas = MatriculaOferta::query()->whereNull('deleted_at')->where('estatus', 'activo')
        ->get()->unique('persona_id')->values();

    verificar('hay al menos cuatro alumnos con los que armar el caso', $activas->count() >= 4);

    $sembrar = function (int $personaId, ?int $estado, ?string $vigencia = null) use ($tipo) {
        DocumentoAlumno::create([
            'persona_id' => $personaId,
            'documento_id' => $tipo->id,
            'url' => 'alumnos/'.$personaId.'/panorama.pdf',
            'estado_documento_id' => $estado,
            'vigencia' => $vigencia,
        ]);
    };

    $sembrar($activas[0]->persona_id, $aceptado);
    $sembrar($activas[1]->persona_id, $pendiente);
    $sembrar($activas[2]->persona_id, $rechazado);
    // Aceptado pero VENCIDO: tiene el papel y el papel ya no vale.
    $sembrar($activas[3]->persona_id, $aceptado, now()->subMonth()->toDateString());

    $r = collect($panorama->resumen(DocumentoRequerido::AMBITO_ALUMNO)['documentos'])->firstWhere('id', $tipo->id);

    verificar('entregados cuenta los cuatro', $r['entregados'] === 4, json_encode($r));
    verificar('aceptados sólo los dos aceptados', $r['aceptados'] === 2);
    verificar('pendientes sólo el pendiente', $r['pendientes'] === 1);
    verificar('rechazados sólo el rechazado', $r['rechazados'] === 1);
    verificar('vencidos sólo el que caducó, aunque esté aceptado', $r['vencidos'] === 1);
    verificar('y faltan los demás', $r['faltan'] === $personasActivas - 4);

    /*
     * ── 5 · Por CLAVE y no por id ──────────────────────────────────────────
     *
     * `estados_documento` es catálogo de cada escuela: en el demo «aceptado» es
     * el id 2, y en otra puede ser el 47. Para probarlo de verdad hay que
     * separar la clave del id, y por eso se REEMPLAZA el estado por otro con la
     * misma clave y un id nuevo: leyendo por clave las cuentas siguen saliendo,
     * y con un id cableado se irían a cero.
     *
     * El único de `clave` obliga a liberar el nombre antes, y el borrado lógico
     * NO lo libera —el índice no mira `deleted_at`—, así que el viejo se
     * renombra. Todo dentro de la transacción que se deshace al final.
     */
    $db->table('estados_documento')->where('id', $aceptado)->update(['clave' => 'aceptado_viejo']);

    $nuevoAceptado = (int) $db->table('estados_documento')->insertGetId([
        'clave' => 'aceptado',
        'nombre' => 'Aceptado',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    verificar(
        'el estado nuevo NO tiene el id que tenía antes',
        $nuevoAceptado !== $aceptado,
        'antes='.$aceptado.' ahora='.$nuevoAceptado,
    );

    $db->table('documentos_alumno')
        ->where('documento_id', $tipo->id)
        ->where('estado_documento_id', $aceptado)
        ->update(['estado_documento_id' => $nuevoAceptado]);

    $conOtroId = collect($panorama->resumen(DocumentoRequerido::AMBITO_ALUMNO)['documentos'])
        ->firstWhere('id', $tipo->id);

    verificar(
        'los aceptados se siguen contando con el id nuevo: se lee la CLAVE',
        $conOtroId['aceptados'] === 2,
        'aceptados='.$conOtroId['aceptados'],
    );

    // Y se deja como estaba para lo que sigue.
    $db->table('documentos_alumno')
        ->where('documento_id', $tipo->id)
        ->where('estado_documento_id', $nuevoAceptado)
        ->update(['estado_documento_id' => $aceptado]);
    $db->table('estados_documento')->where('id', $nuevoAceptado)->delete();
    $db->table('estados_documento')->where('id', $aceptado)->update(['clave' => 'aceptado']);

    // ── 6 · El detalle ─────────────────────────────────────────────────────
    echo PHP_EOL."\033[1mQuiénes son\033[0m".PHP_EOL;

    $faltan = $panorama->personas(DocumentoRequerido::AMBITO_ALUMNO, $tipo->id, 'falta');
    $pendientes = $panorama->personas(DocumentoRequerido::AMBITO_ALUMNO, $tipo->id, 'pendiente');
    $vencidos = $panorama->personas(DocumentoRequerido::AMBITO_ALUMNO, $tipo->id, 'vencido');

    verificar('el detalle de «falta» trae a los que NO tienen fila', count($faltan) === $r['faltan'], count($faltan).' de '.$r['faltan']);
    verificar(
        'y ninguno de los que sí entregaron',
        collect($faltan)->pluck('id')->intersect($activas->take(4)->pluck('persona_id'))->isEmpty(),
    );
    verificar('el de «pendiente» trae al pendiente', count($pendientes) === 1 && $pendientes[0]['id'] === $activas[1]->persona_id);
    verificar('el de «vencido» trae al vencido', count($vencidos) === 1 && $vencidos[0]['id'] === $activas[3]->persona_id);
    verificar(
        'cada renglón trae con qué abrir su expediente',
        $pendientes[0]['enlace_id'] > 0 && $pendientes[0]['nombre'] !== '',
        json_encode($pendientes[0], JSON_UNESCAPED_UNICODE),
    );

    // ── 7 · El alcance por campus ──────────────────────────────────────────
    echo PHP_EOL."\033[1mEl alcance por campus se cruza con el filtro\033[0m".PHP_EOL;

    $campusDelPrimero = MatriculaOferta::with('oferta')->find($activas[0]->id)?->oferta?->campus_id;
    $otroCampus = Campus::query()->where('id', '!=', $campusDelPrimero)->value('id');

    verificar('la escuela tiene más de un campus', $otroCampus !== null);

    $global = usuarioCon(['validar-expediente']);
    $acotado = usuarioCon(['validar-expediente'], (int) $otroCampus);

    $vistaGlobal = props($global);
    $vistaAcotada = props($acotado);

    verificar(
        'el acotado ve un universo más chico que el global',
        $vistaAcotada['total'] < $vistaGlobal['total'],
        'acotado='.$vistaAcotada['total'].' global='.$vistaGlobal['total'],
    );

    // Y escribir OTRO campus en la URL no lo saca de su alcance.
    $forzado = props($acotado, ['campus_id' => (string) $campusDelPrimero]);

    verificar(
        'pidiendo un campus ajeno por la URL no ve nada de ahí',
        $forzado['total'] === 0,
        'total='.$forzado['total'],
    );

    $delSuyo = props($global, ['campus_id' => (string) $campusDelPrimero]);
    verificar(
        'y el global sí puede filtrar por ese campus',
        $delSuyo['total'] > 0 && $delSuyo['total'] <= $vistaGlobal['total'],
        'total='.$delSuyo['total'],
    );

    // ── 8 · Los cuatro ámbitos ─────────────────────────────────────────────
    echo PHP_EOL."\033[1mCada ámbito, su tabla y su universo\033[0m".PHP_EOL;

    $tipoTutor = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_TUTOR)->first();
    $unTutor = TutorAlumno::query()->whereNull('deleted_at')->value('tutor_persona_id');

    DocumentoTutor::create([
        'persona_id' => $unTutor,
        'documento_id' => $tipoTutor->id,
        'url' => 'tutores/'.$unTutor.'/panorama.pdf',
        'estado_documento_id' => $pendiente,
    ]);

    $tutores = $panorama->resumen(DocumentoRequerido::AMBITO_TUTOR);
    $delTutor = collect($tutores['documentos'])->firstWhere('id', $tipoTutor->id);

    verificar(
        'el ámbito tutor mira `documentos_tutor`, no la del alumno',
        $delTutor !== null && $delTutor['pendientes'] === 1,
        json_encode($delTutor),
    );
    verificar(
        'su universo son los tutores vinculados',
        $tutores['total'] === (int) $db->table('tutores_alumno')->whereNull('deleted_at')->distinct()->count('tutor_persona_id'),
        'total='.$tutores['total'],
    );
    verificar(
        'y NO se acota por campus: un tutor puede tener hijos en dos',
        $panorama->resumen(DocumentoRequerido::AMBITO_TUTOR, ['campus' => [(int) $otroCampus]])['total'] === $tutores['total'],
    );

    foreach ([DocumentoRequerido::AMBITO_ASPIRANTE, DocumentoRequerido::AMBITO_DOCENTE] as $otro) {
        $res = $panorama->resumen($otro);
        verificar(
            "el ámbito {$otro} tiene universo y documentos propios",
            $res['total'] > 0 && $res['documentos'] !== [],
            'total='.$res['total'].' docs='.count($res['documentos']),
        );

        /*
         * Y su DETALLE, que es el camino de verdad arriesgado: la llave del
         * titular no siempre es `persona_id` —el aspirante cuelga de su propio
         * id— y el `leftJoin` se arma con la del ámbito. Con la llave
         * equivocada la consulta revienta o devuelve a cualquiera.
         */
        $primero = $res['documentos'][0];
        $quienes = $panorama->personas($otro, $primero['id'], 'falta');

        verificar(
            "y el detalle de {$otro} se ata con SU llave, no con la del alumno",
            count($quienes) === $primero['faltan'] && ($quienes[0]['nombre'] ?? '') !== '',
            count($quienes).' de '.$primero['faltan'],
        );
    }

    // ── 9 · Un ámbito inventado no abre nada ───────────────────────────────
    $inventado = $panorama->resumen('inventado');
    verificar(
        'un ámbito desconocido devuelve nada, no la escuela entera',
        $inventado['total'] === 0 && $inventado['documentos'] === [],
    );

    // ── La pantalla ────────────────────────────────────────────────────────
    echo PHP_EOL."\033[1mLo que llega a la pantalla\033[0m".PHP_EOL;

    $vista = props($global, ['ambito' => 'alumno', 'documento_id' => (string) $tipo->id, 'estado' => 'pendiente']);

    verificar('trae el resumen', count($vista['documentos']) > 0);
    verificar('el documento en foco', ($vista['enFoco']['id'] ?? null) === $tipo->id);
    verificar('y su detalle, sólo cuando hay uno elegido', is_array($vista['personas']) && count($vista['personas']) === 1);

    $sinFoco = props($global, ['ambito' => 'alumno']);
    verificar(
        'sin documento elegido NO se paga la consulta del detalle',
        $sinFoco['personas'] === null,
        var_export($sinFoco['personas'], true),
    );

    $conBasura = props($global, ['ambito' => 'alumno', 'documento_id' => '99999999']);
    verificar(
        'un documento que no es de ese ámbito no abre un detalle vacío sin explicación',
        $conBasura['enFoco'] === null && $conBasura['personas'] === null,
    );

    verificar(
        'los enlaces a las fichas los pone el servidor, no la pantalla',
        ($vista['base']['alumno'] ?? null) === '/escolar/alumnos'
            && ($vista['base']['tutor'] ?? null) === '/padres-tutores',
        json_encode($vista['base'] ?? null),
    );

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    $db->rollBack();
}

exit($fallidas > 0 ? 1 : 0);
