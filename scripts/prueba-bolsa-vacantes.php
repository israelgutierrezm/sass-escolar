<?php

/**
 * Módulo 11 · Bolsa de trabajo — las vacantes. Con rollback.
 *
 * Se corre con `php scripts/prueba-bolsa-vacantes.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. Una empresa VETADA no puede publicar, y sus vacantes vivas dejan de estar
 *     vigentes. Sin lo segundo, vetar a un empleador sólo lo esconde del padrón
 *     mientras sus vacantes siguen recibiendo postulantes.
 *  2. Una vacante VENCIDA no está vigente aunque su situación diga «abierta».
 *     Es la trampa del tablero: se publica con fecha de cierre, se pasa, y la
 *     lista la sigue enseñando en verde.
 *  3. Sin carreras señaladas la vacante es PARA TODAS, y tiene que aparecerle a
 *     cualquier alumno. Dejarlas fuera escondería la mitad de la oferta real.
 *  4. Un rango de sueldo invertido se rechaza.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\Bolsa\VacanteController;
use App\Models\Academico\Carrera;
use App\Models\Bolsa\Empresa;
use App\Models\Bolsa\Habilidad;
use App\Models\Bolsa\SituacionEmpresa;
use App\Models\Bolsa\SituacionVacante;
use App\Models\Bolsa\Vacante;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    $peticion = Request::create('/bolsa/vacantes', $metodo, $datos);
    $peticion->setUserResolver(fn () => $usuario);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

try {
    $control = app(VacanteController::class);
    $usuario = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    $activa = SituacionEmpresa::query()->where('clave', 'activa')->firstOrFail();
    $vetada = SituacionEmpresa::query()->where('clave', 'vetada')->firstOrFail();
    $abierta = SituacionVacante::query()->where('clave', 'abierta')->firstOrFail();

    $empresa = Empresa::create(['razon_social' => 'Empleador de prueba', 'situacion_id' => $activa->id]);

    [$carreraA, $carreraB] = Carrera::query()->limit(2)->get()->all();
    $habilidad = Habilidad::query()->firstOrFail();

    verificar('hay dos carreras distintas con las que probar',
        $carreraA !== null && $carreraB !== null && $carreraA->id !== $carreraB->id);

    $base = fn (array $extra = []) => array_merge([
        'empresa_id' => $empresa->id,
        'titulo' => 'Auxiliar de prueba',
        'descripcion' => 'Descripción de prueba.',
        'vacantes_disponibles' => 2,
        'fecha_publicacion' => now()->toDateString(),
        'situacion_id' => $abierta->id,
    ], $extra);

    echo PHP_EOL.'1. Se publica una vacante'.PHP_EOL;

    $control->guardar(peticionDe($usuario, 'POST', $base([
        'carreras' => [$carreraA->id],
        'habilidades' => [['id' => $habilidad->id, 'indispensable' => true]],
    ])));

    $vacante = Vacante::query()->where('empresa_id', $empresa->id)->firstOrFail();

    verificar('queda publicada', $vacante->titulo === 'Auxiliar de prueba');
    verificar('con su carrera', $vacante->carreras()->count() === 1);
    verificar('y su habilidad marcada como indispensable',
        (bool) $vacante->habilidades()->first()?->pivot?->indispensable);
    verificar('está vigente', Vacante::query()->vigentes()->whereKey($vacante->id)->exists());

    echo PHP_EOL.'2. Sin carreras señaladas es PARA TODAS'.PHP_EOL;

    $control->guardar(peticionDe($usuario, 'POST', $base(['titulo' => 'Abierta a todas'])));
    $general = Vacante::query()->where('titulo', 'Abierta a todas')->firstOrFail();

    verificar('la acotada NO le sale a la otra carrera',
        ! Vacante::query()->paraCarrera($carreraB->id)->whereKey($vacante->id)->exists());
    verificar('la general SÍ le sale a esa carrera',
        Vacante::query()->paraCarrera($carreraB->id)->whereKey($general->id)->exists());
    verificar('y también a la primera',
        Vacante::query()->paraCarrera($carreraA->id)->whereKey($general->id)->exists());

    echo PHP_EOL.'3. Vencida no está vigente aunque diga «abierta»'.PHP_EOL;

    $vacante->update(['fecha_cierre' => now()->subDay()->toDateString()]);

    verificar('su situación sigue siendo abierta',
        $vacante->refresh()->situacion?->clave === 'abierta');
    verificar('pero ya no está vigente',
        ! Vacante::query()->vigentes()->whereKey($vacante->id)->exists());

    $vacante->update(['fecha_cierre' => null]);

    echo PHP_EOL.'4. Vetar la empresa apaga sus vacantes'.PHP_EOL;

    $vigentesAntes = Vacante::query()->vigentes()->where('empresa_id', $empresa->id)->count();

    $empresa->update(['situacion_id' => $vetada->id]);

    verificar('antes tenía vacantes vigentes', $vigentesAntes === 2, (string) $vigentesAntes);
    verificar('vetada, ninguna queda vigente',
        Vacante::query()->vigentes()->where('empresa_id', $empresa->id)->count() === 0);
    verificar('pero las vacantes siguen existiendo',
        Vacante::query()->where('empresa_id', $empresa->id)->count() === 2);

    echo PHP_EOL.'5. Una empresa vetada no puede publicar más'.PHP_EOL;

    $comoFallo = null;

    try {
        $control->guardar(peticionDe($usuario, 'POST', $base(['titulo' => 'No debería existir'])));
    } catch (Throwable $e) {
        $comoFallo = $e;
    }

    verificar('la validación lo detiene con un mensaje', $comoFallo instanceof ValidationException,
        $comoFallo === null ? 'no falló' : get_class($comoFallo));
    verificar('y no se creó', ! Vacante::query()->where('titulo', 'No debería existir')->exists());

    echo PHP_EOL.'6. Un rango de sueldo invertido se rechaza'.PHP_EOL;

    $empresa->update(['situacion_id' => $activa->id]);
    $rechazado = false;

    try {
        $control->guardar(peticionDe($usuario, 'POST', $base([
            'titulo' => 'Sueldo al revés',
            'salario_min' => 20000,
            'salario_max' => 10000,
        ])));
    } catch (ValidationException) {
        $rechazado = true;
    }

    verificar('no se puede guardar con el máximo menor que el mínimo', $rechazado);
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
