<?php

/**
 * Vigencia y revocación de autorizaciones (módulo Familia). Con rollback.
 *
 * Se corre con `php scripts/prueba-autorizaciones-vigencia.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **`vigencia_hasta` es OTRA cosa que `fecha_limite`.** Una concedida deja
 *     de contar al pasar su vigencia —caduca—, sin que nadie la toque.
 *  2. **Revocar distingue de negar.** Retirar lo concedido no es no haberlo
 *     concedido; el estado y el conteo los separan.
 *  3. **Revocar NO se ata al plazo de respuesta.** Un consentimiento vigente se
 *     retira aunque su `fecha_limite` ya pasó —el de uso de imagen—.
 *  4. **Sólo se revoca lo que está EN VIGOR.** Una caducada, una negada o una
 *     pendiente responden 404.
 *  5. **El conteo del administrador concuerda con el estado del modelo** (SQL
 *     contra PHP).
 */

use App\Http\Controllers\AutorizacionController;
use App\Models\Identidad\Autorizacion;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TipoAutorizacion;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
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
    }

    return null;
}

function peticionDe(Usuario $usuario, string $metodo = 'POST', array $datos = []): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);
    $p->headers->set('X-Inertia', 'true');

    return $p;
}

const PREF = 'ZZAUT-';

$db->beginTransaction();

