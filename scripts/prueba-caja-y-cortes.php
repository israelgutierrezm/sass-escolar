<?php

/**
 * Caja y cortes: cuadrar el efectivo contra lo que el sistema dice que entró.
 *
 * `php scripts/prueba-caja-y-cortes.php` desde la raíz. Contra la BD real del
 * tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Que el arqueo compare lo correcto. Los errores de aquí no revientan: cuentan
 * de más y sale sobrante todos los días, o cuentan de menos y el faltante que
 * aparece no es de nadie. En concreto:
 *
 *  - Que sólo entre al conteo lo que la BANDERA del método declara dinero de
 *    cajón. Una tarjeta cobrada en la misma ventanilla pertenece al corte y no
 *    al conteo de billetes.
 *  - Que un pago pendiente de confirmar NO cuente: es una promesa.
 *  - Que el fondo inicial esté en el esperado. Sin él sale sobrante siempre por
 *    el mismo importe.
 *  - Que el pago se ate al turno de quien cobra SIN que nadie tenga que pasarlo.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\Caja;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SesionCaja;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Caja\OperacionDeCaja;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$ok = 0;
$fallos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;

    if ($condicion) {
        $ok++;
        echo "  OK    {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Lo que se rechaza tiene que rechazarse POR SU RAZÓN. */
function seNiega(callable $accion, string $fragmento): array
{
    try {
        $accion();

        return [false, 'no se negó'];
    } catch (RuntimeException $e) {
        return [str_contains($e->getMessage(), $fragmento), $e->getMessage()];
    } catch (Throwable $e) {
        // Se atrapa para REPORTARLA, nunca para darla por buena.
        return [false, 'reventó con '.$e::class.': '.$e->getMessage()];
    }
}

/**
 * Llama a un método del controlador como si fuera una petición de Inertia y
 * devuelve sus props ya resueltas.
 *
 * Hace falta de verdad: el primer 500 de esta rebanada —un scope que filtraba
 * por una columna que no existe— no lo veía la suite porque sólo lo usa la
 * PANTALLA. Comprobar el servicio no basta cuando la consulta que revienta la
 * arma el controlador.
 */
