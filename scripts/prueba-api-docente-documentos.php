<?php

/**
 * API de la app móvil: los DOCUMENTOS del expediente del DOCENTE. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-docente-documentos.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `documentos()` lista los papeles y el catálogo SÓLO del ámbito docente
 *     —el del alumno o el aspirante no tiene nada que hacer en su expediente—.
 *  2. Subir crea el comprobante PENDIENTE, y re-subir REINICIA la revisión.
 *  3. Subir un tipo de OTRO ámbito se rechaza en `documento_id`.
 *  4. Eliminar un documento AJENO → 404; uno ACEPTADO → 422 (no se retira aquí).
 *
 * La lógica vive en el servicio compartido `DocumentosDelDocente`, que usan por
 * igual la web («Mi expediente») y esta API: una sola verdad. El `can:` y el
 * 401/403 del stack los pone el middleware de la ruta —no pasa por el
 * controlador—; eso se comprueba por HTTP real contra el servidor.
 *
 * NO toca el disco real: `Storage::fake('local')`. Y todo va en una transacción
 * que se deshace al final.
 */

use App\Http\Controllers\Api\DocenteApiController;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que.($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

/** Una petición sin cuerpo, con el usuario ya resuelto (como el token). */
function comoDocente(Usuario $usuario): Request
{
    $p = Request::create('/', 'GET');
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

/** Una petición de subida: multipart con el archivo, y el usuario resuelto. */
function peticionSubir(Usuario $usuario, array $datos, UploadedFile $archivo): Request
{
    $p = Request::create('/', 'POST', $datos, [], ['archivo' => $archivo]);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

function pdfFalso(): UploadedFile
{
    return UploadedFile::fake()->create('comprobante.pdf', 20, 'application/pdf');
}

$db->beginTransaction();

try {
    Storage::fake('local');

    // ── Escenario: un docente con cuenta, y otro para el caso ajeno ──────────
    $personasDocentes = Docente::query()->pluck('persona_id');
    $usuario = Usuario::query()->whereIn('persona_id', $personasDocentes)->first();

    if ($usuario === null) {
        throw new RuntimeException('No hay un docente con cuenta en el demo.');
    }

    // Sesión abierta, para que la auditoría (created_by) tenga a quién anotar.
    auth()->login($usuario);
    $personaId = (int) $usuario->persona_id;

    $otroPersonaId = (int) ($personasDocentes->first(fn ($p) => (int) $p !== $personaId) ?? 0);

    // Un tipo del ámbito docente, y uno que es del ámbito ALUMNO y NO del docente
    // —los ámbitos son un pivote, así que un mismo tipo puede estar en varios; el
    // caso que separa las dos reglas es el que a un docente NO se le pide—.
    $idsDocente = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_DOCENTE)->pluck('id')->all();

    $tipoDoc = DocumentoRequerido::query()->whereKey($idsDocente)->orderBy('id')->first();
    $tipoAlumno = DocumentoRequerido::query()
        ->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
        ->whereKeyNot($idsDocente)
        ->orderBy('id')
        ->first();

    // El demo podría no tener uno exclusivo del alumno; se construye para el caso.
    if ($tipoAlumno === null) {
        $tipoAlumno = DocumentoRequerido::query()->create(['nombre' => 'Documento sólo del alumno (prueba)', 'obligatorio' => false]);
        DB::connection('tenant')->table('documento_ambitos')->insert([
            'documento_id' => $tipoAlumno->id,
            'ambito' => DocumentoRequerido::AMBITO_ALUMNO,
        ]);
    }

    if ($tipoDoc === null) {
        throw new RuntimeException('Faltan tipos de documento de ámbito docente en el demo.');
    }

    $pendiente = EstadoDocumento::query()->where('clave', 'pendiente')->value('id');
    $aceptado = EstadoDocumento::query()->where('clave', 'aceptado')->value('id');

    $ctrl = app(DocenteApiController::class);

    // Que el expediente arranque limpio de este tipo, para medir la creación.
    DocumentoDocente::query()->where('persona_id', $personaId)->where('documento_id', $tipoDoc->id)->forceDelete();

    // ── 1. El catálogo es SÓLO del ámbito docente ────────────────────────────
    echo PHP_EOL.'1. documentos(): los papeles del expediente y el catálogo del ámbito docente'.PHP_EOL;

    $datos = json_decode($ctrl->documentos(comoDocente($usuario))->getContent(), true);
    verificar('Trae documentos y tipos', is_array($datos['documentos'] ?? null) && is_array($datos['tipos'] ?? null));

    $idsTipos = array_column($datos['tipos'], 'id');
    verificar('Los tipos son sólo del ámbito docente', $idsTipos !== [] && array_diff($idsTipos, $idsDocente) === []);
    verificar('Un tipo del ámbito ALUMNO NO aparece en el catálogo del docente',
        ! in_array((int) $tipoAlumno->id, array_map('intval', $idsTipos), true));

    // ── 2. Subir crea el comprobante PENDIENTE ───────────────────────────────
    echo PHP_EOL.'2. Subir un comprobante: nace pendiente de revisión'.PHP_EOL;

    $resp = json_decode($ctrl->subirDocumento(peticionSubir($usuario, ['documento_id' => $tipoDoc->id], pdfFalso()))->getContent(), true);
    verificar('Subir responde ok', ($resp['ok'] ?? null) === true);

    $doc = DocumentoDocente::query()->where('persona_id', $personaId)->where('documento_id', $tipoDoc->id)->first();
    verificar('Quedó un documento para ese tipo', $doc !== null);
    verificar('Nace PENDIENTE de revisión', $doc?->estado?->clave === 'pendiente');
    verificar('Y aparece en la lista con su tipo',
        collect(json_decode($ctrl->documentos(comoDocente($usuario))->getContent(), true)['documentos'])
            ->firstWhere('documento_id', $tipoDoc->id) !== null);

    // ── 3. Re-subir REINICIA la revisión ─────────────────────────────────────
    echo PHP_EOL.'3. Re-subir un comprobante ya aceptado reinicia la revisión'.PHP_EOL;

    $doc->forceFill(['estado_documento_id' => $aceptado])->save();
    $ctrl->subirDocumento(peticionSubir($usuario, ['documento_id' => $tipoDoc->id], pdfFalso()));
    $doc->refresh();
    verificar('Vuelve a PENDIENTE tras reemplazarlo', $doc->estado?->clave === 'pendiente');

    // ── 4. Un tipo de OTRO ámbito se rechaza ─────────────────────────────────
    echo PHP_EOL.'4. Un documento de otro ámbito no entra al expediente del docente'.PHP_EOL;

    $errores = null;
    try {
        $ctrl->subirDocumento(peticionSubir($usuario, ['documento_id' => $tipoAlumno->id], pdfFalso()));
    } catch (ValidationException $e) {
        $errores = $e->errors();
    }
    verificar('Un tipo del ámbito ALUMNO se rechaza en documento_id', isset($errores['documento_id']));

    // ── 5. Eliminar: ajeno → 404, aceptado → 422, pendiente → borra ──────────
    echo PHP_EOL.'5. Eliminar: sólo lo propio, y nunca lo ya aceptado'.PHP_EOL;

    if ($otroPersonaId !== 0) {
        $ajeno = DocumentoDocente::updateOrCreate(
            ['persona_id' => $otroPersonaId, 'documento_id' => $tipoDoc->id],
            ['url' => 'docentes/'.$otroPersonaId.'/ajeno.pdf', 'estado_documento_id' => $pendiente],
        );
        verificar('Eliminar un documento de otro docente → 404',
            fallo(fn () => $ctrl->eliminarDocumento(comoDocente($usuario), $ajeno)) === 404);
        verificar('...y el documento ajeno sigue ahí', DocumentoDocente::query()->whereKey($ajeno->id)->exists());
    } else {
        verificar('OMITIDO: no hay un segundo docente en el demo', false, 'escenario incompleto');
        verificar('OMITIDO: no hay un segundo docente en el demo', false, 'escenario incompleto');
    }

    $doc->forceFill(['estado_documento_id' => $aceptado])->save();
    verificar('Eliminar un documento ACEPTADO → 422',
        fallo(fn () => $ctrl->eliminarDocumento(comoDocente($usuario), $doc->fresh())) === 422);
    verificar('...y el aceptado sigue ahí', DocumentoDocente::query()->whereKey($doc->id)->exists());

    $doc->forceFill(['estado_documento_id' => $pendiente])->save();
    $ctrl->eliminarDocumento(comoDocente($usuario), $doc->fresh());
    verificar('Eliminar un documento pendiente lo retira', ! DocumentoDocente::query()->whereKey($doc->id)->exists());
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
