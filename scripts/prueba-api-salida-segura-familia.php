<?php

/**
 * API de la app móvil: salida segura de la familia —quién puede recoger al hijo:
 * listar, autorizar un tercero y retirarlo—. Contra la BD real, con rollback.
 *
 * `php scripts/prueba-api-salida-segura-familia.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La lista efectiva sale del servicio compartido `PuedeRecoger`; agregar y
 *     quitar, de `AutorizadosParaRecoger` (una sola verdad con la web).
 *  2. La familia SÓLO toca `permitido=true`: NO puede quitar un bloqueo de
 *     custodia (404, y sigue ahí).
 *  3. El vínculo cierra a quién: un hijo ajeno → 403; un autorizado de otro hijo
 *     → 404.
 */

use App\Http\Controllers\Api\PadreApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Identidad\AutorizadoRecoger;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

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

function comoFamilia(Usuario $usuario, array $datos = [], string $metodo = 'GET'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

DB::beginTransaction();

try {
    // ── Escenario: un tutor con cuenta y su hijo ─────────────────────────────
    $hijo = Persona::create(['nombre' => 'Hijo', 'primer_apellido' => 'Salida', 'sexo_id' => 1]);

    $facetaPadre = Rol::where('name', 'padre_familia')->firstOrFail();
    $facetaPadre->givePermissionTo(['ver-mis-hijos']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $tutor = Persona::create(['nombre' => 'Tutora', 'primer_apellido' => 'Salida', 'sexo_id' => 2]);
    $usuario = Usuario::create([
        'persona_id' => $tutor->id,
        'usuario' => 'tutor_sal_'.random_int(100000, 999999),
        'email' => 'tutor_sal_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $facetaPadre->id,
    ]);
    $usuario->persona->asignacionesRol()->create(['rol_id' => $facetaPadre->id, 'activo' => true]);
    $usuario = $usuario->fresh(['persona', 'rolActivo']);

    TutorAlumno::create([
        'tutor_persona_id' => $tutor->id,
        'alumno_persona_id' => $hijo->id,
        'parentesco_id' => Parentesco::query()->value('id'),
    ]);

    // Un hijo ajeno (de otro tutor).
    $ajeno = Persona::create(['nombre' => 'Ajeno', 'primer_apellido' => 'Salida', 'sexo_id' => 1]);

    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoFamilia($usuario), fn ($r) => new Response('', 200), 'padre_familia');

    $ctrl = app(PadreApiController::class);

    echo PHP_EOL.'1. La lista efectiva incluye al tutor; sin terceros aún'.PHP_EOL;

    $api = json_decode($ctrl->recogen(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('La efectiva trae al tutor por su vínculo',
        collect($api['efectiva'])->contains(fn ($e) => $e['origen'] === 'tutor'));
    verificar('Sin terceros todavía', $api['terceros'] === []);
    verificar('Y el catálogo de parentescos para el alta', ! empty($api['parentescos']));

    echo PHP_EOL.'2. Agregar un tercero: nace autorizado y con token'.PHP_EOL;

    $ctrl->agregarAutorizado(comoFamilia($usuario, [
        'nombre' => 'Abuela Rosa', 'identificacion' => 'INE 123', 'parentesco_id' => Parentesco::query()->value('id'),
    ], 'POST'), $hijo->fresh());
    $tercero = AutorizadoRecoger::where('alumno_persona_id', $hijo->id)->autoriza()->first();
    verificar('Queda un tercero permitido', $tercero !== null && $tercero->permitido === true);
    verificar('Con token del servidor (para el QR)', $tercero?->token !== null);
    $api = json_decode($ctrl->recogen(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('Aparece en los terceros editables', collect($api['terceros'])->contains(fn ($t) => $t['nombre'] === 'Abuela Rosa'));
    verificar('Y en la efectiva como autorizado', collect($api['efectiva'])->contains(fn ($e) => $e['origen'] === 'autorizado'));

    echo PHP_EOL.'3. La familia NO puede quitar un bloqueo de custodia'.PHP_EOL;

    // Un bloqueo lo pone la ESCUELA (permitido=false). La familia no debe borrarlo.
    $bloqueo = AutorizadoRecoger::create([
        'alumno_persona_id' => $hijo->id, 'persona_id' => $tutor->id,
        'nombre' => 'Progenitor bloqueado', 'permitido' => false, 'motivo' => 'Orden de restricción.',
    ]);
    verificar('Quitar un bloqueo por la puerta de la familia → 404',
        fallo(fn () => $ctrl->quitarAutorizado(comoFamilia($usuario, [], 'DELETE'), $hijo->fresh(), $bloqueo->fresh())) === 404);
    verificar('Y el bloqueo sigue ahí', AutorizadoRecoger::where('id', $bloqueo->id)->exists());

    echo PHP_EOL.'4. Quitar un tercero suyo sí'.PHP_EOL;

    $ctrl->quitarAutorizado(comoFamilia($usuario, [], 'DELETE'), $hijo->fresh(), $tercero->fresh());
    verificar('El tercero se retiró', AutorizadoRecoger::where('id', $tercero->id)->doesntExist());

    echo PHP_EOL.'5. El vínculo cierra a quién'.PHP_EOL;

    verificar('Ver los recogedores de un hijo ajeno → 403',
        fallo(fn () => $ctrl->recogen(comoFamilia($usuario), $ajeno)) === 403);
    verificar('Agregar sobre un hijo ajeno → 403',
        fallo(fn () => $ctrl->agregarAutorizado(comoFamilia($usuario, ['nombre' => 'X'], 'POST'), $ajeno)) === 403);

    // Un autorizado que es de OTRO hijo, pedido por la URL de mi hijo → 404.
    $otroTercero = AutorizadoRecoger::create(['alumno_persona_id' => $ajeno->id, 'nombre' => 'De otro', 'permitido' => true]);
    verificar('Quitar un autorizado que no es de este hijo → 404',
        fallo(fn () => $ctrl->quitarAutorizado(comoFamilia($usuario, [], 'DELETE'), $hijo->fresh(), $otroTercero)) === 404);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    DB::rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