function props(object $controlador, string $metodo, Usuario $como, array $query = []): array
{
    $peticion = Request::create('/', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    // El orden importa: al reenlazar 'request', el AuthServiceProvider vuelve a
    // poner SU resolutor y se lleva por delante el nuestro.
    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    return json_decode($controlador->{$metodo}($peticion)->toResponse($peticion)->getContent(), true)['props'];
}

DB::beginTransaction();

try {
    $caja = app(OperacionDeCaja::class);
    $registrador = app(RegistradorPago::class);
    $ajustes = app(Ajustes::class);

    echo '1. El escenario'.PHP_EOL;

    $campus = Campus::query()->firstOrFail();

    $mostrador = Caja::create([
        'clave' => 'caja-prueba-1', 'nombre' => 'Ventanilla de prueba',
        'campus_id' => $campus->id, 'activa' => true,
    ]);
    $otra = Caja::create([
        'clave' => 'caja-prueba-2', 'nombre' => 'Segunda ventanilla',
        'campus_id' => $campus->id, 'activa' => true,
    ]);
    $apagada = Caja::create([
        'clave' => 'caja-prueba-3', 'nombre' => 'Ventanilla apagada',
        'campus_id' => $campus->id, 'activa' => false,
    ]);

    // Dos cajeros: hace falta el segundo para comprobar que la caja ocupada se
    // niega a OTRA persona, y que nadie autoriza su propio faltante.
    $hacerUsuario = function (string $nombre) {
        $persona = Persona::create(['nombre' => $nombre, 'primer_apellido' => 'Caja', 'sexo_id' => 2]);

        return Usuario::create([
            'persona_id' => $persona->id,
            'usuario' => 'caja.'.strtolower($nombre).'.'.$persona->id,
            'password' => bcrypt('x'),
            'activo' => true,
        ]);
    };

    $cajera = $hacerUsuario('Ana');
    $otroCajero = $hacerUsuario('Beto');

    $persona = Persona::create(['nombre' => 'Alumna', 'primer_apellido' => 'DeCaja', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $tarjeta = MetodoPago::where('clave', 'tarjeta_debito')->firstOrFail();

    verificar('El efectivo entra al cajón y la tarjeta no',
        $efectivo->afecta_caja === true && $tarjeta->afecta_caja === false);
    // Es lo que separa «cobrado» de «cobrado y disponible»: una tarjeta nace
    // pendiente de confirmar.
    verificar('Y la tarjeta nace pendiente de confirmar',
        $tarjeta->requiere_confirmacion === true && $efectivo->requiere_confirmacion === false);

    $cobrar = function (MetodoPago $metodo, float $monto) use ($matricula, $registrador, $colegiatura) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $colegiatura->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);

        return $registrador->registrar($matricula, $metodo, $monto, [$adeudo->id]);
    };

    echo PHP_EOL.'2. Abrir el turno'.PHP_EOL;

    [$bien, $mensaje] = seNiega(fn () => $caja->abrir($apagada, $cajera, 500.0), 'apagada');
    verificar('Una caja apagada no se abre', $bien, $mensaje);

    [$bien, $mensaje] = seNiega(fn () => $caja->abrir($mostrador, $cajera, -1.0), 'no puede ser negativo');
    verificar('Ni con fondo negativo', $bien, $mensaje);

    $sesion = $caja->abrir($mostrador, $cajera, 500.0);

    verificar('Queda abierta', $sesion->estaAbierta() && $sesion->estatus === SesionCaja::ABIERTA);
    verificar('Con su fondo', (float) $sesion->fondo_inicial === 500.0);
    verificar('Y se encuentra por su dueña', $caja->sesionDe($cajera)?->id === $sesion->id);
    verificar('Otra persona no tiene turno', $caja->sesionDe($otroCajero) === null);

    // Las dos reglas que sostienen los únicos de la base: sin ellas, el cobro
    // siguiente no sabría a qué corte pertenece.
    [$bien, $mensaje] = seNiega(fn () => $caja->abrir($otra, $cajera, 100.0), 'Ya tienes un turno');
    verificar('La misma persona no abre un segundo turno', $bien, $mensaje);

    [$bien, $mensaje] = seNiega(fn () => $caja->abrir($mostrador, $otroCajero, 100.0), 'turno abierto, de otra persona');
    verificar('Y una caja ocupada no se le abre a nadie más', $bien, $mensaje);

    echo PHP_EOL.'3. El cobro se ata al turno SOLO'.PHP_EOL;

    // Nadie le pasa la sesión al registrador: la resuelve de quien tiene la
    // sesión iniciada. Pedirla como parámetro habría bastado con que un camino
    // la olvidara para dejar efectivo fuera del arqueo.
    Auth::login($cajera);

    $enEfectivo = $cobrar($efectivo, 1200.0);
    $conTarjeta = $cobrar($tarjeta, 800.0);

    verificar('El cobro cae en el turno sin que nadie lo pase',
        $enEfectivo->sesion_caja_id === $sesion->id);
    verificar('También el de tarjeta: es del turno aunque no del cajón',
        $conTarjeta->sesion_caja_id === $sesion->id);

    Auth::logout();
    $sinSesion = $cobrar($efectivo, 300.0);

    // Una pasarela o un comando no tienen persona detrás: ese dinero no pasa
    // por ningún cajón y no debe caer en el corte de nadie.
    verificar('Lo que entra sin persona detrás no cae en ningún turno',
        $sinSesion->sesion_caja_id === null);

    Auth::login($cajera);

    echo PHP_EOL.'4. El arqueo cuenta lo que debe'.PHP_EOL;

    $totales = $caja->totales($sesion);

    verificar('El efectivo del turno son 1 200', $totales['efectivo'] === 1200.0, (string) $totales['efectivo']);
    // La tarjeta NACE pendiente: es una promesa hasta que alguien la confirme,
    // y contarla haría salir faltante el turno.
    verificar('La tarjeta sin confirmar no suma todavía', $totales['otros'] === 0.0, (string) $totales['otros']);

    // La CONSTANTE y no el literal: el estatus es «completado», y escribirlo a
    // mano hacía que la comprobación midiera un pago que seguía pendiente.
    $conTarjeta->update(['estatus' => Pago::ESTATUS_COMPLETADO]);
    $totales = $caja->totales($sesion);

    verificar('Confirmada, la tarjeta suma en «otros»', $totales['otros'] === 800.0, (string) $totales['otros']);
    // Y NO en el efectivo: ese dinero no pasó por el cajón.
    verificar('Pero NO en el efectivo', $totales['efectivo'] === 1200.0, (string) $totales['efectivo']);

    // Sin el fondo, el esperado saldría 1 200 y el arqueo daría sobrante de 500
    // todos los días.
    verificar('Lo esperado es el fondo más el efectivo', $caja->efectivoEsperado($sesion) === 1700.0,
        (string) $caja->efectivoEsperado($sesion));

    echo PHP_EOL.'5. El cierre que cuadra'.PHP_EOL;

    $cerrada = $caja->cerrar($sesion, $cajera, 1700.0);

    verificar('Queda cerrada', ! $cerrada->estaAbierta());
    verificar('Y cuadrada, sin pedir autorización', $cerrada->estatus === SesionCaja::CERRADA, $cerrada->estatus);
    verificar('Con diferencia cero', (float) $cerrada->diferencia === 0.0);
    // Congelado: recalcular el esperado al mirarlo haría que un corte de hace un
    // mes cambiara solo en cuanto alguien confirme una transferencia vieja.
    verificar('Y el esperado CONGELADO en la fila', (float) $cerrada->efectivo_esperado === 1700.0);

    [$bien, $mensaje] = seNiega(fn () => $caja->cerrar($cerrada, $cajera, 100.0), 'ya está cerrado');
    verificar('No se cierra dos veces', $bien, $mensaje);

    verificar('Y la caja vuelve a estar libre', $mostrador->sesionAbierta() === null);

    echo PHP_EOL.'6. Confirmar una transferencia vieja no mueve el corte'.PHP_EOL;

    // Ésta es la razón de congelar. El pago pendiente que quedó suelto se
    // confirma DESPUÉS del cierre.
    $tardio = Pago::create([
        'matricula_oferta_id' => $matricula->id,
        'metodo_pago_id' => $efectivo->id,
        'sesion_caja_id' => $cerrada->id,
        'monto' => 999.0,
        'estatus' => Pago::ESTATUS_COMPLETADO,
        'momento' => now(),
    ]);

    verificar('Lo que dice el corte no cambia',
        (float) $cerrada->fresh()->efectivo_esperado === 1700.0,
        (string) $cerrada->fresh()->efectivo_esperado);
    verificar('Aunque los totales de hoy ya sean otros',
        $caja->totales($cerrada->fresh())['efectivo'] === 2199.0,
        (string) $caja->totales($cerrada->fresh())['efectivo']);

    $tardio->forceDelete();

    echo PHP_EOL.'7. El cierre que NO cuadra'.PHP_EOL;

    $segunda = $caja->abrir($mostrador, $cajera, 0.0);
    $cobrar($efectivo, 100.0);

    $descuadrada = $caja->cerrar($segunda, $cajera, 80.0, 'Faltaron 20');

    verificar('Queda POR AUTORIZAR', $descuadrada->estatus === SesionCaja::POR_AUTORIZAR, $descuadrada->estatus);
    verificar('Con su diferencia', (float) $descuadrada->diferencia === -20.0, (string) $descuadrada->diferencia);
    // El signo solo no se lee: «−20» no dice si falta o sobra, y son dos
    // conversaciones distintas.
    verificar('Dicha en palabras', $descuadrada->sentidoDeLaDiferencia() === 'faltante');

    echo PHP_EOL.'8. La tolerancia'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::CAJA_TOLERANCIA => 50]);

    $tercera = $caja->abrir($otra, $otroCajero, 0.0);
    Auth::login($otroCajero);
    $cobrar($efectivo, 100.0);
    Auth::login($cajera);

    $conTolerancia = $caja->cerrar($tercera, $otroCajero, 80.0);

    // Con tolerancia 50, un faltante de 20 cierra solo: es lo que la escuela
    // declaró aceptable.
    verificar('Por debajo del tope, el corte cierra solo',
        $conTolerancia->estatus === SesionCaja::CERRADA, $conTolerancia->estatus);
    verificar('Pero la diferencia se guarda igual', (float) $conTolerancia->diferencia === -20.0);

    $ajustes->guardar([CatalogoAjustes::CAJA_TOLERANCIA => 0]);

    echo PHP_EOL.'9. Autorizar la diferencia'.PHP_EOL;

    [$bien, $mensaje] = seNiega(
        fn () => $caja->autorizar($conTolerancia, $cajera, 'x'),
        'no está esperando autorización',
    );
    verificar('Un corte que cuadró no se autoriza', $bien, $mensaje);

    $autorizada = $caja->autorizar($descuadrada, $otroCajero, 'Se pagó un mandado de la papelería');

    verificar('Queda cerrada', $autorizada->estatus === SesionCaja::CERRADA);
    verificar('Con el motivo guardado',
        $autorizada->motivo_diferencia === 'Se pagó un mandado de la papelería');
    verificar('Y con quién la autorizó', $autorizada->autorizada_por_usuario_id === $otroCajero->id);
    verificar('La diferencia NO se borra al autorizarla', (float) $autorizada->diferencia === -20.0);

    verificar('Y ya no queda ninguno por autorizar', $caja->porAutorizar() === 0,
        (string) $caja->porAutorizar());

    echo PHP_EOL.'10. El interruptor de exigir turno'.PHP_EOL;

    Auth::logout();

    // Apagado —el valor por omisión— la escuela cobra como hasta ahora: encender
    // esto sin cajas dadas de alta bloquearía todo el efectivo el primer día.
    verificar('Apagado, cobrar sin turno no estorba',
        $caja->motivoParaNoCobrar($efectivo, null) === null);

    $ajustes->guardar([CatalogoAjustes::CAJA_EXIGE_SESION => true]);

    $motivo = $caja->motivoParaNoCobrar($efectivo, null);
    verificar('Encendido, el efectivo sin turno se rehúsa', $motivo !== null);
    verificar('Y el mensaje dice a dónde ir', str_contains((string) $motivo, 'Abre tu caja'), (string) $motivo);

    // La tarjeta no entra al cajón: exigir turno para ella dejaría sin cobrar a
    // quien atiende por teléfono.
    verificar('Lo que no entra al cajón sigue cobrándose sin turno',
        $caja->motivoParaNoCobrar($tarjeta, null) === null);

    [$bien, $mensaje] = seNiega(fn () => $cobrar($efectivo, 50.0), 'turno de caja abierto');
    verificar('Y el registrador lo impide de verdad', $bien, $mensaje);

    // Con turno, el mismo cobro pasa.
    $cuarta = $caja->abrir($mostrador, $cajera, 0.0);
    Auth::login($cajera);
    $conTurno = $cobrar($efectivo, 50.0);

    verificar('Con turno abierto, el cobro pasa y cae en él',
        $conTurno->sesion_caja_id === $cuarta->id);

    $ajustes->guardar([CatalogoAjustes::CAJA_EXIGE_SESION => false]);

    echo PHP_EOL.'11. Las pantallas, invocadas de verdad'.PHP_EOL;

    // Se llama al CONTROLADOR y se leen sus props. Es lo único que caza un
    // scope roto o una relación mal escrita: el servicio puede estar impecable
    // y la pantalla reventar igual, y eso ya pasó en esta misma rebanada.
    $controlador = app(App\Http\Controllers\CajaController::class);

    $catalogo = props($controlador, 'index', $cajera);

    verificar('El catálogo responde y trae las cajas',
        isset($catalogo['cajas']) && count($catalogo['cajas']) >= 3,
        (string) count($catalogo['cajas'] ?? []));
    verificar('Y dice cuál tiene turno abierto',
        collect($catalogo['cajas'])->contains(fn (array $c) => $c['con_turno_abierto'] === true));

    $operacion = props($controlador, 'operacion', $cajera);

    verificar('La operación responde con el turno abierto',
        ($operacion['sesion']['id'] ?? null) === $cuarta->id, json_encode($operacion['sesion']['id'] ?? null));
    verificar('Y con los cortes de los turnos cerrados',
        count($operacion['cortes'] ?? []) >= 3, (string) count($operacion['cortes'] ?? []));

    // La caja APAGADA no se puede elegir para abrir: ofrecerla sería ofrecer un
    // botón que el servidor va a rechazar.
    $ofrecidas = collect($operacion['disponibles'] ?? [])->pluck('nombre');
    verificar('No se ofrece la caja apagada', ! $ofrecidas->contains('Ventanilla apagada'),
        $ofrecidas->implode(', '));
    // Ni la que ya tiene turno abierto.
    verificar('Ni la que ya está ocupada', ! $ofrecidas->contains('Ventanilla de prueba'),
        $ofrecidas->implode(', '));

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} catch (Throwable $e) {
    // Que la suite muera a media corrida ES una falla y se reporta como tal.
    $fallos[] = 'la suite murió antes de terminar';
    echo '  FALLA la suite murió antes de terminar  ['.$e::class.': '.$e->getMessage().']'.PHP_EOL;
    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} finally {
    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }

    Auth::logout();
    DB::rollBack();

    // Los ajustes se guardan en la tabla del tenant, así que el rollback se los
    // lleva; se olvida la caché en memoria para no dejarla mintiendo.
    app(Ajustes::class)->olvidar();
}

exit($fallos === [] ? 0 : 1);
