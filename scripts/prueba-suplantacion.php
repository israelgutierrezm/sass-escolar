<?php

/**
 * Prueba de integración de la suplantación: quién puede, a quién no, y que
 * todo quede en bitácora. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-suplantacion.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Auditoria;
use App\Services\Suplantador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

tenancy()->initialize(App\Models\Tenant::find('demo'));

$ok = 0;
$fallos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;

    if ($condicion) {
        $ok++;
        echo "  OK   {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Crea un usuario con un rol dado, para no depender del estado de la escuela. */
function usuarioCon(string $rolClave, string $sufijo, string $etiqueta): Usuario
{
    $persona = Persona::create([
        'nombre' => $etiqueta,
        'primer_apellido' => 'Prueba',
        'sexo_id' => 1,
    ]);

    $rol = Rol::where('name', $rolClave)->firstOrFail();

    PersonaRol::create([
        'persona_id' => $persona->id,
        'rol_id' => $rol->id,
        'campus_id' => null,
        'activo' => true,
    ]);

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => mb_strtolower($etiqueta).'-'.$sufijo,
        'email' => mb_strtolower($etiqueta).'-'.$sufijo.'@ejemplo.mx',
        'password' => 'irrelevante',
        'rol_activo_id' => $rol->id,
    ]);

    return $usuario->fresh();
}

echo '1. Quién puede suplantar'.PHP_EOL;

verificar('Dirección general sí',
    Rol::where('name', 'director_general')->firstOrFail()->concede('suplantar-usuarios'));
verificar('Control escolar NO',
    ! Rol::where('name', 'encargado_control_escolar')->firstOrFail()->concede('suplantar-usuarios'));
verificar('El docente NO',
    ! Rol::where('name', 'docente')->firstOrFail()->concede('suplantar-usuarios'));
verificar('El alumno NO',
    ! Rol::where('name', 'alumno')->firstOrFail()->concede('suplantar-usuarios'));

DB::beginTransaction();

try {
    $suplantador = app(Suplantador::class);
    $sufijo = substr((string) microtime(true), -6);

    $director = usuarioCon('director_general', $sufijo, 'Director');
    $docente = usuarioCon('docente', $sufijo, 'Docente');
    $otroDirector = usuarioCon('director_general', $sufijo.'b', 'Directora');

    $peticion = Request::create('/', 'POST');
    $peticion->setLaravelSession(app('session.store'));

    echo PHP_EOL.'2. Impedimentos'.PHP_EOL;

    verificar('Se puede suplantar a un docente',
        $suplantador->impedimentos($peticion, $director, $docente) === [],
        implode(' ', $suplantador->impedimentos($peticion, $director, $docente)));

    // Sin esto, suplantar a un par seria la forma de tomar sus permisos sin que
    // nadie te los diera.
    $contraPar = $suplantador->impedimentos($peticion, $director, $otroDirector);
    verificar('NO se puede suplantar a quien también puede suplantar',
        $contraPar !== [], implode(' ', $contraPar));

    $aSiMismo = $suplantador->impedimentos($peticion, $director, $director);
    verificar('NO se puede suplantar a uno mismo', $aSiMismo !== []);

    $sinRol = usuarioCon('docente', $sufijo.'c', 'SinRol');
    $sinRol->forceFill(['rol_activo_id' => null])->save();
    verificar('NO se puede suplantar a una cuenta sin rol activo',
        $suplantador->impedimentos($peticion, $director, $sinRol->fresh()) !== []);

    echo PHP_EOL.'3. Bitácora'.PHP_EOL;

    $antes = Auditoria::where('evento', 'like', 'suplantacion%')->count();

    // Se ejercita el registro sin tocar la sesión real de nadie.
    $registrar = new ReflectionMethod(Suplantador::class, 'registrar');
    $registrar->setAccessible(true);
    $registrar->invoke($suplantador, $peticion, 'suplantacion_inicio', $director, $docente);

    $ultimo = Auditoria::where('evento', 'suplantacion_inicio')->latest('id')->first();

    verificar('Queda registrado el inicio',
        Auditoria::where('evento', 'like', 'suplantacion%')->count() === $antes + 1);
    verificar('…con quién suplantó',
        ($ultimo->valores_nuevos['suplantador'] ?? null) === $director->usuario,
        (string) ($ultimo->valores_nuevos['suplantador'] ?? ''));
    verificar('…y a quién',
        ($ultimo->valores_nuevos['suplantado'] ?? null) === $docente->usuario);
    verificar('El registro cuelga del usuario SUPLANTADO',
        $ultimo->auditable_id === $docente->id,
        'la pregunta que se hace después es "quién entró como esta persona"');
    verificar('Guarda quién lo hizo en usuario_id', $ultimo->usuario_id === $director->id);

    $registrar->invoke($suplantador, $peticion, 'suplantacion_fin', $director, $docente);
    verificar('También queda registrada la salida',
        Auditoria::where('evento', 'suplantacion_fin')->latest('id')->first()?->usuario_id === $director->id);

    echo PHP_EOL.'4. La bitácora es append-only'.PHP_EOL;

    verificar('No tiene updated_at', Auditoria::UPDATED_AT === null);
    verificar('Ni soft delete',
        ! in_array('deleted_at', app(Auditoria::class)->getDates() ?? [], true)
        && ! method_exists(Auditoria::class, 'trashed'));
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
