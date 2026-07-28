<?php

/**
 * Catálogo de Área con color hexadecimal: al alta sin color se genera un pastel
 * aleatorio; con color se respeta; un hex inválido se rechaza; y el índice
 * expone el color por ítem + los metadatos `extras`. Contra la BD real, con
 * rollback.
 *
 * `php scripts/prueba-catalogo-area-color.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CatalogoAcademicoController;
use App\Models\Academico\Area;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

function req(array $datos): Request
{
    $r = Request::create('/', 'POST', $datos);
    $r->setUserResolver(fn () => Auth::user());
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    $admin = Usuario::query()->whereHas('persona.asignacionesRol.rol', fn ($q) => $q->where('name', 'super-admin'))->first()
        ?? Usuario::query()->first();
    Auth::login($admin);

    $ctrl = new CatalogoAcademicoController;
    $u = substr(uniqid(), -6);

    echo '1. Alta SIN color → pastel aleatorio'.PHP_EOL;
    $ctrl->store(req(['clave' => "SIN-$u", 'nombre' => 'Área sin color']), 'area');
    $sin = Area::query()->where('clave', "SIN-$u")->first();
    verificar('Se creó el área', $sin !== null);
    verificar('Recibió un color hex válido', (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $sin?->color), (string) $sin?->color);
    // Pastel = cada canal ≥ 127 (mezcla a medias con blanco).
    $canales = $sin ? sscanf($sin->color, '#%02x%02x%02x') : [0, 0, 0];
    verificar('El tono es pastel (todos los canales ≥ 127)', min($canales) >= 127, implode(',', $canales));

    echo PHP_EOL.'2. Alta CON color → se respeta'.PHP_EOL;
    $ctrl->store(req(['clave' => "CON-$u", 'nombre' => 'Área con color', 'color' => '#A3D9C7']), 'area');
    $con = Area::query()->where('clave', "CON-$u")->first();
    verificar('Guardó el color capturado', $con?->color === '#A3D9C7');

    echo PHP_EOL.'3. Color inválido se rechaza'.PHP_EOL;
    $rechazado = false;
    try {
        $ctrl->store(req(['clave' => "MAL-$u", 'nombre' => 'Área mal', 'color' => 'rojo']), 'area');
    } catch (ValidationException $e) {
        $rechazado = true;
    }
    verificar('Rechaza un hex inválido', $rechazado);
    verificar('No creó el área inválida', Area::query()->where('clave', "MAL-$u")->doesntExist());

    echo PHP_EOL.'4. index() expone color por ítem + extras del catálogo de área'.PHP_EOL;
    $reqI = req([]);
    $reqI->headers->set('X-Inertia', 'true');
    $props = $ctrl->index($reqI)->toResponse($reqI)->getData(true)['props'];
    $areaCat = collect($props['catalogos'])->firstWhere('clave', 'area');
    verificar('El catálogo de área declara extra color', isset($areaCat['extras']['color']));
    $item = collect($areaCat['items'])->firstWhere('clave', "CON-$u");
    verificar('El ítem trae su color', ($item['color'] ?? null) === '#A3D9C7');
    $clasifCat = collect($props['catalogos'])->firstWhere('clave', 'clasificacion');
    verificar('Un catálogo simple NO trae extras', empty($clasifCat['extras']));
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
