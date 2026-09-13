<?php

/**
 * API de la app móvil: los DOCUMENTOS del expediente del ALUMNO. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-alumno-documentos.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `documentos()` lista los papeles y el catálogo SÓLO del ámbito ALUMNO.
 *  2. «Lo entregó tu tutor»: un documento subido por otra persona lo dice.
 *  3. Subir crea el comprobante PENDIENTE; re-subir REINICIA la revisión.
 *  4. Subir un tipo de OTRO ámbito se rechaza en `documento_id`.
 *  5. Eliminar un documento AJENO → 404; uno ACEPTADO → 422.
 *
 * La lógica vive en `DocumentosDelAlumno`, compartido por la web («Mi
 * expediente») y esta API: una sola verdad. Storage::fake y rollback.
 */

use App\Http\Controllers\Api\AlumnoApiController;
use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\DocumentoAlumno;
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

function comoAlumno(Usuario $usuario): Request
{
    $p = Request::create('/', 'GET');
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

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

    // ── Escenario: un alumno con cuenta, y otro usuario para «lo entregó …» ──
    $personasAlumnos = Alumno::query()->pluck('persona_id');
    $usuario = Usuario::query()->whereIn('persona_id', $personasAlumnos)->first();

    if ($usuario === null) {
        throw new RuntimeException('No hay un alumno con cuenta en el demo.');
    }

    auth()->login($usuario);
    $personaId = (int) $usuario->persona_id;

    // Otro usuario con persona distinta: hará de «tutor que entregó» (created_by
    // apunta a `usuarios`, sin exigir que sea alumno).
    $otroUsuario = Usuario::query()->whereNotNull('persona_id')->where('persona_id', '!=', $personaId)->first();
    $otroPersonaId = (int) ($otroUsuario?->persona_id ?? 0);
    // Para el documento AJENO hace falta otra persona que SEA alumno:
    // `documentos_alumno.persona_id` tiene FK contra `alumnos`.
    $otroAlumnoId = (int) ($personasAlumnos->first(fn ($p) => (int) $p !== $personaId) ?? 0);

    $idsAlumno = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)->pluck('id')->all();
    $tipoAlumno = DocumentoRequerido::query()->whereKey($idsAlumno)->orderBy('id')->first();
    $tipoOtroAmbito = DocumentoRequerido::query()
        ->delAmbito(DocumentoRequerido::AMBITO_DOCENTE)
        ->whereKeyNot($idsAlumno)
        ->orderBy('id')->first();

    if ($tipoOtroAmbito === null) {
        $tipoOtroAmbito = DocumentoRequerido::query()->create(['nombre' => 'Documento sólo del docente (prueba)', 'obligatorio' => false]);
        DB::connection('tenant')->table('documento_ambitos')->insert(['documento_id' => $tipoOtroAmbito->id, 'ambito' => DocumentoRequerido::AMBITO_DOCENTE]);
    }

    if ($tipoAlumno === null) {
        throw new RuntimeException('Faltan tipos de documento de ámbito alumno en el demo.');
    }

    $pendiente = EstadoDocumento::query()->where('clave', 'pendiente')->value('id');
    $aceptado = EstadoDocumento::query()->where('clave', 'aceptado')->value('id');

    $ctrl = app(AlumnoApiController::class);

    DocumentoAlumno::query()->where('persona_id', $personaId)->where('documento_id', $tipoAlumno->id)->forceDelete();

    // ── 1. El catálogo es SÓLO del ámbito alumno ─────────────────────────────
    echo PHP_EOL.'1. documentos(): catálogo del ámbito alumno'.PHP_EOL;

    $datos = json_decode($ctrl->documentos(comoAlumno($usuario))->getContent(), true);
    verificar('Trae documentos y tipos', is_array($datos['documentos'] ?? null) && is_array($datos['tipos'] ?? null));
    $idsTipos = array_map('intval', array_column($datos['tipos'], 'id'));
    verificar('Los tipos son sólo del ámbito alumno', $idsTipos !== [] && array_diff($idsTipos, array_map('intval', $idsAlumno)) === []);
    verificar('Un tipo de OTRO ámbito NO aparece', ! in_array((int) $tipoOtroAmbito->id, $idsTipos, true));

    // ── 2. «Lo entregó tu tutor» ─────────────────────────────────────────────
    echo PHP_EOL.'2. Un documento subido por otra persona lo dice'.PHP_EOL;

    if ($otroPersonaId !== 0) {
        $delTutor = DocumentoAlumno::create([
            'persona_id' => $personaId, 'documento_id' => $tipoAlumno->id,
            'url' => 'alumnos/'.$personaId.'/tutor.pdf', 'estado_documento_id' => $pendiente,
        ]);
        // Simula que lo subió el tutor: created_by de otra persona.
        $delTutor->forceFill(['created_by' => $otroUsuario->id])->saveQuietly();

        $lista = json_decode($ctrl->documentos(comoAlumno($usuario))->getContent(), true)['documentos'];
        $fila = collect($lista)->firstWhere('documento_id', $tipoAlumno->id);
        verificar('entregado_por trae el nombre de quien lo subió', ($fila['entregado_por'] ?? null) !== null);

        $delTutor->forceDelete();
    } else {
        verificar('OMITIDO: no hay un segundo usuario', false, 'escenario incompleto');
    }

    // ── 3. Subir crea PENDIENTE; re-subir reinicia ───────────────────────────
    echo PHP_EOL.'3. Subir: nace pendiente; re-subir reinicia la revisión'.PHP_EOL;

    $resp = json_decode($ctrl->subirDocumento(peticionSubir($usuario, ['documento_id' => $tipoAlumno->id], pdfFalso()))->getContent(), true);
    verificar('Subir responde ok', ($resp['ok'] ?? null) === true);

    $doc = DocumentoAlumno::query()->where('persona_id', $personaId)->where('documento_id', $tipoAlumno->id)->first();
    verificar('Nace PENDIENTE', $doc?->estado?->clave === 'pendiente');

    $doc->forceFill(['estado_documento_id' => $aceptado])->save();
    $ctrl->subirDocumento(peticionSubir($usuario, ['documento_id' => $tipoAlumno->id], pdfFalso()));
    $doc->refresh();
    verificar('Re-subir vuelve a PENDIENTE', $doc->estado?->clave === 'pendiente');

    // ── 4. Un tipo de OTRO ámbito se rechaza ─────────────────────────────────
    echo PHP_EOL.'4. Un documento de otro ámbito no entra al expediente'.PHP_EOL;

    $errores = null;
    try {
        $ctrl->subirDocumento(peticionSubir($usuario, ['documento_id' => $tipoOtroAmbito->id], pdfFalso()));
    } catch (ValidationException $e) {
        $errores = $e->errors();
    }
    verificar('Un tipo de otro ámbito se rechaza en documento_id', isset($errores['documento_id']));

    // ── 5. Eliminar: ajeno → 404, aceptado → 422, pendiente → borra ──────────
    echo PHP_EOL.'5. Eliminar: sólo lo propio, y nunca lo aceptado'.PHP_EOL;

    if ($otroAlumnoId !== 0) {
        $ajeno = DocumentoAlumno::updateOrCreate(
            ['persona_id' => $otroAlumnoId, 'documento_id' => $tipoAlumno->id],
            ['url' => 'alumnos/'.$otroAlumnoId.'/ajeno.pdf', 'estado_documento_id' => $pendiente],
        );
        verificar('Eliminar un documento ajeno → 404',
            fallo(fn () => $ctrl->eliminarDocumento(comoAlumno($usuario), $ajeno)) === 404);
    } else {
        verificar('OMITIDO: no hay un segundo alumno', false, 'escenario incompleto');
    }

    $doc->forceFill(['estado_documento_id' => $aceptado])->save();
    verificar('Eliminar un documento ACEPTADO → 422',
        fallo(fn () => $ctrl->eliminarDocumento(comoAlumno($usuario), $doc->fresh())) === 422);
    verificar('...y el aceptado sigue ahí', DocumentoAlumno::query()->whereKey($doc->id)->exists());

    $doc->forceFill(['estado_documento_id' => $pendiente])->save();
    $ctrl->eliminarDocumento(comoAlumno($usuario), $doc->fresh());
    verificar('Eliminar un documento pendiente lo retira', ! DocumentoAlumno::query()->whereKey($doc->id)->exists());
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
