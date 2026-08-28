<?php

/**
 * Las pantallas de finanzas que un permiso COMPARTIDO dejaba abiertas.
 *
 * Se corre con `php scripts/prueba-puertas-de-finanzas.php` desde la raíz.
 *
 * ── El hueco que esto vigila ──────────────────────────────────────────────
 * `ver-adeudos` pertenece a TRES facetas —administrativo, alumno y padre de
 * familia—, porque el alumno consulta su estado de cuenta con él. Todo el
 * módulo de finanzas cuelga de ese permiso y cada pantalla administrativa añade
 * el suyo encima… menos dos, que se quedaron sólo con el del grupo:
 *
 *   - `/finanzas/comprobantes`, la cola de revisión: nombre, matrícula,
 *     carrera, monto, banco y referencia SPEI de TODA la escuela.
 *   - `/finanzas/cuentas-bancarias`: las CLABE y los números de cuenta.
 *
 * Un permiso compartido entre oficios no puede ser lo único que cierre una
 * puerta administrativa: no distingue de quién es. La regla estaba ESCRITA en
 * un comentario justo encima de esas rutas —«configurar las cuentas va con el
 * permiso de configurar el cobro; aprobar va con el de registrar pagos»— y a
 * los dos `index` no se les había aplicado.
 *
 * ── Por qué el escenario se SIEMBRA ───────────────────────────────────────
 * El demo no tiene ni un comprobante ni una cuenta bancaria, así que las dos
 * pantallas salen VACÍAS y la fuga se lee como si no existiera. Medido: sin
 * sembrar, el alumno entraba igual y veía «0 filas». Cada caso crea lo suyo
 * dentro de la transacción.
 */

use App\Http\Controllers\ComprobantePagoController;
use App\Http\Controllers\CuentaBancariaController;
use App\Models\Identidad\Usuario;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Los `can:` que el middleware exige para llegar a esa dirección. */
function permisosDe(string $uri): array
{
    foreach (app('router')->getRoutes() as $ruta) {
        if ($ruta->uri() !== $uri || $ruta->methods()[0] !== 'GET') {
            continue;
        }

        $can = [];

        foreach ($ruta->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'can:')) {
                $can[] = substr($m, 4);
            }
        }

        return $can;
    }

    return [];
}

/** ¿Pasaría este usuario TODOS los `can:` de esa dirección? */
function alcanza(Usuario $usuario, string $uri): bool
{
    $permisos = permisosDe($uri);

    if ($permisos === []) {
        return true;
    }

    foreach ($permisos as $permiso) {
        if (! Gate::forUser($usuario)->allows($permiso)) {
            return false;
        }
    }

    return true;
}

tenancy()->initialize(App\Models\Tenant::find('demo'));
DB::beginTransaction();

