<?php

/**
 * Editor del panel por rol: catálogo de tarjetas, guardar las encendidas
 * (saneadas al catálogo y sin duplicar), index con permisos/activas por rol,
 * restablecer, y que RegistroTarjetas::para respete la activación del rol activo.
 * Contra la BD real, con rollback.
 *
 * `php scripts/prueba-tarjetas-rol.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\TarjetaRolController;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TarjetaRol;
use App\Panel\RegistroTarjetas;
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

function req(array $datos, string $metodo = 'PUT'): Request
{
    $r = Request::create('/', $metodo, $datos);
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    $registro = app(RegistroTarjetas::class);
    $ctrl = new TarjetaRolController($registro);
    $rol = Rol::query()->firstOrFail();

    echo '1. El catálogo trae las tarjetas registradas'.PHP_EOL;
    $catalogo = $registro->catalogo();
    $claves = array_column($catalogo, 'clave');
    verificar('Hay tarjetas en el catálogo', count($catalogo) >= 5, (string) count($catalogo));
    verificar('Cada tarjeta trae clave, título e icono', isset($catalogo[0]['clave'], $catalogo[0]['titulo'], $catalogo[0]['icono']));
    verificar('Incluye "cartera" y "embudo"', in_array('cartera', $claves, true) && in_array('embudo', $claves, true));

    echo PHP_EOL.'2. Guardar sanea al catálogo y quita duplicados'.PHP_EOL;
    $ctrl->guardar(req(['activas' => ['cartera', 'embudo', 'cartera', 'inexistente']]), $rol);
    $activas = TarjetaRol::query()->where('rol_id', $rol->id)->value('activas');
    verificar('Guardó solo claves válidas y únicas', $activas === ['cartera', 'embudo'], implode(',', $activas));

    echo PHP_EOL.'3. index() expone catálogo y roles con permisos/activas'.PHP_EOL;
    $reqI = req([], 'GET');
    $reqI->headers->set('X-Inertia', 'true');
    $props = $ctrl->index()->toResponse($reqI)->getData(true)['props'];
    verificar('Manda el catálogo', is_array($props['catalogo']) && count($props['catalogo']) === count($catalogo));
    $rolEnLista = collect($props['roles'])->firstWhere('id', $rol->id);
    verificar('El rol trae sus activas', ($rolEnLista['activas'] ?? null) === ['cartera', 'embudo']);
    verificar('El rol trae su lista de permisos', is_array($rolEnLista['permisos'] ?? null));
    verificar('Otros roles vienen con activas null', collect($props['roles'])->where('id', '!=', $rol->id)->every(fn ($r) => $r['activas'] === null));

    echo PHP_EOL.'4. para() respeta la activación del rol activo'.PHP_EOL;
    /*
     * Se enciende UNA de las que esa persona ya ve, y se exige que quede
     * exactamente ésa.
     *
     * Antes se encendía una clave fija —«accesos»— y se comprobaba que lo
     * visible fuera un SUBCONJUNTO de ella. Eso también se cumple cuando no
     * queda nada visible, así que el día que la clave dejara de existir la
     * prueba seguiría en verde sin comprobar nada. Y dejó de existir: la
     * tarjeta se retiró, y esta prueba pasó igual. Ahora la clave sale de lo
     * que el usuario ve de verdad y se pide que quede una, no «como mucho una».
     */
    /*
     * Y hay que BUSCAR a alguien que vea tarjetas, no tomar al primero.
     *
     * Tomando el primero salía `staff.centro`, cuyo rol veía CERO —el hueco que
     * motivó todo este lote de tarjetas nuevas—, así que la prueba se iba por
     * la rama del else y aprobaba dos verificaciones vacías. Se comprobó
     * mutando `RegistroTarjetas` para que ignorara el apagado: seguía en verde.
     *
     * Si NADIE en la escuela ve una sola tarjeta, eso no es un caso a saltarse:
     * es un panel roto, y la prueba lo dice en rojo.
     */
    $usuario = null;
    $todasAntes = [];

    foreach (App\Models\Identidad\Usuario::query()->whereNotNull('rol_activo_id')->limit(60)->get() as $candidato) {
        $suyas = $registro->para($candidato);

        if ($suyas !== []) {
            $usuario = $candidato;
            $todasAntes = $suyas;
            break;
        }
    }

    verificar('Hay al menos una persona que ve tarjetas en su panel',
        $usuario !== null, $usuario?->usuario ?? 'ninguna');

    if ($usuario !== null) {
        $elegida = $todasAntes[0]['clave'];

        TarjetaRol::updateOrCreate(['rol_id' => $usuario->rol_activo_id], ['activas' => [$elegida]]);
        $clavesVisibles = array_column($registro->para($usuario), 'clave');

        verificar("Encendida sólo «{$elegida}», es la única que sale",
            $clavesVisibles === [$elegida], implode(',', $clavesVisibles));

        // Sin config (restablecido) vuelven a salir todas las permitidas.
        TarjetaRol::query()->where('rol_id', $usuario->rol_activo_id)->forceDelete();
        verificar('Sin config, el panel vuelve a mostrar todas las permitidas',
            count($registro->para($usuario)) === count($todasAntes));
    } else {
        verificar('(no se pudo probar el apagado: nadie ve tarjetas)', false);
        verificar('(idem)', false);
    }

    echo PHP_EOL.'5. Restablecer borra la fila (vuelve al default)'.PHP_EOL;
    $ctrl->guardar(req(['activas' => ['cartera']]), $rol);
    $ctrl->restablecer($rol);
    verificar('Ya no hay fila para el rol', TarjetaRol::query()->where('rol_id', $rol->id)->doesntExist());
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
