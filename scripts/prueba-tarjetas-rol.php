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
    // Un usuario cuyo rol activo tiene SOLO "accesos" encendida no debe ver otras.
    $usuario = App\Models\Identidad\Usuario::query()->whereNotNull('rol_activo_id')->first();
    if ($usuario) {
        TarjetaRol::updateOrCreate(['rol_id' => $usuario->rol_activo_id], ['activas' => ['accesos']]);
        $tarjetas = $registro->para($usuario);
        $clavesVisibles = array_column($tarjetas, 'clave');
        verificar('Solo aparecen tarjetas encendidas (subconjunto de {accesos})',
            count(array_diff($clavesVisibles, ['accesos'])) === 0, implode(',', $clavesVisibles));

        // Sin config (restablecido) vuelven a salir varias.
        TarjetaRol::query()->where('rol_id', $usuario->rol_activo_id)->forceDelete();
        $todas = $registro->para($usuario);
        verificar('Sin config, el panel muestra más de una tarjeta', count($todas) >= count($tarjetas));
    } else {
        verificar('(sin usuario con rol activo para probar para())', true);
        verificar('(idem)', true);
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
