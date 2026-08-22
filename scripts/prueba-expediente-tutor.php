<?php

/**
 * El expediente del TUTOR FAMILIAR: sube lo suyo, no valida, no toca lo ajeno.
 * Con rollback.
 *
 * Se corre con `php scripts/prueba-expediente-tutor.php` desde la raíz.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * `DocumentoRequerido::AMBITO_TUTOR` estaba en el catálogo desde el principio y
 * la escuela demo YA lo usa —«Identificación oficial», obligatoria—, pero el
 * portal de la familia sólo mostraba a los hijos: no había dónde entregarla.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 * Tres cosas, y las tres son de acceso:
 *  1. Que sólo se ofrezcan los documentos DEL ÁMBITO TUTOR. Sin eso, el portal
 *     le pediría al padre el certificado de bachillerato de su hijo.
 *  2. Que la validación no acepte un `documento_id` de otro ámbito aunque no
 *     esté en la lista: el desplegable no es una defensa.
 *  3. Que no se lea ni se borre el documento de otro tutor — la ruta lleva id.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\ExpedienteTutorController;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Identidad\DocumentoTutor;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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

/** Una petición con ese usuario, como la que arma el middleware. */
function peticionDe(Usuario $usuario, string $metodo = 'GET', array $datos = [], array $archivos = []): Request
{
    $peticion = Request::create('/mis-hijos/expediente', $metodo, $datos, [], $archivos);
    $peticion->setUserResolver(fn () => $usuario);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

$subidos = [];

try {
    $control = app(ExpedienteTutorController::class);

    // ── Un tutor con cuenta ──
    $vinculo = TutorAlumno::query()->first();

    if ($vinculo === null) {
        echo 'Esta escuela no tiene ningún vínculo tutor-alumno; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $tutor = Usuario::query()->where('persona_id', $vinculo->tutor_persona_id)->firstOrFail();

    echo PHP_EOL.'1. Sólo se le piden los documentos de SU ámbito'.PHP_EOL;

    $props = $control->show(peticionDe($tutor))->toResponse(peticionDe($tutor))->getData(true)['props'];

    $ofrecidos = collect($props['tiposDocumento'])->pluck('id');
    $delTutor = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_TUTOR)->pluck('id');

    verificar('la lista es exactamente la del ámbito tutor',
        $ofrecidos->sort()->values()->all() === $delTutor->sort()->values()->all(),
        'ofrecidos: '.$ofrecidos->implode(',').' / del ámbito: '.$delTutor->implode(','));
    verificar('y no está vacía —si no, esto no probaría nada—', $ofrecidos->isNotEmpty());

    $ajeno = DocumentoRequerido::query()
        ->whereNotIn('id', $delTutor)
        ->value('id');

    verificar('hay documentos de otros ámbitos con los que probar', $ajeno !== null);

    echo PHP_EOL.'2. Sube su documento y queda PENDIENTE de revisión'.PHP_EOL;

    $tipo = (int) $delTutor->first();
    $archivo = UploadedFile::fake()->create('identificacion.pdf', 40, 'application/pdf');

    $control->subir(peticionDe($tutor, 'POST', ['documento_id' => $tipo], ['archivo' => $archivo]));

    $doc = DocumentoTutor::query()
        ->where('persona_id', $tutor->persona_id)
        ->where('documento_id', $tipo)
        ->first();

    $subidos[] = $doc?->url;

    verificar('quedó guardado', $doc !== null);
    verificar('el archivo existe en el disco privado',
        $doc !== null && Storage::disk('local')->exists($doc->url), (string) $doc?->url);
    verificar('con estado pendiente: sube, no valida',
        $doc?->estado?->clave === 'pendiente', (string) $doc?->estado?->clave);

    echo PHP_EOL.'3. Un documento de OTRO ámbito se rechaza en el servidor'.PHP_EOL;

    $rechazado = false;

    try {
        $control->subir(peticionDe(
            $tutor, 'POST', ['documento_id' => $ajeno],
            ['archivo' => UploadedFile::fake()->create('otro.pdf', 10, 'application/pdf')],
        ));
    } catch (ValidationException) {
        $rechazado = true;
    }

    verificar('la validación lo detiene aunque no salga en el desplegable', $rechazado);

    echo PHP_EOL.'4. Lo aceptado no se borra desde el portal'.PHP_EOL;

    $doc->update(['estado_documento_id' => EstadoDocumento::query()->where('clave', 'aceptado')->value('id')]);

    $control->eliminar(peticionDe($tutor, 'DELETE'), $doc->refresh());

    verificar('sigue ahí tras intentar eliminarlo',
        DocumentoTutor::query()->whereKey($doc->id)->exists());

    echo PHP_EOL.'5. El de otro tutor no se lee ni se borra'.PHP_EOL;

    $otraPersona = Persona::query()->whereKeyNot($tutor->persona_id)->firstOrFail();

    /*
     * Con archivo DE VERDAD en el disco, y no sólo la fila.
     *
     * Sin él, quitarle la salvaguarda al controlador hacía que la descarga
     * fallara igual —por archivo inexistente, 404— y la prueba «pasaba»
     * cazando el error equivocado. Se vio mutando: el script moría en vez de
     * reportar. Con el archivo puesto, la única razón de que no se descargue es
     * que la comprobación de propiedad lo impida.
     */
    $rutaAjena = 'tutores/'.$otraPersona->id.'/ajeno.pdf';
    Storage::disk('local')->put($rutaAjena, 'contenido ajeno');
    $subidos[] = $rutaAjena;

    $ajenoDoc = DocumentoTutor::create([
        'persona_id' => $otraPersona->id,
        'documento_id' => $tipo,
        'url' => $rutaAjena,
        'estado_documento_id' => EstadoDocumento::query()->where('clave', 'pendiente')->value('id'),
    ]);

    $bloqueado = false;

    // `Throwable` y no sólo la de acceso denegado: lo que se comprueba es que
    // NO se entregue el archivo, sea cual sea la forma de negarse. Si se
    // entregara, no habría excepción y la verificación caería, que es lo que
    // debe pasar.
    try {
        $control->descargar(peticionDe($tutor), $ajenoDoc);
    } catch (Throwable) {
        $bloqueado = true;
    }

    verificar('descargar el ajeno se rechaza', $bloqueado);

    $bloqueado = false;

    try {
        $control->eliminar(peticionDe($tutor, 'DELETE'), $ajenoDoc);
    } catch (Throwable) {
        $bloqueado = true;
    }

    verificar('borrar el ajeno se rechaza', $bloqueado);
    verificar('y sigue existiendo', DocumentoTutor::query()->whereKey($ajenoDoc->id)->exists());

    echo PHP_EOL.'6. Quien no es tutor de nadie no tiene expediente'.PHP_EOL;

    $sinVinculo = Usuario::query()
        ->whereNotNull('persona_id')
        ->whereNotIn('persona_id', TutorAlumno::query()->select('tutor_persona_id'))
        ->firstOrFail();

    $bloqueado = false;

    try {
        $control->show(peticionDe($sinVinculo));
    } catch (AccessDeniedHttpException) {
        $bloqueado = true;
    }

    verificar('el permiso no basta: hace falta el vínculo', $bloqueado, $sinVinculo->usuario);
} finally {
    // Los archivos de prueba viven FUERA de la transacción: el rollback no los
    // borra del disco y quedarían de basura en cada corrida.
    foreach (array_filter($subidos) as $ruta) {
        Storage::disk('local')->delete($ruta);
    }

    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
