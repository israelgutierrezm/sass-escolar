<?php

/**
 * API de la app móvil: el ASPIRANTE llena un FORMULARIO dinámico. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-aspirante-formularios.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La ficha trae los campos (con su condición) y lo ya contestado.
 *  2. Guardar escribe una respuesta por campo; releer las muestra.
 *  3. Un campo condicional escondido NO es obligatorio; visible, SÍ.
 *  4. Un formulario que no le toca → 404.
 *
 * La regla vive en `CapturaDeFormulario` (el mismo servicio que la web). El demo
 * no asigna formularios a aspirantes, así que se construye en la transacción.
 */

use App\Http\Controllers\Api\AspiranteApiController;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Formularios\OpcionCampo;
use App\Models\Formularios\TipoCampo;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

function esValidacion(callable $accion): bool
{
    try {
        $accion();
    } catch (ValidationException) {
        return true;
    }

    return false;
}

function req(Usuario $usuario, array $datos = [], string $metodo = 'POST'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    $aspirante = Aspirante::query()->whereNotNull('persona_id')
        ->whereHas('persona', fn ($q) => $q->whereHas('usuario'))->orderByDesc('id')->first();
    $usuario = $aspirante === null ? null : Usuario::query()->where('persona_id', $aspirante->persona_id)->first();
    $rolAspirante = Rol::query()->where('name', 'aspirante')->first();
    $radio = TipoCampo::query()->where('clave', 'radio')->first();
    $texto = TipoCampo::query()->where('clave', 'texto')->first();

    if ($usuario === null || $rolAspirante === null || $radio === null || $texto === null) {
        throw new RuntimeException('Faltan datos base en el demo (aspirante con cuenta, rol aspirante, tipos de campo).');
    }

    auth()->login($usuario);

    // ── Un formulario asignado a la faceta aspirante (ámbito null = a todos) ──
    $f = Formulario::create(['clave' => 'app-prueba-'.uniqid(), 'titulo' => 'Datos de prueba', 'version' => 1]);
    FormularioAsignacion::create([
        'formulario_id' => $f->id, 'rol_id' => $rolAspirante->id, 'ambito_tipo' => null, 'ambito_id' => null, 'obligatorio' => true,
    ]);
    $padre = CampoFormulario::create([
        'formulario_id' => $f->id, 'tipo_campo_id' => $radio->id, 'pregunta' => '¿Trabajas?', 'obligatorio' => true, 'orden' => 1,
    ]);
    OpcionCampo::create(['campo_formulario_id' => $padre->id, 'valor' => 'si', 'etiqueta' => 'Sí', 'orden' => 1]);
    OpcionCampo::create(['campo_formulario_id' => $padre->id, 'valor' => 'no', 'etiqueta' => 'No', 'orden' => 2]);
    $hijo = CampoFormulario::create([
        'formulario_id' => $f->id, 'tipo_campo_id' => $texto->id, 'pregunta' => '¿En qué?', 'obligatorio' => true,
        'orden' => 2, 'campo_padre_id' => $padre->id, 'condicional' => 'si',
    ]);

    $ctrl = app(AspiranteApiController::class);

    // ── 1. La ficha ────────────────────────────────────────────────────────
    echo PHP_EOL.'1. La ficha trae los campos con su condición'.PHP_EOL;

    $ficha = json_decode($ctrl->formulario(req($usuario, [], 'GET'), $f->fresh())->getContent(), true);
    verificar('Trae los dos campos', count($ficha['campos']) === 2);
    $hijoEnFicha = collect($ficha['campos'])->firstWhere('id', $hijo->id);
    verificar('El campo hijo declara su padre y condición',
        $hijoEnFicha['campo_padre_id'] === $padre->id && $hijoEnFicha['condicional'] === 'si');
    verificar('El radio trae sus opciones', count(collect($ficha['campos'])->firstWhere('id', $padre->id)['opciones']) === 2);
    verificar('Arranca sin respuestas', $ficha['respuestas'] === []);

    // ── 2. Guardar y releer ──────────────────────────────────────────────────
    echo PHP_EOL.'2. Guardar escribe una respuesta por campo'.PHP_EOL;

    $ctrl->guardarFormulario(req($usuario, ['campos' => [$padre->id => 'si', $hijo->id => 'En una tienda']]), $f->fresh());
    verificar('Se guardó la del radio', RespuestaCampo::query()->where('aspirante_id', $aspirante->id)->where('campo_formulario_id', $padre->id)->exists());

    $ficha = json_decode($ctrl->formulario(req($usuario, [], 'GET'), $f->fresh())->getContent(), true);
    verificar('Al releer, el hijo trae lo contestado', ($ficha['respuestas'][(string) $hijo->id]['valor'] ?? null) === 'En una tienda');

    // ── 3. El condicional ─────────────────────────────────────────────────────
    echo PHP_EOL.'3. Un campo condicional escondido no es obligatorio; visible, sí'.PHP_EOL;

    // Padre = 'no' esconde al hijo: guardar SIN el hijo debe pasar.
    $ctrl->guardarFormulario(req($usuario, ['campos' => [$padre->id => 'no']]), $f->fresh());
    verificar('Con el padre en «no», el hijo escondido no se exige', true);

    // Padre = 'si' muestra al hijo: guardarlo vacío debe fallar.
    verificar('Con el padre en «sí», el hijo vacío → validación',
        esValidacion(fn () => $ctrl->guardarFormulario(req($usuario, ['campos' => [$padre->id => 'si', $hijo->id => '']]), $f->fresh())));

    // ── 4. Un formulario ajeno → 404 ─────────────────────────────────────────
    echo PHP_EOL.'4. Un formulario que no le toca → 404'.PHP_EOL;

    $ajeno = Formulario::create(['clave' => 'app-ajeno-'.uniqid(), 'titulo' => 'No asignado', 'version' => 1]);
    verificar('Un formulario sin asignar → 404',
        fallo(fn () => $ctrl->formulario(req($usuario, [], 'GET'), $ajeno->fresh())) === 404);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
