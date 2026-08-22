<?php

/**
 * «Y a sus familias»: el modificador que hace que un aviso alcance a los padres
 * de los alumnos destinatarios. Con rollback.
 *
 * Se corre con `php scripts/prueba-aviso-a-familias.php` desde la raíz.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * El destino «alumno» casa contra la persona de QUIEN INICIÓ SESIÓN, así que un
 * citatorio dirigido a Juan le llegaba a Juan y no a su madre. No había forma de
 * mandarle nada a una familia en concreto.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. Con el modificador, el familiar lo recibe. Sin él, NO — es la mitad que
 *     se rompe por exceso: si llegara igual, cualquier aviso a un grupo se le
 *     mandaría a todos los padres sin que nadie lo pidiera.
 *  2. El modificador se CRUZA con los demás destinos y no se suma: un aviso con
 *     «y a sus familias» dirigido a OTRO grupo no le llega a este padre.
 *  3. El alumno sigue recibiendo lo suyo, con modificador o sin él.
 *  4. Un aviso cuyo único destino sea el modificador no se puede guardar.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Enums\DestinoEvento;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Tenant;
use App\Rules\AlMenosUnDestinoReal;
use App\Services\Plataforma\AlcanceDeDestinos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

/** ¿Este usuario alcanza ese aviso? */
function alcanza(Usuario $usuario, int $avisoId): bool
{
    return app(AlcanceDeDestinos::class)
        ->aplicar(Aviso::query()->whereKey($avisoId), $usuario)
        ->exists();
}

function crearAviso(string $titulo, array $destinos): Aviso
{
    $aviso = Aviso::create([
        'titulo' => $titulo,
        'cuerpo' => 'Cuerpo de prueba.',
        'prioridad' => 'informativo',
        'publicado' => true,
        'publicado_desde' => now()->subDay(),
    ]);

    foreach ($destinos as [$tipo, $id]) {
        $aviso->destinos()->create(['tipo' => $tipo, 'destino_id' => $id]);
    }

    return $aviso;
}

$db->beginTransaction();

try {
    // Un padre cuyo hijo esté inscrito en algún grupo: sin grupo no hay
    // segmentación académica que extender y la prueba no distinguiría nada.
    $vinculo = TutorAlumno::query()
        ->whereIn('alumno_persona_id', DB::connection('tenant')->table('matricula_oferta as mo')
            ->join('inscripcion as i', 'i.matricula_oferta_id', '=', 'mo.id')
            ->whereNull('mo.deleted_at')
            ->select('mo.persona_id'))
        ->first();

    if ($vinculo === null) {
        echo 'Esta escuela no tiene ningún tutor cuyo hijo esté inscrito; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $padre = Usuario::query()->where('persona_id', $vinculo->tutor_persona_id)->firstOrFail();
    $hijo = Usuario::query()->where('persona_id', $vinculo->alumno_persona_id)->first();

    $grupo = (int) $db->table('inscripcion as i')
        ->join('asignatura_grupo as ag', 'ag.id', '=', 'i.asignatura_grupo_id')
        ->join('matricula_oferta as mo', 'mo.id', '=', 'i.matricula_oferta_id')
        ->where('mo.persona_id', $vinculo->alumno_persona_id)
        ->value('ag.grupo_id');

    $otroGrupo = (int) $db->table('grupos')->where('id', '!=', $grupo)->value('id');

    verificar('hay dos grupos distintos con los que probar', $otroGrupo > 0 && $otroGrupo !== $grupo,
        "hijo en {$grupo}, otro {$otroGrupo}");

    echo PHP_EOL.'1. Sin el modificador, al padre NO le llega'.PHP_EOL;

    $soloAlumnos = crearAviso('Sin familias', [[DestinoEvento::Grupo->value, $grupo]]);

    verificar('el padre no lo recibe', ! alcanza($padre, $soloAlumnos->id));

    if ($hijo !== null) {
        verificar('pero el hijo sí', alcanza($hijo, $soloAlumnos->id));
    } else {
        verificar('(el hijo no tiene cuenta con la que comprobarlo)', true);
    }

    echo PHP_EOL.'2. Con el modificador, sí le llega'.PHP_EOL;

    $conFamilias = crearAviso('Con familias', [
        [DestinoEvento::Grupo->value, $grupo],
        [DestinoEvento::Familiares->value, null],
    ]);

    verificar('el padre lo recibe', alcanza($padre, $conFamilias->id));

    if ($hijo !== null) {
        verificar('y el hijo lo sigue recibiendo', alcanza($hijo, $conFamilias->id));
    } else {
        verificar('(idem)', true);
    }

    echo PHP_EOL.'3. El modificador se CRUZA, no se suma'.PHP_EOL;

    $deOtroGrupo = crearAviso('Familias de otro grupo', [
        [DestinoEvento::Grupo->value, $otroGrupo],
        [DestinoEvento::Familiares->value, null],
    ]);

    verificar('un aviso a OTRO grupo no le llega aunque lleve el modificador',
        ! alcanza($padre, $deOtroGrupo->id));

    echo PHP_EOL.'4. También funciona señalando al alumno por su nombre'.PHP_EOL;

    $alHijo = crearAviso('Citatorio', [
        [DestinoEvento::Alumno->value, $vinculo->alumno_persona_id],
        [DestinoEvento::Familiares->value, null],
    ]);

    verificar('el citatorio del hijo le llega a su tutor', alcanza($padre, $alHijo->id));

    $sinModificador = crearAviso('Citatorio sin familias', [
        [DestinoEvento::Alumno->value, $vinculo->alumno_persona_id],
    ]);

    verificar('y sin el modificador, no', ! alcanza($padre, $sinModificador->id));

    echo PHP_EOL.'5. El modificador solo no es un destino'.PHP_EOL;

    $validador = Validator::make(
        ['destinos' => [['tipo' => DestinoEvento::Familiares->value, 'destino_id' => null]]],
        ['destinos' => ['required', 'array', 'min:1', new AlMenosUnDestinoReal]],
    );

    verificar('un aviso sólo con «y a sus familias» se rechaza', $validador->fails());

    $conReal = Validator::make(
        ['destinos' => [
            ['tipo' => DestinoEvento::Grupo->value, 'destino_id' => $grupo],
            ['tipo' => DestinoEvento::Familiares->value, 'destino_id' => null],
        ]],
        ['destinos' => ['required', 'array', 'min:1', new AlMenosUnDestinoReal]],
    );

    verificar('acompañado de un destino real, se acepta', ! $conReal->fails());
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
