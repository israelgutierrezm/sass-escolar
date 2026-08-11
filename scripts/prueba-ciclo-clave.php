<?php

/**
 * Ciclo: la clave se genera de año + número de periodo, y el nivel de estudios
 * opcional. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-ciclo-clave.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CicloController;
use App\Models\Academico\NivelEstudio;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\SituacionCiclo;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

function admin(): Usuario
{
    $persona = Persona::create(['nombre' => 'Cic', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);
    $rolId = Rol::where('name', 'director_general')->firstOrFail()->id;
    $u = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_cic_'.random_int(100000, 999999),
        'email' => 'prueba_cic_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);
    $persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true]);

    return $u->fresh(['persona', 'rolActivo']);
}

function pet(array $datos, Usuario $u, string $metodo = 'POST', string $uri = '/escolar/ciclos'): Request
{
    $r = Request::create($uri, $metodo, $datos);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $u = admin();
    $c = new CicloController;
    $situacion = SituacionCiclo::query()->value('id');
    /*
     * Por NOMBRE y no por clave: en el catálogo del TENANT la clave es el
     * número que usa la SEP («81»), no la palabra. Buscar 'licenciatura' ahí
     * devuelve null y el ciclo se manda sin nivel —que la validación ya no
     * acepta—. Es la misma trampa que mordió a `acadion:oferta-demo`.
     */
    $nivel = NivelEstudio::query()->where('nombre', 'Licenciatura')->value('id')
        ?? NivelEstudio::query()->value('id');

    echo '1. La clave se genera de año + periodo'.PHP_EOL;

    $anio = random_int(2030, 2099); // un año sin ciclos, para no chocar
    /*
     * El campus es OBLIGATORIO desde `eed73bd` («ciclos: campus obligatorio»).
     * Antes «sin campus» significaba ciclo global y la prueba mandaba `[]`; hoy
     * eso lo rechaza la validación antes de llegar a la clave, que es lo que
     * esta suite comprueba.
     */
    $campusPrueba = [App\Models\Academico\Campus::query()->value('id')];

    $c->store(pet([
        'campus_ids' => $campusPrueba,
        'anio' => $anio,
        'numero_periodo' => 3,
        'nivel_ids' => [$nivel],
        'nombre' => 'Ciclo de prueba',
        'fecha_inicio' => "$anio-01-15",
        'fecha_fin' => "$anio-06-15",
        'situacion_id' => $situacion,
    ], $u));

    $creado = Ciclo::where('anio', $anio)->where('numero_periodo', 3)->first();

    verificar('La clave es «AÑO-PERIODO» con guión', $creado?->clave === "$anio-3", $creado?->clave);
    verificar('Guardó año y número de periodo', $creado?->anio === $anio && $creado?->numero_periodo === 3);
    verificar('Guardó el nivel de estudios ligado (pivote)', $creado?->niveles()->pluck('niveles_estudio.id')->contains($nivel));

    echo PHP_EOL.'2. La clave no se puede repetir'.PHP_EOL;

    $choco = false;

    try {
        $c->store(pet([
            'campus_ids' => $campusPrueba, 'anio' => $anio, 'numero_periodo' => 3,
            'nombre' => 'Otro', 'fecha_inicio' => "$anio-01-15", 'fecha_fin' => "$anio-06-15",
            'situacion_id' => $situacion,
        ], $u));
    } catch (ValidationException $e) {
        $choco = array_key_exists('numero_periodo', $e->errors());
    }

    verificar('Mismo año+periodo se rechaza (clave duplicada)', $choco);

    echo PHP_EOL.'3. Validaciones de año y periodo'.PHP_EOL;

    $anioMal = false;

    try {
        $c->store(pet([
            'campus_ids' => $campusPrueba, 'anio' => 26, 'numero_periodo' => 1,
            'nombre' => 'X', 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-06-15',
            'situacion_id' => $situacion,
        ], $u));
    } catch (ValidationException $e) {
        $anioMal = array_key_exists('anio', $e->errors());
    }

    verificar('Un año que no es de 4 dígitos se rechaza', $anioMal);

    $periodoMal = false;

    try {
        $c->store(pet([
            'campus_ids' => $campusPrueba, 'anio' => $anio, 'numero_periodo' => 7,
            'nombre' => 'X', 'fecha_inicio' => "$anio-01-15", 'fecha_fin' => "$anio-06-15",
            'situacion_id' => $situacion,
        ], $u));
    } catch (ValidationException $e) {
        $periodoMal = array_key_exists('numero_periodo', $e->errors());
    }

    verificar('Un periodo fuera de 1..4 se rechaza', $periodoMal);

    echo PHP_EOL.'4. El nivel es opcional'.PHP_EOL;

    $anio2 = $anio + 1;
    $c->store(pet([
        'campus_ids' => $campusPrueba, 'anio' => $anio2, 'numero_periodo' => 1,
        'nombre' => 'Sin nivel', 'fecha_inicio' => "$anio2-01-15", 'fecha_fin' => "$anio2-06-15",
        'situacion_id' => $situacion,
    ], $u));

    verificar('Se crea sin nivel (cualquier nivel)',
        Ciclo::where('anio', $anio2)->where('numero_periodo', 1)->first()?->niveles()->doesntExist());

    echo PHP_EOL.'5. El ciclo admite VARIOS niveles a la vez'.PHP_EOL;

    $dosNiveles = NivelEstudio::query()->orderBy('id')->take(2)->pluck('id')->all();
    $anio3 = $anio + 2;

    $c->store(pet([
        'campus_ids' => $campusPrueba, 'anio' => $anio3, 'numero_periodo' => 1,
        'nivel_ids' => $dosNiveles,
        'nombre' => 'Dos niveles', 'fecha_inicio' => "$anio3-01-15", 'fecha_fin' => "$anio3-06-15",
        'situacion_id' => $situacion,
    ], $u));

    $multi = Ciclo::where('anio', $anio3)->where('numero_periodo', 1)->first();

    verificar('Guarda los dos niveles marcados',
        $multi?->niveles()->count() === 2
        && collect($dosNiveles)->every(fn ($n) => $multi->niveles()->pluck('niveles_estudio.id')->contains($n)));
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