try {
    $control = app(AutorizacionController::class);
    $tipo = TipoAutorizacion::query()->firstOrFail();

    $vinculo = TutorAlumno::query()->first();

    if ($vinculo === null) {
        echo 'Esta escuela no tiene vínculos familiares; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $familiar = Usuario::query()->where('persona_id', $vinculo->tutor_persona_id)->first()
        ?? Usuario::create([
            'persona_id' => $vinculo->tutor_persona_id,
            'usuario' => PREF.random_int(100000, 999999),
            'password' => Hash::make('x'),
        ]);

    $nueva = function (array $extra) use ($vinculo, $tipo) {
        return Autorizacion::create(array_merge([
            'vinculo_familiar_id' => $vinculo->id,
            'tipo_autorizacion_id' => $tipo->id,
            'titulo' => PREF.'Autorización',
        ], $extra));
    };

    // ── 1. La máquina de estados ────────────────────────────────────────────
    echo PHP_EOL.'1. Cada fila cae en su estado'.PHP_EOL;

    $pendiente = $nueva(['fecha_limite' => now()->addWeek()->toDateString()]);
    $sinResponder = $nueva(['fecha_limite' => now()->subDay()->toDateString()]);
    $negada = $nueva(['concedida' => false, 'fecha_respuesta' => now()]);
    $vigente = $nueva(['concedida' => true, 'fecha_respuesta' => now(), 'vigencia_hasta' => null]);
    $porVencer = $nueva(['concedida' => true, 'fecha_respuesta' => now(), 'vigencia_hasta' => now()->addMonth()->toDateString()]);
    $caducada = $nueva(['concedida' => true, 'fecha_respuesta' => now()->subMonths(2), 'vigencia_hasta' => now()->subDay()->toDateString()]);
    $revocada = $nueva(['concedida' => true, 'fecha_respuesta' => now()->subMonth(), 'revocada_en' => now()]);

    verificar('pendiente', $pendiente->estado() === 'pendiente');
    verificar('sin responder (plazo vencido, sin contestar)', $sinResponder->estado() === 'sin_responder');
    verificar('negada', $negada->estado() === 'negada');
    verificar('en vigor (permanente)', $vigente->estado() === 'en_vigor' && $vigente->estaEnVigor());
    verificar('en vigor (con vigencia futura)', $porVencer->estado() === 'en_vigor');
    verificar('caducada', $caducada->estado() === 'caducada' && ! $caducada->estaEnVigor() && $caducada->caducada());
    verificar('revocada', $revocada->estado() === 'revocada' && $revocada->revocada() && ! $revocada->estaEnVigor());

    // ── 2. Caducar NO es negar ni revocar ───────────────────────────────────
    echo PHP_EOL.'2. Una caducada dejó de contar sin que nadie la tocara'.PHP_EOL;

    verificar('La caducada NO cuenta como en vigor', ! $caducada->estaEnVigor());
    verificar('Pero sigue siendo distinta de una negada', $caducada->estado() !== $negada->estado());
    verificar('Y la vigencia es OTRA cosa que el plazo de respuesta',
        $caducada->fecha_limite === null && $caducada->vigencia_hasta !== null);

    // ── 3. puede_revocar sólo lo que está en vigor ──────────────────────────
    echo PHP_EOL.'3. Sólo lo que está en vigor se puede revocar'.PHP_EOL;

    verificar('En vigor: se puede revocar', $vigente->puedeRevocar() === true);
    verificar('Caducada: no', $caducada->puedeRevocar() === false);
    verificar('Negada: no', $negada->puedeRevocar() === false);
    verificar('Pendiente: no', $pendiente->puedeRevocar() === false);

    // ── 4. Revocar por el controlador ───────────────────────────────────────
    echo PHP_EOL.'4. La familia revoca lo suyo, y queda REVOCADA (no negada)'.PHP_EOL;

    $control->revocar(peticionDe($familiar, 'POST', ['comentario' => 'Ya no autorizo']), $vigente);
    $vigente->refresh();

    verificar('Quedó marcada la revocación', $vigente->revocada_en !== null);
    verificar('El estado es «revocada»', $vigente->estado() === 'revocada');
    verificar('Ya no está en vigor', ! $vigente->estaEnVigor());
    verificar('Sigue con concedida=true (revocar no es negar)', $vigente->concedida === true);
    verificar('Guardó el comentario', $vigente->comentario === 'Ya no autorizo');

    // ── 5. Revocar NO depende del plazo de respuesta ────────────────────────
    echo PHP_EOL.'5. Revocar un consentimiento vigente aunque el plazo ya pasó'.PHP_EOL;

    $vigentePlazoPasado = $nueva([
        'concedida' => true, 'fecha_respuesta' => now()->subMonth(),
        'fecha_limite' => now()->subWeek()->toDateString(), 'vigencia_hasta' => null,
    ]);
    verificar('Su plazo de respuesta ya pasó', ! $vigentePlazoPasado->admiteRespuesta());
    verificar('Pero se puede revocar', $vigentePlazoPasado->puedeRevocar());
    $control->revocar(peticionDe($familiar, 'POST'), $vigentePlazoPasado);
    verificar('Y el controlador la revocó', $vigentePlazoPasado->refresh()->estado() === 'revocada');

    // ── 6. Revocar lo que no está en vigor: 404 ─────────────────────────────
    echo PHP_EOL.'6. No se revoca una caducada, una negada ni una pendiente'.PHP_EOL;

    verificar('Revocar una caducada responde 404',
        fallo(fn () => $control->revocar(peticionDe($familiar), $caducada)) === 404);
    verificar('Revocar una negada responde 404',
        fallo(fn () => $control->revocar(peticionDe($familiar), $negada)) === 404);
    verificar('Revocar una pendiente responde 404',
        fallo(fn () => $control->revocar(peticionDe($familiar), $pendiente)) === 404);

    // Ajena: otro familiar sin vínculo con este alumno.
    $ajena = $nueva(['concedida' => true, 'fecha_respuesta' => now()]);
    $otroTutor = Persona::query()
        ->whereNotIn('id', TutorAlumno::query()->where('alumno_persona_id', $vinculo->alumno_persona_id)->select('tutor_persona_id'))
        ->first();
    $otroVinculo = TutorAlumno::create([
        'tutor_persona_id' => $otroTutor->id,
        'alumno_persona_id' => Persona::query()->whereKeyNot($otroTutor->id)->value('id'),
        'parentesco_id' => Parentesco::query()->value('id'),
    ]);
    $otroFamiliar = Usuario::query()->where('persona_id', $otroTutor->id)->first()
        ?? Usuario::create(['persona_id' => $otroTutor->id, 'usuario' => PREF.random_int(100000, 999999), 'password' => Hash::make('x')]);
    verificar('Revocar la de otro vínculo responde 404',
        fallo(fn () => $control->revocar(peticionDe($otroFamiliar), $ajena)) === 404);

    // ── 7. Validación de la vigencia al emitir ──────────────────────────────
    echo PHP_EOL.'7. Al emitir, la vigencia no cae antes del plazo ni en el pasado'.PHP_EOL;

    $emisor = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    $emitir = fn (array $datos) => (bool) fallo(function () use ($control, $emisor, $datos) {
        try {
            $control->emitir(peticionDe($emisor, 'POST', $datos));
        } catch (ValidationException) {
            throw new HttpException(422);
        }
    });

    $base = ['tipo_autorizacion_id' => $tipo->id, 'titulo' => PREF.'Salida', 'alumnos' => [$vinculo->alumno_persona_id]];
    verificar('Vigencia en el pasado se rechaza',
        $emitir(array_merge($base, ['vigencia_hasta' => now()->subDay()->toDateString()])));
    verificar('Vigencia antes del plazo de respuesta se rechaza',
        $emitir(array_merge($base, ['fecha_limite' => now()->addWeek()->toDateString(), 'vigencia_hasta' => now()->addDay()->toDateString()])));
    verificar('Una vigencia válida (después del plazo) se acepta',
        ! $emitir(array_merge($base, ['fecha_limite' => now()->addDay()->toDateString(), 'vigencia_hasta' => now()->addMonth()->toDateString()])));

    // ── 8. El conteo del administrador concuerda con el modelo ──────────────
    echo PHP_EOL.'8. El conteo (SQL) concuerda con el estado (PHP)'.PHP_EOL;

    // Todas las de nuestro título, contadas por estado en PHP.
    $mias = Autorizacion::query()->where('titulo', PREF.'Autorización')->get();
    $tally = ['en_vigor' => 0, 'caducada' => 0, 'revocada' => 0, 'negada' => 0, 'sin_responder' => 0, 'pendiente' => 0];
    foreach ($mias as $a) {
        $tally[$a->estado()]++;
    }

    $props = json_decode($control->index(peticionDe($emisor, 'GET'))->toResponse(peticionDe($emisor, 'GET'))->getContent(), true)['props'];
    // La(s) emisión(es) de nuestro título; puede haber varias por distinta vigencia.
    $filas = collect($props['emisiones'])->where('titulo', PREF.'Autorización');
    $sql = [
        'en_vigor' => (int) $filas->sum('en_vigor'),
        'caducada' => (int) $filas->sum('caducadas'),
        'revocada' => (int) $filas->sum('revocadas'),
        'negada' => (int) $filas->sum('negadas'),
        'pendiente' => (int) $filas->sum('pendientes'),
    ];

    verificar('en vigor concuerda', $sql['en_vigor'] === $tally['en_vigor'], "sql={$sql['en_vigor']} php={$tally['en_vigor']}");
    verificar('caducadas concuerda', $sql['caducada'] === $tally['caducada'], "sql={$sql['caducada']} php={$tally['caducada']}");
    verificar('revocadas concuerda', $sql['revocada'] === $tally['revocada'], "sql={$sql['revocada']} php={$tally['revocada']}");
    verificar('negadas concuerda', $sql['negada'] === $tally['negada']);
    // «pendientes» del SQL = concedida IS NULL, que en PHP son pendiente + sin_responder.
    verificar('pendientes (incluye sin responder) concuerda',
        $sql['pendiente'] === $tally['pendiente'] + $tally['sin_responder']);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
