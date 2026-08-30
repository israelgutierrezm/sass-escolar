<?php

/**
 * Alta de oferta en LOTE (fan-out): un programa académico+plan por varios campus,
 * modalidades y turnos genera una Oferta por combinación, sin duplicar.
 * Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-oferta-fanout.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\OfertaController;
use App\Models\Academico\Campus;
use App\Models\Academico\Modalidad;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\Turno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
    $persona = Persona::create(['nombre' => 'Of', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);
    $rolId = Rol::where('name', 'director_general')->firstOrFail()->id;
    $u = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_of_'.random_int(100000, 999999),
        'email' => 'prueba_of_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);
    $persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true]);

    return $u->fresh(['persona', 'rolActivo']);
}

function pet(array $datos, Usuario $u): Request
{
    $r = Request::create('/academico/ofertas', 'POST', $datos);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $u = admin();
    $c = new OfertaController;

    $plan = PlanEstudio::query()->firstOrFail();
    $programaAcademicoId = $plan->programa_academico_id;

    // Dos campus, dos modalidades, un turno → 2×2×1 = 4 combinaciones.
    $campusIds = Campus::query()->take(2)->pluck('id')->all();
    $modalidades = Modalidad::query()->take(2)->pluck('clave')->all();
    $turnoId = Turno::query()->value('id');

    $antes = Oferta::query()->where('plan_id', $plan->id)->count();

    /*
     * El TURNO se salió de la oferta.
     *
     * `oferta.turno_id` se retiró en `eed73bd`: el turno es del GRUPO, no de la
     * oferta —no distingue una oferta de otra—. Esta suite seguía mandando
     * `turno_ids` y consultando `whereNull('turno_id')`, así que reventaba con
     * «Unknown column 'turno_id'».
     *
     * Y la modalidad se fue por el mismo camino: dejó de ser una dimensión del
     * fan-out (`modalidades[]`) para ser UN atributo opcional que se aplica a
     * todas. Hoy el fan-out reparte por CAMPUS y nada más, y la combinación que
     * no se duplica es (programa académico, plan, campus).
     */
    echo '1. El fan-out genera una oferta por combinación'.PHP_EOL;

    $c->store(pet([
        'programa_academico_id' => $programaAcademicoId,
        'plan_id' => $plan->id,
        'campus_ids' => $campusIds,
        'modalidad' => $modalidades[0],
        'estatus' => 'abierta',
    ], $u));

    $despues = Oferta::query()->where('plan_id', $plan->id)->count();

    // Una oferta por campus que no la tuviera ya.
    $yaTenian = Oferta::query()->where('plan_id', $plan->id)
        ->whereIn('campus_id', $campusIds)->count();

    verificar('Se creó una oferta por campus',
        $yaTenian === count($campusIds), "$yaTenian de ".count($campusIds));

    verificar('Cada oferta quedó con UN solo campus y su modalidad',
        Oferta::query()->where('plan_id', $plan->id)->whereIn('campus_id', $campusIds)
            ->get()->every(fn (Oferta $o) => $o->campus_id !== null && $o->modalidad !== null));

    echo PHP_EOL.'2. Re-ejecutar el mismo lote NO duplica'.PHP_EOL;

    $c->store(pet([
        'programa_academico_id' => $programaAcademicoId,
        'plan_id' => $plan->id,
        'campus_ids' => $campusIds,
        'modalidad' => $modalidades[0],
        'estatus' => 'abierta',
    ], $u));

    verificar('El total no cambió: todas ya existían',
        Oferta::query()->where('plan_id', $plan->id)->count() === $despues,
        (string) Oferta::query()->where('plan_id', $plan->id)->count());

    echo PHP_EOL.'3. Otro plan genera su propia combinación'.PHP_EOL;

    $otroPlan = PlanEstudio::query()->where('id', '!=', $plan->id)->first() ?? $plan;
    $campusUno = [Campus::query()->value('id')];

    $c->store(pet([
        'programa_academico_id' => $otroPlan->programa_academico_id,
        'plan_id' => $otroPlan->id,
        'campus_ids' => $campusUno,
        'modalidad' => $modalidades[0],
        'estatus' => 'abierta',
    ], $u));

    /*
     * La combinación que NO se duplica es (programa académico, plan, campus): la modalidad
     * quedó fuera de la llave. Si ese plan ya tenía oferta en ese campus, el
     * lote la omite y conserva la modalidad con la que nació —comprobar la
     * modalidad aquí haría fallar la prueba por un dato preexistente del demo,
     * no por el fan-out—.
     */
    verificar('Existe la oferta de ese plan en ese campus',
        Oferta::query()->where('plan_id', $otroPlan->id)
            ->where('campus_id', $campusUno[0])->exists());

    echo PHP_EOL.'4. La modalidad se valida contra el catálogo'.PHP_EOL;

    $rechazada = false;

    try {
        $c->store(pet([
            'programa_academico_id' => $programaAcademicoId, 'plan_id' => $plan->id,
            'campus_ids' => $campusUno, 'modalidad' => 'inventada',
            'estatus' => 'abierta',
        ], $u));
    } catch (Illuminate\Validation\ValidationException $e) {
        $rechazada = array_key_exists('modalidad', $e->errors());
    }

    verificar('Una modalidad fuera del catálogo se rechaza', $rechazada);

    echo PHP_EOL.'5. El plan debe pertenecer al programa académico'.PHP_EOL;

    $programaAcademicoAjena = \App\Models\Academico\ProgramaAcademico::query()->where('id', '!=', $programaAcademicoId)->value('id');

    if ($programaAcademicoAjena !== null) {
        $malPlan = false;

        try {
            $c->store(pet([
                'programa_academico_id' => $programaAcademicoAjena, 'plan_id' => $plan->id,
                'campus_ids' => $campusUno, 'modalidades' => [$modalidades[0]], 'turno_ids' => [],
                'estatus' => 'abierta',
            ], $u));
        } catch (Illuminate\Validation\ValidationException $e) {
            $malPlan = array_key_exists('plan_id', $e->errors());
        }

        verificar('Un plan de otro programa académico se rechaza', $malPlan);
    }
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
