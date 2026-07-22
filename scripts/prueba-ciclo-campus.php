<?php

/**
 * Prueba de integración del ciclo multi-campus y del alcance por rol, contra la
 * base REAL del tenant demo. Todo dentro de una transacción con rollback.
 *
 * Se corre con `php scripts/prueba-ciclo-campus.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Academico\Campus;
use App\Models\Academico\TipoCampus;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\SituacionCiclo;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
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

DB::beginTransaction();

try {
    $tipo = TipoCampus::firstOrFail();
    $sufijo = substr((string) microtime(true), -6);

    $norte = Campus::create(['clave' => "N{$sufijo}", 'nombre' => 'Campus Norte', 'tipo_campus_id' => $tipo->id]);
    $sur = Campus::create(['clave' => "S{$sufijo}", 'nombre' => 'Campus Sur', 'tipo_campus_id' => $tipo->id]);
    $este = Campus::create(['clave' => "E{$sufijo}", 'nombre' => 'Campus Este', 'tipo_campus_id' => $tipo->id]);

    $situacion = SituacionCiclo::firstOrFail();

    $base = [
        'nombre' => 'Ciclo de prueba',
        'fecha_inicio' => '2026-08-01',
        'fecha_fin' => '2026-12-15',
        'situacion_id' => $situacion->id,
    ];

    $dosCampus = Ciclo::create([...$base, 'clave' => "P{$sufijo}-A"]);
    $dosCampus->campus()->sync([$norte->id, $sur->id]);

    $soloEste = Ciclo::create([...$base, 'clave' => "P{$sufijo}-B"]);
    $soloEste->campus()->sync([$este->id]);

    $global = Ciclo::create([...$base, 'clave' => "P{$sufijo}-G"]);

    echo PHP_EOL.'1. Un ciclo en varios campus'.PHP_EOL;

    verificar('El ciclo guarda 2 campus', $dosCampus->campus()->count() === 2);
    verificar('Un ciclo sin campus es global', $global->fresh()->esGlobal());
    verificar('Un ciclo con campus no es global', ! $dosCampus->fresh()->esGlobal());

    // La clave ahora es única en toda la escuela, no por campus.
    $claveRepetida = false;
    try {
        Ciclo::create([...$base, 'clave' => "P{$sufijo}-A"]);
    } catch (Throwable) {
        $claveRepetida = true;
    }
    verificar('La clave del ciclo es única en la escuela', $claveRepetida);

    echo PHP_EOL.'2. Alcance por campus'.PHP_EOL;

    $delNorte = Ciclo::query()->delAlcance([$norte->id])->pluck('clave')->all();

    verificar('Quien administra Norte ve su ciclo', in_array("P{$sufijo}-A", $delNorte, true));
    verificar('…y también los globales de la escuela', in_array("P{$sufijo}-G", $delNorte, true));
    verificar('…pero NO el ciclo que solo es de Este',
        ! in_array("P{$sufijo}-B", $delNorte, true), implode(', ', $delNorte));

    $todos = Ciclo::query()->delAlcance(null)->pluck('clave')->all();
    verificar('Con alcance global se ven todos',
        in_array("P{$sufijo}-A", $todos, true)
        && in_array("P{$sufijo}-B", $todos, true)
        && in_array("P{$sufijo}-G", $todos, true));

    verificar('scopeParaCampus sigue sirviendo (campus + globales)',
        Ciclo::query()->paraCampus($este->id)->pluck('clave')->contains("P{$sufijo}-B"));

    echo PHP_EOL.'3. El alcance sale de persona_rol'.PHP_EOL;

    $usuario = Usuario::first();
    $rolAcotado = Rol::where('name', 'director_campus')->firstOrFail();

    // Se limpia cualquier asignación previa de ese rol para aislar la prueba.
    PersonaRol::where('persona_id', $usuario->persona_id)->where('rol_id', $rolAcotado->id)->delete();
    PersonaRol::create([
        'persona_id' => $usuario->persona_id,
        'rol_id' => $rolAcotado->id,
        'campus_id' => $norte->id,
        'activo' => true,
    ]);

    $usuario->forceFill(['rol_activo_id' => $rolAcotado->id])->save();
    $usuario->refresh();

    verificar('Un rol acotado devuelve sus campus',
        $usuario->campusVisibles() === [$norte->id], json_encode($usuario->campusVisibles()));
    verificar('Alcanza el campus que administra', $usuario->alcanzaCampus($norte->id));
    verificar('NO alcanza un campus ajeno', ! $usuario->alcanzaCampus($sur->id));

    $rolGlobal = Rol::where('name', 'director_general')->firstOrFail();
    $usuario->forceFill(['rol_activo_id' => $rolGlobal->id])->save();
    $usuario->refresh();

    verificar('Un rol sin campus tiene alcance global (null, no vacío)',
        $usuario->campusVisibles() === null);
    verificar('Con alcance global alcanza cualquier campus', $usuario->alcanzaCampus($sur->id));

    echo PHP_EOL.'4. Sincronizar sin perder lo ajeno'.PHP_EOL;

    // Simula lo que hace el controlador: un administrador de Norte edita un
    // ciclo que también aplica en Sur. Solo sincroniza lo suyo y conserva Sur.
    $suyos = [$norte->id];
    $ajenos = $dosCampus->campus()->pluck('campus.id')
        ->reject(fn (int $id) => in_array($id, [$norte->id], true))
        ->values()->all();

    $dosCampus->campus()->sync([...$suyos, ...$ajenos]);

    verificar('Editar desde Norte no desvincula Sur',
        $dosCampus->fresh()->campus->pluck('id')->sort()->values()->all()
            === collect([$norte->id, $sur->id])->sort()->values()->all(),
        $dosCampus->fresh()->campus->pluck('nombre')->implode(', '));

    echo PHP_EOL.'5. Borrar un campus no deja basura'.PHP_EOL;

    $idEste = $este->id;
    $este->forceDelete();

    verificar('El pivote se limpia en cascada',
        DB::table('ciclo_campus')->where('campus_id', $idEste)->doesntExist());
    verificar('El ciclo que se queda sin campus pasa a global',
        $soloEste->fresh()->esGlobal());
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
