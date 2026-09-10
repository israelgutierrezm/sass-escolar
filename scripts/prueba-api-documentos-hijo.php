<?php

/**
 * API de la app móvil: los documentos del hijo (listar, subir, eliminar). Con
 * rollback y `Storage::fake` para no tocar el disco.
 *
 * `php scripts/prueba-api-documentos-hijo.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La lista, las tres capas y la escritura salen de UN servicio
 *     (`EntregaDocumentos`) que usan la web y la API.
 *  2. Las tres capas: la escuela apagada → null/404; un hijo mayor → sólo el
 *     motivo; un hijo ajeno → 403.
 *  3. Subir crea el documento pendiente; lo ACEPTADO no se pisa (mensaje).
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\Api\PadreApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Familia\EntregaDocumentos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));
Storage::fake('local'); // los archivos de la prueba no tocan el disco real

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
    } catch (ValidationException $e) {
        return $e->status;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

function comoFamilia(Usuario $usuario, array $datos = [], string $metodo = 'GET', array $archivos = []): Request
{
    // El archivo va en el bag de FILES: `->file('archivo')` no lee de la entrada.
    $p = Request::create('/', $metodo, $datos, [], $archivos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: tutor con cuenta, un hijo al que volvemos MENOR ───────────
    $vinculo = TutorAlumno::query()->whereHas('tutor.usuario')->first();
    if ($vinculo === null) {
        throw new RuntimeException('No hay un tutor con cuenta en el demo.');
    }

    $usuario = Usuario::query()->where('persona_id', $vinculo->tutor_persona_id)->firstOrFail();
    $hijo = Persona::findOrFail($vinculo->alumno_persona_id);
    // Diez años: menor de edad para cualquier mayoría razonable.
    $hijo->forceFill(['fecha_nacimiento' => now()->subYears(10)->format('Y-m-d')])->save();

    $ajustes = app(Ajustes::class);
    $ajustes->guardar([CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS => true, CatalogoAjustes::MAYORIA_DE_EDAD => 18]);

    $tipoDoc = (int) DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)->value('id');

    $servicio = app(EntregaDocumentos::class);
    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoFamilia($usuario), fn ($r) => new Response('', 200), 'padre_familia');

    echo PHP_EOL.'1. El servicio arma la lista cuando el tutor puede entregar'.PHP_EOL;

    $datos = $servicio->datos($hijo, $vinculo);
    verificar('Devuelve el bloque (la escuela lo permite y es menor)',
        is_array($datos) && array_key_exists('motivo', $datos) && $datos['motivo'] === null);
    verificar('Con los tipos que la escuela pide', ! empty($datos['tipos']));

    echo PHP_EOL.'2. La API lee la lista'.PHP_EOL;

    $ctrl = app(PadreApiController::class);
    $api = json_decode($ctrl->documentos(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('La API trae tipos y documentos', array_key_exists('tipos', $api) && array_key_exists('documentos', $api));

    echo PHP_EOL.'3. Subir crea el documento pendiente'.PHP_EOL;

    $archivo = UploadedFile::fake()->create('acta.pdf', 120, 'application/pdf');
    $ctrl->subirDocumento(comoFamilia($usuario, ['documento_id' => $tipoDoc], 'POST', ['archivo' => $archivo]), $hijo->fresh());
    $subido = DocumentoAlumno::where('persona_id', $hijo->id)->where('documento_id', $tipoDoc)->first();
    verificar('Quedó registrado', $subido !== null);
    verificar('Y en estado pendiente', $subido?->estado?->clave === 'pendiente');
    verificar('El archivo se guardó en el disco (fake)', $subido !== null && Storage::disk('local')->exists($subido->url));

    echo PHP_EOL.'4. Lo ACEPTADO no se pisa'.PHP_EOL;

    $aceptadoId = EstadoDocumento::query()->where('clave', 'aceptado')->value('id');
    $subido->forceFill(['estado_documento_id' => $aceptadoId])->save();
    $otro = UploadedFile::fake()->create('acta2.pdf', 120, 'application/pdf');
    $error = $servicio->subir($hijo, $tipoDoc, $otro, null, null);
    verificar('Subir sobre un aceptado devuelve mensaje, no lo reemplaza', $error !== null && str_contains($error, 'aceptado'));
    // Devolver a pendiente para el resto.
    $subido->forceFill(['estado_documento_id' => EstadoDocumento::where('clave', 'pendiente')->value('id')])->save();

    echo PHP_EOL.'5. Las tres capas'.PHP_EOL;

    // Escuela apagada → datos null y la API responde 404.
    $ajustes->guardar([CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS => false]);
    verificar('Apagado el ajuste, datos() es null', $servicio->datos($hijo, $vinculo) === null);
    verificar('Y la API responde 404', fallo(fn () => $ctrl->documentos(comoFamilia($usuario), $hijo->fresh())) === 404);
    $ajustes->guardar([CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS => true]);

    // Hijo MAYOR → sólo el motivo, sin documentos.
    $hijo->forceFill(['fecha_nacimiento' => now()->subYears(30)->format('Y-m-d')])->save();
    $mayor = $servicio->datos($hijo->fresh(), $vinculo);
    verificar('Un hijo mayor: sólo el motivo, sin lista', ($mayor['motivo'] ?? null) !== null && $mayor['documentos'] === []);
    $hijo->forceFill(['fecha_nacimiento' => now()->subYears(10)->format('Y-m-d')])->save();

    // Hijo ajeno → 403.
    $ajeno = Persona::query()->whereKeyNot($hijo->id)
        ->whereNotIn('id', TutorAlumno::where('tutor_persona_id', $vinculo->tutor_persona_id)->pluck('alumno_persona_id'))
        ->first();
    if ($ajeno !== null) {
        verificar('Los documentos de un alumno ajeno → 403',
            fallo(fn () => $ctrl->documentos(comoFamilia($usuario), $ajeno)) === 403);
    }

    echo PHP_EOL.'6. Eliminar retira lo pendiente'.PHP_EOL;

    $ctrl->eliminarDocumento(comoFamilia($usuario, [], 'DELETE'), $hijo->fresh(), $subido->fresh());
    verificar('El documento se retiró', DocumentoAlumno::where('id', $subido->id)->doesntExist());
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
