<?php

/**
 * Editor de menú por rol: guardar la disposición (saneada a 3 niveles, sin
 * basura), restablecer (borra la fila) e index (expone la estructura por rol).
 * Contra la BD real, con rollback.
 *
 * `php scripts/prueba-menu-rol.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\MenuRolController;
use App\Models\Identidad\MenuRol;
use App\Models\Identidad\Rol;
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
    $ctrl = new MenuRolController;
    $rol = Rol::query()->firstOrFail();

    echo '1. Guardar una disposición anidada la persiste'.PHP_EOL;
    $ctrl->guardar(req([
        'estructura' => [
            ['clave' => 'escolar', 'hijos' => [
                ['clave' => 'ciclos', 'hijos' => []],
                ['clave' => 'docentes', 'hijos' => [
                    ['clave' => 'docentes-listado', 'hijos' => []],
                ]],
            ]],
            ['clave' => 'admisiones', 'hijos' => []],
        ],
        'ocultos' => [],
    ]), $rol);

    $guardado = MenuRol::query()->where('rol_id', $rol->id)->value('estructura');
    verificar('Se guardó la fila del rol', $guardado !== null);
    verificar('Docentes quedó anidado dentro de Control escolar',
        ($guardado[0]['clave'] ?? null) === 'escolar'
        && ($guardado[0]['hijos'][1]['clave'] ?? null) === 'docentes'
        && ($guardado[0]['hijos'][1]['hijos'][0]['clave'] ?? null) === 'docentes-listado');

    echo PHP_EOL.'2. El saneado recorta a 3 niveles y descarta basura'.PHP_EOL;
    $ctrl->guardar(req([
        'estructura' => [
            // 4 niveles de profundidad: el 4º se debe recortar.
            ['clave' => 'a1', 'hijos' => [
                ['clave' => 'b2', 'hijos' => [
                    ['clave' => 'c3', 'hijos' => [
                        ['clave' => 'd4', 'hijos' => []],
                    ]],
                ]],
            ]],
            // Nodo basura sin clave: se descarta.
            ['hijos' => []],
            ['clave' => 123, 'hijos' => []], // clave no-string: se descarta
        ],
        'ocultos' => [],
    ]), $rol);
    $s = MenuRol::query()->where('rol_id', $rol->id)->value('estructura');
    verificar('Solo quedó el nodo con clave válida', count($s) === 1 && $s[0]['clave'] === 'a1');
    verificar('El 3er nivel existe pero el 4º se recortó',
        ($s[0]['hijos'][0]['hijos'][0]['clave'] ?? null) === 'c3'
        && ($s[0]['hijos'][0]['hijos'][0]['hijos'] ?? null) === []);

    echo PHP_EOL.'3. Guardar ocultos los persiste (dedup)'.PHP_EOL;
    $ctrl->guardar(req([
        'estructura' => [['clave' => 'escolar', 'hijos' => []]],
        'ocultos' => ['docentes', 'finanzas', 'docentes'], // duplicado
    ]), $rol);
    $fila = MenuRol::query()->where('rol_id', $rol->id)->first();
    verificar('Guardó los ocultos sin duplicar', $fila->ocultos === ['docentes', 'finanzas']);

    echo PHP_EOL.'4. index() expone estructura, ocultos, ámbito y permisos por rol'.PHP_EOL;
    $reqI = req([], 'GET');
    $reqI->headers->set('X-Inertia', 'true');
    $props = $ctrl->index()->toResponse($reqI)->getData(true)['props'];
    $rolEnLista = collect($props['roles'])->firstWhere('id', $rol->id);
    verificar('El rol viene con su estructura y ocultos', ($rolEnLista['estructura'][0]['clave'] ?? null) === 'escolar' && $rolEnLista['ocultos'] === ['docentes', 'finanzas']);
    verificar('Cada rol trae su ámbito', is_string($rolEnLista['ambito'] ?? null) && $rolEnLista['ambito'] !== '');
    verificar('Cada rol trae la lista de sus permisos', is_array($rolEnLista['permisos'] ?? null));
    /*
     * Un rol SIN menú guardado viene con `estructura` null, que es lo que hace
     * que el editor parta del árbol por omisión.
     *
     * Esto pedía que TODOS los demás vinieran en null, y dejó de ser cierto en
     * cuanto alguien configuró el menú de dos roles desde la pantalla: la
     * prueba fallaba por haber usado la función que prueba. Ahora se pregunta
     * por los que de verdad no tienen fila.
     */
    $conMenuGuardado = MenuRol::query()->pluck('rol_id')->all();

    verificar('Un rol sin menú guardado viene con estructura null',
        collect($props['roles'])
            ->reject(fn ($r) => in_array($r['id'], $conMenuGuardado, true))
            ->every(fn ($r) => $r['estructura'] === null));

    echo PHP_EOL.'5. Restablecer borra la fila (vuelve al default)'.PHP_EOL;
    $ctrl->restablecer($rol);
    verificar('Ya no hay fila para el rol', MenuRol::query()->where('rol_id', $rol->id)->doesntExist());
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
