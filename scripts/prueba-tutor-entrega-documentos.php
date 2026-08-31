<?php

/**
 * El tutor entrega los documentos de su hijo MENOR de edad. Con rollback.
 *
 * Se corre con `php scripts/prueba-tutor-entrega-documentos.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué se caería si se rompe ─────────────────────────
 *  1. La MAYORÍA DE EDAD es un ajuste, no un 18 escrito en el código: mover
 *     `familia.mayoria_de_edad` cambia de verdad quién puede entregar. El mismo
 *     hijo pasa de poderse a no poderse según ese número.
 *  2. Sin fecha de nacimiento se falla CERRADO y con su razón escrita: quien no
 *     puede demostrar que representa a un menor no lo representa.
 *  3. El interruptor de la escuela apaga el acto entero: 404, no 403. Lo que la
 *     escuela no contrató no existe para nadie; el 403 es personal.
 *  4. El VÍNCULO decide sobre QUIÉN: un tutor legítimo de un hijo no puede
 *     entregar por el hijo de otro.
 *  5. Y la pareja (hijo, documento) se vuelve a comprobar: las dos ids viajan
 *     por la URL, así que sin eso se pide el documento de cualquier alumno
 *     poniendo el hijo propio en el primer hueco.
 *  6. El `exists` va acotado al ÁMBITO ALUMNO: el desplegable no es una
 *     defensa, y un documento de aspirante o de tutor no tiene nada que hacer
 *     en el expediente del alumno.
 *  7. Escribe en `documentos_alumno`, la MISMA tabla del alumno, y la auditoría
 *     dice quién lo subió. Con eso el alumno distingue en su portal lo que
 *     subió él de lo que subió su madre.
 *  8. Lo ACEPTADO no se pisa ni se retira desde el portal de la familia: es la
 *     constancia de un trámite que la escuela ya dio por bueno.
 *  9. La pantalla y el controlador leen la MISMA regla: lo que el portal ofrece
 *     es exactamente lo que el servidor acepta.
 *
 * Comprobada mutando cada una de esas reglas.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\DocumentosDelHijoController;
use App\Http\Controllers\ExpedienteAlumnoController;
use App\Http\Controllers\PadreController;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Familia\RepresentacionDelTutor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

/** Una petición con sesión, como la que arma el portal de la familia. */
function peticion(array $datos = [], string $metodo = 'POST', ?Usuario $como = null, array $archivos = []): Request
{
    $p = Request::create('/', $metodo, $datos, [], $archivos);
    $p->headers->set('X-Inertia', 'true');

    if ($como !== null) {
        auth()->setUser($como);
        $p->setUserResolver(fn () => $como);
    }

    return $p;
}

/** Una cuenta propia con el rol de padre de familia. */
function cuentaDe(Persona $persona): Usuario
{
    $sufijo = random_int(100000, 999999);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_tut_'.$sufijo,
        'email' => 'prueba_tut_'.$sufijo.'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', 'padre_familia')->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/**
 * Una persona nueva, para no tocar a nadie del demo.
 *
 * Con su fila en `alumnos` cuando hace falta: `documentos_alumno.persona_id`
 * tiene foránea contra `alumnos`, así que un hijo que no está inscrito en la
 * escuela no puede tener expediente aquí. Se construye el escenario en vez de
 * buscarlo: el demo no tiene un tutor con hijos de tres edades distintas.
 */