try {
    $alumno = Usuario::query()->whereHas('persona.matriculas')->firstOrFail();

    echo PHP_EOL.'1. El punto de partida: el permiso del grupo lo tiene el alumno'.PHP_EOL;

    verificar('El alumno tiene `ver-adeudos` —lo necesita para su estado de cuenta',
        $alumno->can('ver-adeudos'),
        'es de tres facetas: administrativo, alumno y padre de familia');

    verificar('Y NO tiene los permisos de personal de caja ni de configuración',
        ! $alumno->can('registrar-pagos') && ! $alumno->can('gestionar-planes-cobro'));

    echo PHP_EOL.'2. Las dos pantallas piden algo MÁS que el permiso compartido'.PHP_EOL;

    /*
     * Se mira lo que exige el MIDDLEWARE y no lo que devuelve el controlador:
     * el defecto era exactamente que el controlador entregaba los datos porque
     * nadie lo había parado antes.
     */
    verificar('La cola de comprobantes exige el permiso de quien cobra',
        in_array('registrar-pagos', permisosDe('finanzas/comprobantes'), true),
        implode(', ', permisosDe('finanzas/comprobantes')));

    verificar('El catálogo de cuentas exige su propia puerta',
        in_array('ver-cuentas-bancarias', permisosDe('finanzas/cuentas-bancarias'), true),
        implode(', ', permisosDe('finanzas/cuentas-bancarias')));

    verificar('El alumno NO alcanza la cola de comprobantes',
        ! alcanza($alumno, 'finanzas/comprobantes'),
        'ahí están los pagos de otras familias');

    verificar('Ni el catálogo de cuentas bancarias',
        ! alcanza($alumno, 'finanzas/cuentas-bancarias'),
        'ahí están las CLABE de la escuela');

    echo PHP_EOL.'3. Y quien sí trabaja ahí sigue entrando'.PHP_EOL;

    /*
     * Las dos mitades de la puerta derivada, por separado: si sólo se probara
     * con alguien que tiene los dos permisos, fundir la condición en un `&&`
     * pasaría desapercibido.
     */
    $caja = usuarioCon(['ver-adeudos', 'registrar-pagos']);
    $cobro = usuarioCon(['ver-adeudos', 'gestionar-planes-cobro']);

    verificar('Quien registra pagos entra a la cola de comprobantes',
        alcanza($caja, 'finanzas/comprobantes'));

    verificar('…y también a las cuentas, que necesita para casar una transferencia',
        alcanza($caja, 'finanzas/cuentas-bancarias'),
        'es la mitad de la puerta derivada que no administra el catálogo');

    verificar('Quien configura el cobro entra a las cuentas',
        alcanza($cobro, 'finanzas/cuentas-bancarias'));

    verificar('…y NO a la cola de comprobantes, porque aprobar es cobrar',
        ! alcanza($cobro, 'finanzas/comprobantes'),
        'la regla ya estaba escrita para aprobar y rechazar');

    echo PHP_EOL.'4. Al alumno no se le rompe el pago: sus cuentas salen de SU cartera'.PHP_EOL;

    $matricula = $alumno->persona->matriculas()->firstOrFail();

    $cuenta = DB::table('cuentas_bancarias')->insertGetId([
        'nombre' => 'Cuenta de prueba', 'banco' => 'BBVA', 'titular' => 'Instituto Demo',
        'clabe' => '012180001234567895', 'numero_cuenta' => '0123456789',
        'activa' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $suyas = App\Models\Finanzas\CuentaBancaria::paraCarrera($matricula->oferta?->carrera_id)
        ->filter(fn ($c) => $c->puedeRecibir());

    verificar('El alumno sigue viendo a dónde pagar, por el camino de su cartera',
        $suyas->contains(fn ($c) => $c->id === $cuenta),
        $suyas->count().' cuenta(s) por `paraCarrera` + `puedeRecibir`');

    echo PHP_EOL.'5. Con datos sembrados, la fuga era real (no «0 filas» del demo)'.PHP_EOL;

    $ajena = DB::table('matricula_oferta')
        ->where('persona_id', '!=', $alumno->persona_id)
        ->whereNull('deleted_at')->firstOrFail();

    DB::table('comprobantes_pago')->insert([
        'matricula_oferta_id' => $ajena->id, 'cuenta_bancaria_id' => $cuenta,
        'monto' => 4850.00, 'fecha_transferencia' => now()->subDay()->toDateString(),
        'referencia' => 'SPEI-0099887766', 'archivo' => 'comprobantes/inexistente.jpg',
        'adeudo_ids' => json_encode([]), 'estado' => 'pendiente',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /*
     * El controlador SIGUE sin filtrar, y está bien: lo que cierra la puerta es
     * el middleware. Lo que esta comprobación fija es que la fuga existía de
     * verdad —con datos— y no que el demo estuviera vacío.
     */
    auth()->login($alumno);
    $peticion = Request::create('/finanzas/comprobantes', 'GET');
    $peticion->setUserResolver(fn () => $alumno);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', Inertia\Inertia::getVersion());

    $props = app(ComprobantePagoController::class)->index($peticion)
        ->toResponse($peticion)->getData(true)['props'] ?? [];

    $filas = $props['comprobantes'] ?? [];

    verificar('El controlador entrega el comprobante ajeno a quien llegue',
        count($filas) > 0 && ($filas[0]['matricula'] ?? null) === $ajena->matricula,
        'por eso la defensa tiene que estar ANTES, en el middleware');

    verificar('Y trae datos personales de otra familia',
        isset($filas[0]['alumno'], $filas[0]['monto'], $filas[0]['referencia']),
        'nombre, monto y referencia bancaria');

    $cuentas = app(CuentaBancariaController::class)->index($peticion)
        ->toResponse($peticion)->getData(true)['props']['cuentas'] ?? [];

    verificar('Lo mismo el catálogo de cuentas, con su CLABE',
        collect($cuentas)->contains(fn ($c) => ($c['clabe'] ?? null) === '012180001234567895'));

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}

/** Una cuenta con EXACTAMENTE esos permisos, y ninguno más. */
function usuarioCon(array $permisos): Usuario
{
    static $n = 0;
    $n++;

    $persona = App\Models\Identidad\Persona::create([
        'nombre' => 'Prueba', 'primer_apellido' => 'Finanzas',
        'curp' => 'PFI'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).random_int(100000, 999999).'XY',
    ]);

    $rol = App\Models\Identidad\Rol::create([
        'name' => 'prueba-finanzas-'.$persona->id, 'nombre' => 'Prueba finanzas', 'guard_name' => 'web',
    ]);

    foreach ($permisos as $clave) {
        $rol->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($clave, 'web'));
    }

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba.finanzas.'.$persona->id,
        'password' => bcrypt('sin-importancia'),
    ]);

    DB::table('persona_rol')->insert([
        'persona_id' => $persona->id, 'rol_id' => $rol->id, 'activo' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $usuario->update(['rol_activo_id' => $rol->id]);

    return $usuario->refresh();
}
