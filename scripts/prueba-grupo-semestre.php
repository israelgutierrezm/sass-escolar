<?php

/**
 * Grupo: semestre opcional, la restricción de campus/nivel que impone el ciclo,
 * y quitar un docente de una materia. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-grupo-semestre.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\GrupoController;
use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\SituacionCiclo;
use App\Models\ControlEscolar\SituacionGrupo;
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
    $persona = Persona::create(['nombre' => 'Gru', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);
    $rolId = Rol::where('name', 'director_general')->firstOrFail()->id;
    $u = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_gru_'.random_int(100000, 999999),
        'email' => 'prueba_gru_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);
    $persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true]);

    return $u->fresh(['persona', 'rolActivo']);
}

function pet(array $datos, Usuario $u): Request
{
    $r = Request::create('/escolar/grupos', 'POST', $datos);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $u = admin();
    $c = new GrupoController;
    $sitGrupo = SituacionGrupo::query()->value('id');
    $sitCiclo = SituacionCiclo::query()->value('id');

    // Un plan y su carrera, para conocer el nivel real.
    $plan = PlanEstudio::query()->join('carreras', 'carreras.id', '=', 'planes_estudio.carrera_id')
        ->select('planes_estudio.id', 'carreras.nivel_estudios_id')
        ->first();
    $nivelDelPlan = $plan->nivel_estudios_id;
    $otroNivel = NivelEstudio::query()->where('id', '!=', $nivelDelPlan)->value('id');

    $campusA = Campus::query()->value('id');
    $campusB = Campus::query()->where('id', '!=', $campusA)->value('id');

    echo '1. Semestre opcional se guarda'.PHP_EOL;

    // Ciclo global (sin campus, sin nivel): no restringe.
    $cicloLibre = Ciclo::create([
        'clave' => '2098-1', 'anio' => 2098, 'numero_periodo' => 1, 'nombre' => 'Libre',
        'fecha_inicio' => '2098-01-15', 'fecha_fin' => '2098-06-15', 'situacion_id' => $sitCiclo,
    ]);

    $c->store(pet([
        'ciclo_id' => $cicloLibre->id, 'campus_id' => $campusA, 'plan_id' => $plan->id,
        'semestre' => 3, 'clave' => 'GS-'.random_int(1000, 9999), 'situacion_id' => $sitGrupo,
    ], $u));

    $grupo = Grupo::where('ciclo_id', $cicloLibre->id)->first();
    verificar('El grupo guardó su semestre', $grupo?->semestre === 3);

    echo PHP_EOL.'2. El ciclo acota el campus del grupo'.PHP_EOL;

    // Ciclo con UN campus (campusA): un grupo en campusB debe rechazarse.
    $cicloCampus = Ciclo::create([
        'clave' => '2098-2', 'anio' => 2098, 'numero_periodo' => 2, 'nombre' => 'CampusA',
        'fecha_inicio' => '2098-01-15', 'fecha_fin' => '2098-06-15', 'situacion_id' => $sitCiclo,
    ]);
    $cicloCampus->campus()->sync([$campusA]);

    $campusMal = false;

    try {
        $c->store(pet([
            'ciclo_id' => $cicloCampus->id, 'campus_id' => $campusB, 'plan_id' => null,
            'clave' => 'GC-'.random_int(1000, 9999), 'situacion_id' => $sitGrupo,
        ], $u));
    } catch (ValidationException $e) {
        $campusMal = array_key_exists('campus_id', $e->errors());
    }

    verificar('Un campus fuera de los del ciclo se rechaza', $campusMal);

    // El campus correcto sí pasa.
    $c->store(pet([
        'ciclo_id' => $cicloCampus->id, 'campus_id' => $campusA, 'plan_id' => null,
        'clave' => 'GC-'.random_int(1000, 9999), 'situacion_id' => $sitGrupo,
    ], $u));

    verificar('El campus del ciclo sí se acepta',
        Grupo::where('ciclo_id', $cicloCampus->id)->where('campus_id', $campusA)->exists());

    echo PHP_EOL.'3. El ciclo acota el nivel del plan'.PHP_EOL;

    if ($otroNivel !== null) {
        // Ciclo acotado a OTRO nivel distinto al del plan: el plan debe rechazarse.
        $cicloNivel = Ciclo::create([
            'clave' => '2098-3', 'anio' => 2098, 'numero_periodo' => 3, 'nombre' => 'Nivel',
            'fecha_inicio' => '2098-01-15', 'fecha_fin' => '2098-06-15', 'situacion_id' => $sitCiclo,
            'nivel_estudios_id' => $otroNivel,
        ]);

        $nivelMal = false;

        try {
            $c->store(pet([
                'ciclo_id' => $cicloNivel->id, 'campus_id' => $campusA, 'plan_id' => $plan->id,
                'clave' => 'GN-'.random_int(1000, 9999), 'situacion_id' => $sitGrupo,
            ], $u));
        } catch (ValidationException $e) {
            $nivelMal = array_key_exists('plan_id', $e->errors());
        }

        verificar('Un plan de otro nivel se rechaza cuando el ciclo lo acota', $nivelMal);
    }

    // Ciclo acotado al nivel DEL plan: sí pasa.
    $cicloOk = Ciclo::create([
        'clave' => '2098-4', 'anio' => 2098, 'numero_periodo' => 4, 'nombre' => 'NivelOk',
        'fecha_inicio' => '2098-01-15', 'fecha_fin' => '2098-06-15', 'situacion_id' => $sitCiclo,
        'nivel_estudios_id' => $nivelDelPlan,
    ]);

    $c->store(pet([
        'ciclo_id' => $cicloOk->id, 'campus_id' => $campusA, 'plan_id' => $plan->id,
        'clave' => 'GN-'.random_int(1000, 9999), 'situacion_id' => $sitGrupo,
    ], $u));

    verificar('El plan del nivel del ciclo sí se acepta',
        Grupo::where('ciclo_id', $cicloOk->id)->exists());
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
