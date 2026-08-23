<?php

/**
 * Módulo 10 · Nómina y RH, primera rebanada — el expediente laboral.
 * Con rollback.
 *
 * Se corre con `php scripts/prueba-rh-expediente-laboral.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. «Baja» tiene UNA fuente de verdad: `fecha_baja`. El catálogo de
 *     situaciones no la duplica, así que no hay forma de que un expediente
 *     diga «activo» con fecha de baja puesta.
 *  2. A quién se le paga lo dice la BANDERA `entra_a_nomina`, no la clave de
 *     la situación: licencia sin goce sigue contratado y no cobra; comisión
 *     cobra. Preguntar por `clave = 'activo'` se equivoca en los dos casos.
 *  3. Dar de baja CIERRA las adscripciones abiertas. Sin eso, quien renunció
 *     seguiría figurando como coordinador en el organigrama.
 *  4. Una sola adscripción principal: al marcar una, la anterior se degrada.
 *  5. El número de empleado no se repite, y quien captura lee el mensaje en su
 *     formulario en vez de un error de SQL.
 *  6. El NSS se guarda en la PERSONA, no en el expediente: quien es
 *     recontratado no vuelve a capturarlo.
 *  7. No se adscribe a quien ya está dado de baja, ni se cierra una
 *     adscripción antes de que empezara.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\Rh\EmpleadoController;
use App\Models\Academico\Campus;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Nomina\Adscripcion;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\MotivoBajaLaboral;
use App\Models\Nomina\Puesto;
use App\Models\Nomina\SituacionEmpleado;
use App\Models\Nomina\TipoContrato;
use App\Models\Tenant;
use App\Services\Nomina\RegistroLaboral;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

function peticionDe(Usuario $usuario, string $metodo = 'POST', array $datos = []): Request
{
    $peticion = Request::create('/prueba', $metodo, $datos);
    $peticion->setUserResolver(fn () => $usuario);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

try {
    $registro = app(RegistroLaboral::class);
    $control = app(EmpleadoController::class);
    $staff = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    echo PHP_EOL.'0. El catálogo, y la bandera que de verdad decide'.PHP_EOL;

    $situaciones = SituacionEmpleado::query()->activos()->get();

    verificar('hay situaciones sembradas', $situaciones->count() >= 3, (string) $situaciones->count());

    /*
     * NO existe una situación de «baja», y es la decisión que sostiene todo lo
     * demás: con ella, un expediente podría decir «activo» con fecha de baja
     * puesta y nadie sabría cuál manda.
     */
    verificar('ninguna situación se llama «baja»',
        $situaciones->doesntContain(fn ($s) => str_contains($s->clave, 'baja')),
        $situaciones->pluck('clave')->implode(','));

    $activo = $situaciones->firstWhere('clave', 'activo');
    $sinGoce = $situaciones->firstWhere('clave', 'licencia_sin_goce');
    $comision = $situaciones->firstWhere('clave', 'comision');

    verificar('«activo» entra a nómina', (bool) $activo?->entra_a_nomina);
    verificar('«licencia sin goce» NO entra a nómina', $sinGoce !== null && ! $sinGoce->entra_a_nomina);
    // Y ésta es la que delata a quien pregunte por `clave = 'activo'`.
    verificar('«comisión» SÍ entra a nómina, aunque no se llame «activo»',
        (bool) $comision?->entra_a_nomina);

    echo PHP_EOL.'1. Se da de alta un expediente sobre una persona que ya existe'.PHP_EOL;

    $contrato = TipoContrato::query()->activos()->firstOrFail();
    $campus = Campus::query()->orderBy('id')->take(2)->get();
    $puestos = Puesto::query()->activos()->take(2)->get();

    verificar('hay dos campus y dos puestos con los que probar',
        $campus->count() === 2 && $puestos->count() === 2);

    // Personas del demo que no tengan expediente: el padrón se arma sobre el
    // directorio que ya existe, no inventando gente.
    $conExpediente = ExpedienteLaboral::query()->pluck('persona_id');
    $gente = Persona::query()->whereNotIn('id', $conExpediente)->take(3)->get();

    verificar('hay tres personas del directorio con las que trabajar', $gente->count() === 3);

    $marca = 'PRUEBA-'.substr((string) microtime(true), -6);

    $control->guardar(peticionDe($staff, 'POST', [
        'persona_id' => $gente[0]->id,
        'numero_empleado' => $marca.'-A',
        'tipo_contrato_id' => $contrato->id,
        'situacion_id' => $activo->id,
        'fecha_ingreso' => now()->subYear()->toDateString(),
        'nss' => '12345678901',
    ]));

    $expediente = ExpedienteLaboral::query()->where('numero_empleado', $marca.'-A')->firstOrFail();

    verificar('quedó el expediente', $expediente->exists);
    verificar('y sigue contratado', $expediente->sigueContratado());
    verificar('entra a nómina', ExpedienteLaboral::query()->enNomina()->whereKey($expediente->id)->exists());

    // El NSS es de la PERSONA: quien es recontratado no vuelve a capturarlo.
    verificar('el NSS se guardó en la persona, no en el expediente',
        $gente[0]->refresh()->nss === '12345678901');
    verificar('y el expediente no tiene columna para repetirlo',
        ! Schema::connection('tenant')->hasColumn('expedientes_laborales', 'nss'));

    echo PHP_EOL.'2. El número de empleado no se repite'.PHP_EOL;

    /*
     * Y lo detiene la VALIDACIÓN, no el índice único: lo que se prueba es que
     * quien captura lea el mensaje en su formulario, no que la base se defienda
     * sola con un 500. Es la trampa que ya mordió en `prueba-bolsa-empresas`.
     */
    $rechazo = null;
    $porLaBase = false;

    try {
        $control->guardar(peticionDe($staff, 'POST', [
            'persona_id' => $gente[1]->id,
            'numero_empleado' => $marca.'-A',
            'tipo_contrato_id' => $contrato->id,
            'situacion_id' => $activo->id,
            'fecha_ingreso' => now()->toDateString(),
        ]));
    } catch (QueryException $e) {
        $porLaBase = true;
        $rechazo = $e->getMessage();
    } catch (ValidationException $e) {
        $rechazo = 'validación';
    }

    verificar('se rechaza el número repetido', $rechazo !== null);
    verificar('y lo detiene la validación, no el índice único', ! $porLaBase, (string) $rechazo);

    echo PHP_EOL.'3. La adscripción principal es una sola'.PHP_EOL;

    $primera = $registro->adscribir(
        $expediente, (int) $puestos[0]->id, (int) $campus[0]->id,
        now()->subYear()->toDateString(), null, true,
    );

    verificar('la primera queda como principal', $primera->es_principal);

    $segunda = $registro->adscribir(
        $expediente, (int) $puestos[1]->id, (int) $campus[1]->id,
        now()->subMonths(2)->toDateString(), null, true,
    );

    verificar('al marcar la segunda, la primera se degrada',
        ! $primera->refresh()->es_principal && $segunda->es_principal);
    verificar('y siguen siendo dos, no una',
        $expediente->adscripciones()->count() === 2);

    verificar('la adscripción actual es la principal',
        (int) $expediente->refresh()->load('adscripciones')->adscripcionActual()?->id === (int) $segunda->id);

    echo PHP_EOL.'4. Una adscripción se cierra, no se borra'.PHP_EOL;

    $registro->cerrarAdscripcion($primera, now()->subMonths(2)->toDateString());

    verificar('quedó con fecha de fin', $primera->refresh()->vigente_hasta !== null);
    verificar('y la fila sigue ahí', Adscripcion::query()->whereKey($primera->id)->exists());
    verificar('ya no está vigente', ! $primera->estaVigente());

    $alReves = false;

    try {
        $registro->cerrarAdscripcion($segunda, now()->subYears(3)->toDateString());
    } catch (RuntimeException) {
        $alReves = true;
    }

    verificar('no se cierra antes de haber empezado', $alReves);

    // Y tampoco se abre una que termine antes de empezar.
    $volteada = false;

    try {
        $registro->adscribir($expediente, (int) $puestos[0]->id, (int) $campus[0]->id,
            now()->toDateString(), now()->subMonth()->toDateString(), false);
    } catch (RuntimeException) {
        $volteada = true;
    }

    verificar('ni se abre una que termine antes de empezar', $volteada);

    echo PHP_EOL.'5. La situación decide si se le paga; la clave no sirve'.PHP_EOL;

    $expediente->update(['situacion_id' => $sinGoce->id]);

    verificar('con licencia sin goce sigue contratado', $expediente->refresh()->sigueContratado());
    verificar('pero YA NO entra a nómina',
        ! ExpedienteLaboral::query()->enNomina()->whereKey($expediente->id)->exists());

    $expediente->update(['situacion_id' => $comision->id]);

    verificar('comisionado sí entra a nómina, aunque su clave no sea «activo»',
        ExpedienteLaboral::query()->enNomina()->whereKey($expediente->id)->exists());

    $expediente->update(['situacion_id' => $activo->id]);

    echo PHP_EOL.'6. La baja cierra el vínculo y sus adscripciones'.PHP_EOL;

    $motivo = MotivoBajaLaboral::query()->activos()->firstOrFail();

    // Un expediente aparte para las reglas de fecha: gastar el principal aquí
    // dejaría las comprobaciones de más abajo sin sujeto.
    $expedienteFechas = ExpedienteLaboral::create([
        'persona_id' => $gente[2]->id,
        'numero_empleado' => $marca.'-F',
        'tipo_contrato_id' => $contrato->id,
        'situacion_id' => $activo->id,
        'fecha_ingreso' => now()->subMonth()->toDateString(),
    ]);
    $abiertasAntes = $expediente->adscripciones()->whereNull('vigente_hasta')->count();

    verificar('antes tenía una adscripción abierta', $abiertasAntes === 1, (string) $abiertasAntes);

    $registro->darDeBaja($expediente, now()->toDateString(), (int) $motivo->id);

    verificar('ya no sigue contratado', ! $expediente->refresh()->sigueContratado());
    verificar('con su motivo', $expediente->motivo_baja_id === $motivo->id);
    verificar('sale del padrón vigente',
        ! ExpedienteLaboral::query()->vigentes()->whereKey($expediente->id)->exists());
    verificar('y no entra a nómina aunque su situación diga «activo»',
        ! ExpedienteLaboral::query()->enNomina()->whereKey($expediente->id)->exists());
    verificar('sus adscripciones abiertas se cerraron',
        $expediente->adscripciones()->whereNull('vigente_hasta')->count() === 0);

    /*
     * Una baja anterior al ingreso es un error de captura, no un dato raro:
     * dejaría a alguien «dado de baja» antes de haber entrado y el periodo de
     * nómina no sabría si pagarle.
     */
    $antesDeEntrar = false;

    try {
        $registro->darDeBaja($expedienteFechas, now()->subYears(5)->toDateString(), (int) $motivo->id);
    } catch (RuntimeException) {
        $antesDeEntrar = true;
    }

    verificar('la baja no puede ser anterior al ingreso', $antesDeEntrar);
    verificar('y ese expediente sigue contratado', $expedienteFechas->refresh()->sigueContratado());

    $dosVeces = false;

    try {
        $registro->darDeBaja($expediente->refresh(), now()->toDateString(), (int) $motivo->id);
    } catch (RuntimeException) {
        $dosVeces = true;
    }

    verificar('no se da de baja dos veces', $dosVeces);

    $aUnBaja = false;

    try {
        $registro->adscribir($expediente->refresh(), (int) $puestos[0]->id, (int) $campus[0]->id,
            now()->toDateString(), null, false);
    } catch (RuntimeException) {
        $aUnBaja = true;
    }

    verificar('no se adscribe a quien ya está de baja', $aUnBaja);

    echo PHP_EOL.'7. Deshacer la baja'.PHP_EOL;

    $registro->reactivar($expediente->refresh());

    verificar('vuelve a estar contratado', $expediente->refresh()->sigueContratado());
    verificar('y sin motivo de baja colgando', $expediente->motivo_baja_id === null);

    /*
     * Las adscripciones NO se reabren, y es a propósito: no hay forma de saber
     * cuáles estaban abiertas antes de la baja ni si el puesto sigue libre.
     * Reabrirlas devolvería puestos que quizá ya ocupa alguien más.
     */
    verificar('sus adscripciones NO se reabren solas',
        $expediente->adscripciones()->whereNull('vigente_hasta')->count() === 0);

    $noEstaba = false;

    try {
        $registro->reactivar($expediente->refresh());
    } catch (RuntimeException) {
        $noEstaba = true;
    }

    verificar('no se reactiva a quien no estaba de baja', $noEstaba);

    echo PHP_EOL.'8. El padrón y su alcance'.PHP_EOL;

    $props = fn (string $metodo, array $query = [], array $extra = []) => json_decode(
        $control->{$metodo}(...array_merge([tap(
            Request::create('/prueba', 'GET', $query),
            fn ($p) => $p->setUserResolver(fn () => $staff),
        )], $extra))->toResponse(
            tap(Request::create('/prueba', 'GET', $query), function ($p) use ($staff) {
                $p->setUserResolver(fn () => $staff);
                $p->headers->set('X-Inertia', 'true');
                $p->headers->set('X-Inertia-Version', '');
            })
        )->getContent(),
        true,
    )['props'];

    $listado = $props('index');
    $ids = collect($listado['expedientes']['data'])->pluck('id');

    verificar('el expediente reactivado aparece en el padrón', $ids->contains($expediente->id));

    $registro->darDeBaja($expediente->refresh(), now()->toDateString(), (int) $motivo->id);

    $soloVigentes = collect($props('index')['expedientes']['data'])->pluck('id');

    verificar('dado de baja, YA NO aparece por omisión', ! $soloVigentes->contains($expediente->id));

    $conHistorico = collect($props('index', ['vinculo' => 'historico'])['expedientes']['data'])->pluck('id');

    verificar('pero sí al pedir el histórico', $conHistorico->contains($expediente->id));

    $renglon = collect($props('index', ['vinculo' => 'historico'])['expedientes']['data'])
        ->firstWhere('id', $expediente->id);

    verificar('el renglón dice que ya no está vigente', ($renglon['vigente'] ?? true) === false);
    verificar('y trae su fecha de baja', ($renglon['fecha_baja'] ?? null) !== null);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