function personaNueva(string $apellido, ?string $nacimiento, bool $esAlumno = true): Persona
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => $apellido,
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
        'fecha_nacimiento' => $nacimiento,
    ]);

    if ($esAlumno) {
        DB::connection('tenant')->table('alumnos')->insert([
            'persona_id' => $persona->id,
            'situacion_id' => DB::connection('tenant')->table('situaciones_alumno')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $persona;
}

$db->beginTransaction();

/*
 * Los archivos NO entran en el rollback: se escriben en disco. Se anotan para
 * borrarlos en el `finally`, porque una prueba que deja basura en el disco de
 * la escuela es la misma clase de defecto que una que la deja en la base.
 */
$escritos = [];

try {
    $ajustes = app(Ajustes::class);
    $representacion = app(RepresentacionDelTutor::class);
    $controlador = app(DocumentosDelHijoController::class);

    // ── El ajuste existe y se lee ──────────────────────────────────────────
    echo PHP_EOL."\033[1mLos dos ajustes\033[0m".PHP_EOL;

    verificar(
        'la mayoría de edad está declarada en el catálogo',
        CatalogoAjustes::buscar(CatalogoAjustes::MAYORIA_DE_EDAD) !== null,
    );
    verificar(
        'y el interruptor de la entrega también',
        CatalogoAjustes::buscar(CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS) !== null,
    );
    verificar(
        'la mayoría de edad por omisión son 18 años',
        CatalogoAjustes::buscar(CatalogoAjustes::MAYORIA_DE_EDAD)?->porDefecto === 18,
    );
    verificar(
        'y la entrega nace ENCENDIDA: el caso normal es la escuela donde el papeleo lo lleva el padre',
        CatalogoAjustes::buscar(CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS)?->porDefecto === true,
    );

    // ── El escenario: un tutor y tres hijos ────────────────────────────────
    $tutorPersona = personaNueva('Tutor', '1985-03-10', esAlumno: false);
    $tutor = cuentaDe($tutorPersona);

    $menor = personaNueva('Menor', now()->subYears(12)->toDateString());
    $mayor = personaNueva('Mayor', now()->subYears(22)->toDateString());
    $sinFecha = personaNueva('SinFecha', null);
    $ajeno = personaNueva('Ajeno', now()->subYears(10)->toDateString());

    $parentescoId = $db->table('parentescos')->whereNull('deleted_at')->value('id');

    foreach ([$menor, $mayor, $sinFecha] as $hijo) {
        TutorAlumno::create([
            'tutor_persona_id' => $tutorPersona->id,
            'alumno_persona_id' => $hijo->id,
            'parentesco_id' => $parentescoId,
            'puede_ver_academico' => true,
            'puede_ver_finanzas' => true,
        ]);
    }

    $vinculoMenor = TutorAlumno::where('tutor_persona_id', $tutorPersona->id)
        ->where('alumno_persona_id', $menor->id)->first();
    $vinculoMayor = TutorAlumno::where('tutor_persona_id', $tutorPersona->id)
        ->where('alumno_persona_id', $mayor->id)->first();
    $vinculoSinFecha = TutorAlumno::where('tutor_persona_id', $tutorPersona->id)
        ->where('alumno_persona_id', $sinFecha->id)->first();

    // ── La regla, en un solo sitio ─────────────────────────────────────────
    echo PHP_EOL."\033[1mQuién puede entregar por quién\033[0m".PHP_EOL;

    verificar('por el hijo MENOR sí puede', $representacion->motivoParaNoEntregarDocumentos($vinculoMenor, $menor) === null);

    $motivoMayor = $representacion->motivoParaNoEntregarDocumentos($vinculoMayor, $mayor);
    verificar('por el hijo MAYOR no', $motivoMayor !== null, (string) $motivoMayor);
    verificar(
        'y el motivo dice la edad, no un «no se puede» pelado',
        $motivoMayor !== null && str_contains($motivoMayor, '22'),
        (string) $motivoMayor,
    );

    $motivoSinFecha = $representacion->motivoParaNoEntregarDocumentos($vinculoSinFecha, $sinFecha);
    verificar(
        'sin fecha de nacimiento falla CERRADO',
        $motivoSinFecha !== null,
        (string) $motivoSinFecha,
    );
    verificar(
        'y dice qué falta, para que la escuela lo capture',
        $motivoSinFecha !== null && str_contains(mb_strtolower($motivoSinFecha), 'fecha de nacimiento'),
    );

    verificar(
        'sin vínculo, tampoco',
        $representacion->motivoParaNoEntregarDocumentos(null, $ajeno) !== null,
    );

    // ── La edad es CONFIGURABLE, y es la razón de que exista el ajuste ─────
    echo PHP_EOL."\033[1mLa mayoría de edad la fija la escuela\033[0m".PHP_EOL;

    $deVeintiuno = personaNueva('Veinte', now()->subYears(20)->toDateString());
    TutorAlumno::create([
        'tutor_persona_id' => $tutorPersona->id,
        'alumno_persona_id' => $deVeintiuno->id,
        'parentesco_id' => $parentescoId,
        'puede_ver_academico' => true,
        'puede_ver_finanzas' => true,
    ]);
    $vinculoVeinte = TutorAlumno::where('tutor_persona_id', $tutorPersona->id)
        ->where('alumno_persona_id', $deVeintiuno->id)->first();

    $ajustes->guardar([CatalogoAjustes::MAYORIA_DE_EDAD => 18]);
    $ajustes->olvidar();
    verificar(
        'con la mayoría en 18, por el de 20 años NO se puede',
        app(RepresentacionDelTutor::class)->motivoParaNoEntregarDocumentos($vinculoVeinte, $deVeintiuno) !== null,
    );

    $ajustes->guardar([CatalogoAjustes::MAYORIA_DE_EDAD => 21]);
    $ajustes->olvidar();
    verificar(
        'y con la mayoría en 21, por el MISMO hijo sí',
        app(RepresentacionDelTutor::class)->motivoParaNoEntregarDocumentos($vinculoVeinte, $deVeintiuno) === null,
        'mayoría='.app(RepresentacionDelTutor::class)->mayoriaDeEdad(),
    );

    $ajustes->guardar([CatalogoAjustes::MAYORIA_DE_EDAD => 18]);
    $ajustes->olvidar();

    // ── El interruptor apaga el acto entero, con 404 ───────────────────────
    echo PHP_EOL."\033[1mEl interruptor de la escuela\033[0m".PHP_EOL;

    $tipo = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)->first();
    verificar('la escuela pide algún documento a sus alumnos', $tipo !== null);

    $archivo = fn () => UploadedFile::fake()->create('acta.pdf', 20, 'application/pdf');

    $ajustes->guardar([CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS => false]);
    $ajustes->olvidar();

    $estadoApagado = null;
    try {
        app(DocumentosDelHijoController::class)->subir(
            peticion(['documento_id' => $tipo->id], 'POST', $tutor, ['archivo' => $archivo()]),
            $menor,
        );
    } catch (NotFoundHttpException $e) {
        $estadoApagado = 404;
    } catch (HttpException $e) {
        $estadoApagado = $e->getStatusCode();
    }
    verificar(
        'apagado, la dirección responde 404 y no 403',
        $estadoApagado === 404,
        'estado='.var_export($estadoApagado, true),
    );

    $ajustes->guardar([CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS => true]);
    $ajustes->olvidar();
    $controlador = app(DocumentosDelHijoController::class);

    // ── La entrega de verdad ───────────────────────────────────────────────
    echo PHP_EOL."\033[1mEntregar por el hijo menor\033[0m".PHP_EOL;

    $controlador->subir(
        peticion(['documento_id' => $tipo->id, 'descripcion' => 'Copia certificada'], 'POST', $tutor, ['archivo' => $archivo()]),
        $menor,
    );

    $guardado = DocumentoAlumno::query()->where('persona_id', $menor->id)->first();
    if ($guardado !== null) {
        $escritos[] = $guardado->url;
    }

    verificar('el documento queda en `documentos_alumno`, no en una tabla del tutor', $guardado !== null);
    verificar('colgando del HIJO', (int) $guardado?->persona_id === $menor->id);
    verificar(
        'la auditoría dice que lo subió el TUTOR',
        (int) $guardado?->created_by === $tutor->id,
        'created_by='.var_export($guardado?->created_by, true),
    );
    verificar('y nace pendiente de revisión: entregar no es acreditar', $guardado?->estado?->clave === 'pendiente');
    verificar('el archivo está en el disco', $guardado !== null && Storage::disk('local')->exists($guardado->url));

    /*
     * 6 · El ámbito.
     *
     * El documento de otro ámbito se CONSTRUYE, no se busca: el demo no tiene
     * uno que sea sólo de tutor, y una prueba que se salta la comprobación
     * cuando no encuentra el caso se apaga sola el día que cambian los datos.
     */
    $deOtroAmbito = DocumentoRequerido::create([
        'nombre' => 'Sólo del tutor '.random_int(1000, 9999),
        'obligatorio' => false,
    ]);
    $db->table('documento_ambitos')->insert([
        'documento_id' => $deOtroAmbito->id,
        'ambito' => DocumentoRequerido::AMBITO_TUTOR,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rechazado = false;
    try {
        $controlador->subir(
            peticion(['documento_id' => $deOtroAmbito->id], 'POST', $tutor, ['archivo' => $archivo()]),
            $menor,
        );
    } catch (ValidationException $e) {
        $rechazado = array_key_exists('documento_id', $e->errors());
    }
    verificar('un documento de OTRO ámbito se rechaza en la validación', $rechazado);

    // ── Por el hijo de otro, no ────────────────────────────────────────────
    echo PHP_EOL."\033[1mEl vínculo decide sobre quién\033[0m".PHP_EOL;

    $estadoAjeno = null;
    try {
        $controlador->subir(
            peticion(['documento_id' => $tipo->id], 'POST', $tutor, ['archivo' => $archivo()]),
            $ajeno,
        );
    } catch (HttpException $e) {
        $estadoAjeno = $e->getStatusCode();
    }
    verificar('por un alumno que no es su hijo: 403', $estadoAjeno === 403, 'estado='.var_export($estadoAjeno, true));

    $estadoMayor = null;
    try {
        $controlador->subir(
            peticion(['documento_id' => $tipo->id], 'POST', $tutor, ['archivo' => $archivo()]),
            $mayor,
        );
    } catch (HttpException $e) {
        $estadoMayor = $e->getStatusCode();
    }
    verificar('por su hijo YA MAYOR: 403', $estadoMayor === 403, 'estado='.var_export($estadoMayor, true));

    // 5 · El documento tiene que ser de ESE hijo.
    $delAjeno = DocumentoAlumno::create([
        'persona_id' => $ajeno->id,
        'documento_id' => $tipo->id,
        'url' => 'alumnos/'.$ajeno->id.'/inventado.pdf',
        'estado_documento_id' => $guardado->estado_documento_id,
    ]);

    $estadoCruzado = null;
    try {
        $controlador->descargar(peticion([], 'GET', $tutor), $menor, $delAjeno);
    } catch (NotFoundHttpException $e) {
        $estadoCruzado = 404;
    } catch (HttpException $e) {
        $estadoCruzado = $e->getStatusCode();
    }
    verificar(
        'con su hijo en la URL y el documento de OTRO alumno: 403',
        $estadoCruzado === 403,
        'estado='.var_export($estadoCruzado, true),
    );

    // ── Lo aceptado no se toca ─────────────────────────────────────────────
    echo PHP_EOL."\033[1mLo que la escuela ya dio por bueno\033[0m".PHP_EOL;

    $aceptado = $db->table('estados_documento')->where('clave', 'aceptado')->value('id');
    verificar('la escuela tiene el estado «aceptado»', $aceptado !== null);

    $guardado->update(['estado_documento_id' => $aceptado]);

    $respuesta = $controlador->subir(
        peticion(['documento_id' => $tipo->id], 'POST', $tutor, ['archivo' => $archivo()]),
        $menor,
    );
    verificar(
        're-subir sobre un documento ACEPTADO no lo pisa',
        $guardado->fresh()->estado_documento_id === $aceptado,
    );
    verificar(
        'y lo dice con un aviso, no en silencio',
        $respuesta->getSession()?->get('error') !== null
            || str_contains(json_encode($respuesta->getSession()?->all() ?? []), 'aceptado'),
    );

    $controlador->eliminar(peticion([], 'DELETE', $tutor), $menor, $guardado->fresh());
    verificar(
        'y tampoco se puede retirar desde el portal de la familia',
        DocumentoAlumno::query()->whereKey($guardado->id)->exists(),
    );

    // Se devuelve a pendiente para probar el retiro normal.
    $guardado->update(['estado_documento_id' => $db->table('estados_documento')->where('clave', 'pendiente')->value('id')]);
    $controlador->eliminar(peticion([], 'DELETE', $tutor), $menor, $guardado->fresh());
    verificar(
        'lo pendiente sí se retira',
        ! DocumentoAlumno::query()->whereKey($guardado->id)->exists(),
    );

    // ── La pantalla dice lo mismo que el servidor ──────────────────────────
    echo PHP_EOL."\033[1mLa pantalla y el controlador leen la misma regla\033[0m".PHP_EOL;

    /*
     * Se invoca al CONTROLADOR y se leen sus props, como hace `prueba-listados`:
     * lo que se quiere comprobar es que la pantalla reciba exactamente lo que
     * el servidor va a aceptar, y reimplementar la consulta no lo probaría.
     */
    $props = function (Persona $hijo) use ($tutor) {
        $peticion = peticion([], 'GET', $tutor);
        $peticion->headers->set('X-Inertia-Version', '');

        $contenido = app(PadreController::class)->hijo($peticion, $hijo)
            ->toResponse($peticion)->getContent();

        return json_decode($contenido, true)['props']['entregaDocumentos'] ?? null;
    };

    $bloqueMenor = $props($menor);
    verificar('al hijo menor se le dibuja la sección', is_array($bloqueMenor) && $bloqueMenor['motivo'] === null);
    verificar(
        'con los tipos del ámbito ALUMNO',
        is_array($bloqueMenor) && count($bloqueMenor['tipos']) > 0,
    );

    /*
     * Al hijo mayor se le siembra un documento a propósito.
     *
     * Sin él, «no se le enseñan sus papeles» se cumple porque no tiene ninguno,
     * y quitar la salvaguarda no tumbaba nada. Lo destapó una mutación.
     */
    DocumentoAlumno::create([
        'persona_id' => $mayor->id,
        'documento_id' => $tipo->id,
        'url' => 'alumnos/'.$mayor->id.'/suyo.pdf',
        'estado_documento_id' => $db->table('estados_documento')->where('clave', 'pendiente')->value('id'),
    ]);

    $bloqueMayor = $props($mayor);
    verificar(
        'al mayor se le dice el motivo, en vez de esfumarse la sección',
        is_array($bloqueMayor) && $bloqueMayor['motivo'] !== null,
    );
    verificar(
        'y no se le enseñan sus papeles: consultarlos es representarlo igual que subirlos',
        is_array($bloqueMayor) && $bloqueMayor['documentos'] === [],
    );

    $ajustes->guardar([CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS => false]);
    $ajustes->olvidar();
    verificar(
        'con el interruptor apagado la sección ni se anuncia',
        $props($menor) === null,
    );
    $ajustes->guardar([CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS => true]);
    $ajustes->olvidar();

    /*
     * ── Y el alumno tiene que ENTERARSE ────────────────────────────────────
     *
     * En su propio expediente aparece un archivo que él no subió. Sin decírselo
     * se lee como propio, y no sabría si lo cargó y no se acuerda o si alguien
     * más lo hizo por él.
     */
    echo PHP_EOL."[1mEl alumno ve quién lo entregó[0m".PHP_EOL;

    /*
     * Se revive el que el tutor subió de verdad —arriba se retiró para probar
     * el borrado— en vez de insertar uno a mano: así el `created_by` que se
     * comprueba es el que escribió el controlador, no uno que puso la prueba.
     */
    $delTutor = DocumentoAlumno::withTrashed()->findOrFail($guardado->id);
    $delTutor->restore();

    $pendienteId = $db->table('estados_documento')->where('clave', 'pendiente')->value('id');

    $otroTipo = DocumentoRequerido::create(['nombre' => 'Del alumno '.random_int(1000, 9999), 'obligatorio' => false]);
    $db->table('documento_ambitos')->insert([
        'documento_id' => $otroTipo->id,
        'ambito' => DocumentoRequerido::AMBITO_ALUMNO,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /*
     * La sesión se cambia al HIJO antes de crear el suyo: `TieneAuditoria`
     * escribe `created_by` desde la sesión y pisa lo que se le pase a mano. Sin
     * esto, el documento «del alumno» quedaba con el id del tutor y la
     * comprobación de abajo medía otra cosa.
     */
    $cuentaDelHijo = cuentaDe($menor);
    auth()->setUser($cuentaDelHijo);

    $suyo = DocumentoAlumno::create([
        'persona_id' => $menor->id,
        'documento_id' => $otroTipo->id,
        'url' => 'alumnos/'.$menor->id.'/subido-por-el-alumno.pdf',
        'estado_documento_id' => $pendienteId,
        'created_by' => $cuentaDelHijo->id,
    ]);

    $peticionHijo = peticion([], 'GET', $cuentaDelHijo);
    $peticionHijo->headers->set('X-Inertia-Version', '');
    $suExpediente = json_decode(
        app(ExpedienteAlumnoController::class)->show($peticionHijo)->toResponse($peticionHijo)->getContent(),
        true,
    )['props']['documentos'];

    $renglonAjeno = collect($suExpediente)->firstWhere('id', $delTutor->id);
    $renglonPropio = collect($suExpediente)->firstWhere('id', $suyo->id);

    verificar(
        'el documento que subió su tutor viene con el nombre de quien lo entregó',
        ($renglonAjeno['entregado_por'] ?? null) === $tutorPersona->nombreCompleto(),
        var_export($renglonAjeno['entregado_por'] ?? null, true),
    );
    /*
     * Con `array_key_exists` y no con `?? 'x'`.
     *
     * `($fila['entregado_por'] ?? 'x') === null` es FALSA pase lo que pase: el
     * coalescente reemplaza justamente el null que se quiere ver. Es la tercera
     * vez que este proyecto se cobra ese giro, y esta vez lo escribí yo.
     */
    verificar(
        'y el que subió él mismo NO lo lleva: sería ruido en todos los renglones',
        is_array($renglonPropio)
            && array_key_exists('entregado_por', $renglonPropio)
            && $renglonPropio['entregado_por'] === null,
        json_encode($renglonPropio['entregado_por'] ?? 'sin renglón'),
    );

    echo PHP_EOL."Resultado: ".($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    $db->rollBack();

    // Los archivos no los deshace la transacción.
    foreach (array_filter($escritos) as $ruta) {
        Storage::disk('local')->delete($ruta);
    }
}

exit($fallidas > 0 ? 1 : 0);
