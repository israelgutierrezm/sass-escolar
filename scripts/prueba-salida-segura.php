<?php

/**
 * Salida segura, rebanada 1: quién puede recoger a un alumno. Con rollback.
 *
 * Se corre con `php scripts/prueba-salida-segura.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El BLOQUEO de custodia gana sobre ser tutor.** Un progenitor impedido
 *     no recoge aunque sea el padre.
 *  2. **Un tutor recoge por su vínculo**; un tercero, sólo si está autorizado y
 *     VIGENTE; nadie más.
 *  3. **La familia agrega/retira TERCEROS de SUS hijos**, y no puede tocar un
 *     bloqueo ni el hijo de otro.
 *  4. **El bloqueo de custodia exige motivo**, y sólo la escuela lo pone.
 *  5. **La lista efectiva** = tutores no bloqueados + terceros vigentes.
 */

use App\Http\Controllers\SalidaSeguraController;
use App\Models\Identidad\AutorizadoRecoger;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Familia\PuedeRecoger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    } catch (ValidationException) {
        return 422;
    }

    return null;
}

function peticionDe(Usuario $usuario, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

const PREF = 'ZZREC-';

$db->beginTransaction();

try {
    $reglas = app(PuedeRecoger::class);
    $control = app(SalidaSeguraController::class);

    $vinculo = TutorAlumno::query()->first();
    if ($vinculo === null) {
        echo 'Sin vínculos familiares; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $alumno = Persona::query()->findOrFail($vinculo->alumno_persona_id);
    $tutor1 = Persona::query()->findOrFail($vinculo->tutor_persona_id);

    // Un SEGUNDO tutor del mismo alumno (para bloquearlo).
    $tutor2 = Persona::query()
        ->whereNotIn('id', TutorAlumno::query()->where('alumno_persona_id', $alumno->id)->select('tutor_persona_id'))
        ->whereKeyNot($alumno->id)->firstOrFail();
    TutorAlumno::create(['tutor_persona_id' => $tutor2->id, 'alumno_persona_id' => $alumno->id, 'parentesco_id' => Parentesco::query()->value('id')]);

    // Un tercero con cuenta (para validar por persona_id).
    $tercero = Persona::query()->whereKeyNot($alumno->id)->whereKeyNot($tutor1->id)->whereKeyNot($tutor2->id)->firstOrFail();

    $familiar = Usuario::query()->where('persona_id', $tutor1->id)->first()
        ?? Usuario::create(['persona_id' => $tutor1->id, 'usuario' => PREF.random_int(100000, 999999), 'password' => Hash::make('x')]);
    $admin = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    // ── 1. La regla de PuedeRecoger ─────────────────────────────────────────
    echo PHP_EOL.'1. Tutor sí; desconocido no; tercero autorizado sí'.PHP_EOL;

    verificar('Un tutor recoge por su vínculo',
        $reglas->validar($alumno->id, $tutor1->id) === ['permitido' => true, 'razon' => 'tutor', 'motivo' => null]);
    verificar('Un desconocido no', $reglas->validar($alumno->id, $tercero->id)['razon'] === 'no_esta');

    $auth = AutorizadoRecoger::create([
        'alumno_persona_id' => $alumno->id, 'persona_id' => $tercero->id,
        'nombre' => PREF.'Abuela', 'permitido' => true,
    ]);
    verificar('Un tercero autorizado sí',
        $reglas->validar($alumno->id, $tercero->id) === ['permitido' => true, 'razon' => 'autorizado', 'motivo' => null]);

    // Vigencia: una autorización vencida NO cuenta.
    $auth->update(['vigencia_hasta' => now()->subDay()->toDateString()]);
    verificar('Un tercero con vigencia VENCIDA ya no recoge',
        $reglas->validar($alumno->id, $tercero->id)['razon'] === 'no_esta');
    $auth->update(['vigencia_hasta' => null]);

    // ── 2. El bloqueo gana sobre ser tutor ──────────────────────────────────
    echo PHP_EOL.'2. La custodia vence a todo, incluido ser tutor'.PHP_EOL;

    verificar('Antes del bloqueo, el tutor 2 recoge', $reglas->validar($alumno->id, $tutor2->id)['permitido'] === true);

    $bloqueo = AutorizadoRecoger::create([
        'alumno_persona_id' => $alumno->id, 'persona_id' => $tutor2->id,
        'nombre' => $tutor2->nombreCompleto(), 'permitido' => false, 'motivo' => 'Sentencia de custodia',
    ]);
    $r = $reglas->validar($alumno->id, $tutor2->id);
    verificar('Bloqueado, NO recoge aunque sea tutor', $r['permitido'] === false && $r['razon'] === 'bloqueo');
    verificar('Y el motivo viaja', $r['motivo'] === 'Sentencia de custodia');

    // ── 3. La familia agrega y retira terceros ──────────────────────────────
    echo PHP_EOL.'3. La familia gestiona a SUS terceros y nada más'.PHP_EOL;

    $control->agregarTercero(peticionDe($familiar, [
        'nombre' => PREF.'Chofer', 'identificacion' => 'INE 123', 'parentesco_id' => Parentesco::query()->value('id'),
    ]), $alumno);
    $chofer = AutorizadoRecoger::query()->where('nombre', PREF.'Chofer')->first();
    verificar('La familia autorizó a un tercero', $chofer !== null && $chofer->permitido === true);

    // Un ajeno (no tutor de este alumno) no puede agregar.
    $ajeno = Usuario::query()->where('persona_id', $tutor2->id)->first()
        ?? Usuario::create(['persona_id' => $tutor2->id, 'usuario' => PREF.random_int(100000, 999999), 'password' => Hash::make('x')]);
    // tutor2 SÍ es tutor; usemos alguien sin vínculo.
    $sinVinculo = Usuario::query()->where('persona_id', $tercero->id)->first()
        ?? Usuario::create(['persona_id' => $tercero->id, 'usuario' => PREF.random_int(100000, 999999), 'password' => Hash::make('x')]);
    verificar('Quien no es tutor del alumno no puede autorizar (404)',
        fallo(fn () => $control->agregarTercero(peticionDe($sinVinculo, ['nombre' => 'X']), $alumno)) === 404);

    verificar('La familia NO puede retirar un bloqueo de custodia (404)',
        fallo(fn () => $control->quitarTercero(peticionDe($familiar), $bloqueo)) === 404);

    $control->quitarTercero(peticionDe($familiar), $chofer);
    verificar('La familia retiró a su tercero', AutorizadoRecoger::query()->whereKey($chofer->id)->doesntExist());

    // ── 4. La escuela bloquea y desbloquea ──────────────────────────────────
    echo PHP_EOL.'4. La escuela pone y quita bloqueos, con motivo'.PHP_EOL;

    verificar('Bloquear SIN motivo se rechaza (422)',
        fallo(fn () => $control->bloquear(peticionDe($admin, ['persona_id' => $tutor1->id]), $alumno)) === 422);

    $control->bloquear(peticionDe($admin, ['persona_id' => $tutor1->id, 'motivo' => 'Medida cautelar']), $alumno);
    verificar('Bloqueado el tutor 1, ya no recoge', $reglas->validar($alumno->id, $tutor1->id)['razon'] === 'bloqueo');

    $bloqueoT1 = AutorizadoRecoger::query()->where('alumno_persona_id', $alumno->id)->where('persona_id', $tutor1->id)->bloquea()->first();
    verificar('Desbloquear un tercero (no un bloqueo) responde 404',
        fallo(fn () => $control->desbloquear($auth)) === 404);
    $control->desbloquear($bloqueoT1);
    verificar('Retirado el bloqueo, el tutor 1 recoge otra vez', $reglas->validar($alumno->id, $tutor1->id)['razon'] === 'tutor');

    // ── 5. La lista efectiva ────────────────────────────────────────────────
    echo PHP_EOL.'5. La lista efectiva: tutores no bloqueados + terceros vigentes'.PHP_EOL;

    $lista = $reglas->listaEfectiva($alumno->id);
    $nombres = array_column($lista, 'nombre');
    verificar('Incluye al tutor 1 (no bloqueado)', in_array($tutor1->nombreCompleto(), $nombres, true));
    verificar('NO incluye al tutor 2 (bloqueado por custodia)', ! in_array($tutor2->nombreCompleto(), $nombres, true));
    verificar('Incluye al tercero vigente (Abuela)', in_array(PREF.'Abuela', $nombres, true));
    verificar('Cada renglón dice su origen', collect($lista)->every(fn ($r) => in_array($r['origen'], ['tutor', 'autorizado'], true)));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
