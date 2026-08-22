<?php

/**
 * El seeder del demo le DEVUELVE su campus a las cuentas de staff que lo
 * perdieron, no sólo se las salta. Con rollback.
 *
 * Se corre con `php scripts/prueba-seeder-staff.php` desde la raíz.
 *
 * ── Por qué existe esta prueba ─────────────────────────────────────────────
 * `staff.centro` y `staff.norte` existen para probar el alcance por campus, y
 * apuntaban a dos campus que se habían borrado. Al reparar las referencias
 * rotas con `acadion:auditar-datos --reparar` quedaron en NULL, y en esa columna
 * NULL no significa «menos» sino ALCANCE GLOBAL: justo lo contrario de lo que
 * esas cuentas vienen a probar.
 *
 * Resembrar no lo arreglaba, porque el seeder es idempotente por correo y se
 * limitaba a saltarse lo que ya existe: no duplicaba, pero tampoco convergía.
 *
 * ── Y la otra mitad, que es la que se puede romper por exceso ──────────────
 * Reparar no puede convertirse en pisar: si alguien movió la asignación a mano
 * para probar otra cosa, una resiembra no tiene por qué deshacérselo. Por eso se
 * comprueban las dos direcciones.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Campus;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Database\Seeders\PoblarInstitucionDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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

/** Sólo el trozo del seeder que nos interesa: no se resiembra media escuela. */
function correrSeeder(): void
{
    $seeder = new PoblarInstitucionDemoSeeder;
    $metodo = new ReflectionMethod($seeder, 'crearStaffPorCampus');
    $metodo->setAccessible(true);

    // El mismo orden que usa el seeder al sembrar de cero.
    $metodo->invoke($seeder, Campus::query()->orderBy('id')->get()->all());
}

$campusDe = fn (string $cuenta) => Usuario::where('usuario', $cuenta)->first()?->campusVisibles();

$db->beginTransaction();

try {
    $cuentas = Usuario::whereIn('usuario', ['staff.centro', 'staff.norte'])->get();

    if ($cuentas->count() < 2) {
        echo 'Esta escuela no tiene las dos cuentas de staff; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $personas = $cuentas->pluck('persona_id');
    $antes = ['staff.centro' => $campusDe('staff.centro'), 'staff.norte' => $campusDe('staff.norte')];

    echo PHP_EOL.'1. La cuenta se quedó sin campus (lo que deja --reparar)'.PHP_EOL;

    $db->table('persona_rol')->whereIn('persona_id', $personas)->update(['campus_id' => null]);

    verificar('sin campus, el alcance es GLOBAL', $campusDe('staff.centro') === null);

    correrSeeder();

    verificar('el seeder le devuelve el suyo',
        $campusDe('staff.centro') === $antes['staff.centro'], json_encode($campusDe('staff.centro')));
    verificar('y a la otra cuenta también',
        $campusDe('staff.norte') === $antes['staff.norte'], json_encode($campusDe('staff.norte')));

    echo PHP_EOL.'2. La cuenta apunta a un campus que ya no existe'.PHP_EOL;

    // Se fabrica la referencia rota igual que la deja una resiembra con las
    // comprobaciones apagadas: la foránea impide crearla de frente.
    $db->statement('SET FOREIGN_KEY_CHECKS=0');
    $db->table('persona_rol')->whereIn('persona_id', $personas)->update(['campus_id' => 987654321]);
    $db->statement('SET FOREIGN_KEY_CHECKS=1');

    correrSeeder();

    verificar('también se repara, no sólo el NULL',
        $campusDe('staff.centro') === $antes['staff.centro'], json_encode($campusDe('staff.centro')));

    echo PHP_EOL.'3. Alguien la movió a propósito'.PHP_EOL;

    $otro = Campus::query()->whereNotIn('id', (array) $antes['staff.centro'])->firstOrFail();
    $db->table('persona_rol')
        ->whereIn('persona_id', Usuario::where('usuario', 'staff.centro')->pluck('persona_id'))
        ->update(['campus_id' => $otro->id]);

    correrSeeder();

    verificar('el seeder respeta la decisión y NO la pisa',
        $campusDe('staff.centro') === [$otro->id],
        'esperado ['.$otro->id.'], obtenido '.json_encode($campusDe('staff.centro')));

    echo PHP_EOL.'4. Sigue siendo idempotente'.PHP_EOL;

    $cuantas = Usuario::whereIn('usuario', ['staff.centro', 'staff.norte'])->count();
    correrSeeder();
    correrSeeder();

    verificar('correrlo de nuevo no crea cuentas de más',
        Usuario::whereIn('usuario', ['staff.centro', 'staff.norte'])->count() === $cuantas,
        (string) $cuantas);
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
