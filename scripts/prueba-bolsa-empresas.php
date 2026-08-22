<?php

/**
 * Módulo 11 · Bolsa de trabajo — el padrón de empleadores. Con rollback.
 *
 * Se corre con `php scripts/prueba-bolsa-empresas.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. Una empresa VETADA deja de poder publicar, pero NO desaparece: sus
 *     colocaciones históricas son el insumo de los reportes de acreditación, y
 *     borrarla se las llevaría.
 *  2. El RFC no se repite. Sin eso la misma empresa capturada dos veces reparte
 *     sus colocaciones entre los duplicados y ningún reporte cuadra.
 *  3. Un solo contacto principal. Marcar a alguien degrada al anterior en la
 *     misma transacción; con dos, la pantalla enseña el que salga primero.
 *  4. El contacto de otra empresa no se borra desde aquí.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\Bolsa\EmpresaController;
use App\Models\Bolsa\Empresa;
use App\Models\Bolsa\SituacionEmpresa;
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

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function peticionDe(Usuario $usuario, string $metodo = 'POST', array $datos = []): Request
{
    $peticion = Request::create('/bolsa/empresas', $metodo, $datos);
    $peticion->setUserResolver(fn () => $usuario);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

try {
    $control = app(EmpresaController::class);
    $usuario = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    $activa = SituacionEmpresa::query()->where('clave', 'activa')->firstOrFail();
    $vetada = SituacionEmpresa::query()->where('clave', 'vetada')->firstOrFail();

    echo PHP_EOL.'1. Se registra un empleador'.PHP_EOL;

    $control->guardar(peticionDe($usuario, 'POST', [
        'razon_social' => 'Constructora de prueba SA de CV',
        'rfc' => 'CPR010101AAA',
        'situacion_id' => $activa->id,
        'sitio_web' => 'https://ejemplo.mx',
    ]));

    $empresa = Empresa::query()->where('rfc', 'CPR010101AAA')->first();

    verificar('queda registrada', $empresa !== null);
    verificar('con su situación', $empresa?->situacion_id === $activa->id);

    echo PHP_EOL.'2. El RFC no se repite'.PHP_EOL;

    /*
     * Se exige que lo detenga la VALIDACIÓN y no el índice único de la base.
     *
     * Las dos impiden el duplicado, así que catchar cualquier excepción daría
     * la prueba por buena con la regla quitada —lo comprobé mutando: el índice
     * la bloqueaba igual, sólo que con un 500—. Lo que se está probando es que
     * quien captura lea «ya hay una empresa con ese RFC» en su formulario, no
     * que la base se defienda sola.
     */
    $comoFallo = null;

    try {
        $control->guardar(peticionDe($usuario, 'POST', [
            'razon_social' => 'Otra con el mismo RFC',
            'rfc' => 'CPR010101AAA',
            'situacion_id' => $activa->id,
        ]));
    } catch (Throwable $e) {
        $comoFallo = $e;
    }

    verificar('la segunda con el mismo RFC se rechaza con un mensaje, no con un 500',
        $comoFallo instanceof ValidationException,
        $comoFallo === null ? 'no falló' : get_class($comoFallo));
    verificar('y sigue habiendo una sola',
        Empresa::query()->where('rfc', 'CPR010101AAA')->count() === 1);

    echo PHP_EOL.'3. Sin RFC sí se puede registrar, y varias'.PHP_EOL;

    foreach (['Taller sin papeles', 'Otro taller sin papeles'] as $nombre) {
        $control->guardar(peticionDe($usuario, 'POST', [
            'razon_social' => $nombre,
            'situacion_id' => $activa->id,
        ]));
    }

    verificar('dos empresas sin RFC conviven',
        Empresa::query()->whereIn('razon_social', ['Taller sin papeles', 'Otro taller sin papeles'])->count() === 2);

    echo PHP_EOL.'4. Vetarla la saca de las publicables sin borrarla'.PHP_EOL;

    $publicableAntes = Empresa::query()->publicables()->whereKey($empresa->id)->exists();

    $control->guardar(peticionDe($usuario, 'PUT', [
        'razon_social' => $empresa->razon_social,
        'rfc' => $empresa->rfc,
        'situacion_id' => $vetada->id,
    ]), $empresa);

    $empresa->refresh();

    verificar('antes podía publicar', $publicableAntes);
    verificar('vetada ya no puede', ! Empresa::query()->publicables()->whereKey($empresa->id)->exists());
    verificar('pero sigue existiendo', Empresa::query()->whereKey($empresa->id)->exists());

    echo PHP_EOL.'5. Un solo contacto principal'.PHP_EOL;

    foreach ([['Ana Reclutadora', true], ['Luis Suplente', false], ['Marta Nueva', true]] as [$nombre, $principal]) {
        $control->guardarContacto(
            peticionDe($usuario, 'POST', ['nombre' => $nombre, 'es_principal' => $principal]),
            $empresa,
        );
    }

    $principales = $empresa->contactos()->where('es_principal', true)->pluck('nombre');

    verificar('queda exactamente uno', $principales->count() === 1, $principales->implode(', '));
    verificar('y es el último marcado', $principales->first() === 'Marta Nueva', (string) $principales->first());
    verificar('los tres siguen registrados', $empresa->contactos()->count() === 3);

    echo PHP_EOL.'6. El contacto de otra empresa no se toca'.PHP_EOL;

    $otra = Empresa::query()->where('razon_social', 'Taller sin papeles')->firstOrFail();
    $ajeno = $otra->contactos()->create(['nombre' => 'Contacto ajeno']);

    $bloqueado = false;

    try {
        $control->eliminarContacto($empresa, $ajeno);
    } catch (HttpException $e) {
        $bloqueado = $e->getStatusCode() === 404;
    }

    verificar('responde 404', $bloqueado);
    verificar('y el contacto sigue ahí', $otra->contactos()->whereKey($ajeno->id)->exists());
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
