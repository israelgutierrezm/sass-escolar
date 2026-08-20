<?php

/**
 * Prueba de integración de las CLASES EN LÍNEA: el reparto de licencias, los dos
 * proveedores y qué se le puede enseñar al alumno. Contra la base real, con
 * rollback.
 *
 * Se corre con `php scripts/prueba-clases-en-linea.php` desde la raíz.
 *
 * Fuerza el modo `fake`: no sale a internet ni crea reuniones de verdad. Lo que
 * se prueba es el reparto y las salvaguardas, que son de Acadion; que Zoom
 * responda es de Zoom.
 *
 * Crea sus propias cuentas y sus propias clases. No toca las del demo.
 */

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Models\Lms\CuentaVideo;
use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Models\Tenant;
use App\Services\Videoconferencia\AsignadorDeCuenta;
use App\Services\Videoconferencia\ProgramadorDeClases;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Antes de inicializar el tenant: lo que se prueba es el reparto, no Zoom.
config(['video.modo' => 'fake']);

tenancy()->initialize(Tenant::find('demo'));

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

/** Lo que se le dice al usuario cuando algo rebota, o null si no rebotó. */
function motivoDe(callable $accion): ?string
{
    try {
        $accion();

        return null;
    } catch (Throwable $e) {
        return App\Exceptions\AvisoParaElUsuario::motivoDe($e) ?? 'excepción: '.$e->getMessage();
    }
}

DB::beginTransaction();

try {
    $usuario = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    auth()->login($usuario);

    $programador = app(ProgramadorDeClases::class);
    $asignador = app(AsignadorDeCuenta::class);

    // Tres materias distintas: las clases simultáneas son de grupos distintos.
    $materias = AsignaturaGrupo::query()->limit(3)->get();
    $inicio = now()->addDay()->setTime(9, 0);
    $marca = 'P-'.uniqid();

    echo '1. El catálogo declara la diferencia entre proveedores'.PHP_EOL;

    // Es la decisión que gobierna el reparto: si esto se invierte, Zoom
    // sobrevende licencias o Meet pide comprar las que no existen.
    verificar('En Zoom una cuenta sostiene UNA reunión',
        ProveedoresVideoCatalogo::unaReunionPorCuenta('zoom'));
    verificar('En Meet no hay ese límite',
        ! ProveedoresVideoCatalogo::unaReunionPorCuenta('meet'));
    // Ante uno desconocido se supone el lado seguro: como mucho se dirá que no
    // hay cuentas libres, en vez de crear dos reuniones que se estorben.
    verificar('Un proveedor desconocido se trata como limitado',
        ProveedoresVideoCatalogo::unaReunionPorCuenta('inventado'));

    echo PHP_EOL.'2. Sin cuentas cargadas, se dice qué falta'.PHP_EOL;

    IntegracionVideo::para('zoom')->update([
        'activa' => true,
        'credenciales' => ['account_id' => 'a', 'client_id' => 'b', 'client_secret' => 'c'],
    ]);

    // Las del demo se apagan para que la prueba mida SOLO las suyas.
    CuentaVideo::query()->update(['activa' => false]);

    $motivo = motivoDe(fn () => $programador->programar($materias[0], 'zoom', "{$marca} sin cuentas", $inicio->copy(), 60));

    verificar('Rebota sin cuentas', $motivo !== null);
    // «No hay licencias» no dice qué hacer; el mensaje tiene que decir dónde.
    verificar('Y dice dónde se agregan', str_contains((string) $motivo, 'Plataforma'), (string) $motivo);

    echo PHP_EOL.'3. Dos licencias sostienen dos clases simultáneas'.PHP_EOL;

    $uno = CuentaVideo::create(['proveedor' => 'zoom', 'etiqueta' => "{$marca} L1", 'identificador' => "{$marca}-1@x.mx", 'activa' => true]);
    $dos = CuentaVideo::create(['proveedor' => 'zoom', 'etiqueta' => "{$marca} L2", 'identificador' => "{$marca}-2@x.mx", 'activa' => true]);

    $a = $programador->programar($materias[0], 'zoom', "{$marca} A", $inicio->copy(), 60, $usuario->id);
    $b = $programador->programar($materias[1], 'zoom', "{$marca} B", $inicio->copy(), 60, $usuario->id);

    verificar('La primera toma una licencia', $a->cuenta_id === $uno->id);
    // Si las dos cayeran en la misma, la segunda clase echaría a la primera de
    // la sala con el grupo dentro.
    verificar('La segunda toma la OTRA', $b->cuenta_id === $dos->id, "a={$a->cuenta_id} b={$b->cuenta_id}");

    echo PHP_EOL.'4. La tercera no se inventa una licencia'.PHP_EOL;

    $motivo = motivoDe(fn () => $programador->programar($materias[2], 'zoom', "{$marca} C", $inicio->copy(), 60));

    verificar('Rebota la tercera', $motivo !== null);
    verificar('Y dice cuántas están ocupadas', str_contains((string) $motivo, '2 cuentas'), (string) $motivo);
    verificar('Y a qué hora', str_contains((string) $motivo, '09:00'), (string) $motivo);
    verificar('No quedó una clase a medias',
        Videoconferencia::query()->where('titulo', "{$marca} C")->doesntExist());

    echo PHP_EOL.'5. El traslape se mide por VENTANA, no por hora de inicio'.PHP_EOL;

    // Empieza media hora después: no comparte hora de arranque y choca igual.
    $motivo = motivoDe(fn () => $programador->programar(
        $materias[2], 'zoom', "{$marca} solapada", $inicio->copy()->addMinutes(30), 60,
    ));

    verificar('Una que empieza a media clase también choca', $motivo !== null, (string) $motivo);

    // Y una que empieza justo al terminar, no.
    $pegada = $programador->programar($materias[2], 'zoom', "{$marca} pegada", $inicio->copy()->addHour(), 60, $usuario->id);

    verificar('Una que empieza justo al terminar sí entra', $pegada->cuenta_id === $uno->id);

    echo PHP_EOL.'6. Cancelar libera la licencia'.PHP_EOL;

    $programador->cancelar($a);
    $a->refresh();

    verificar('Queda cancelada', $a->estado === Videoconferencia::CANCELADA);
    // El enlace deja de servir: se retira para que ninguna pantalla lo pinte.
    verificar('Y sin enlaces', $a->url_join === null && $a->url_anfitrion === null);
    verificar('La licencia vuelve a estar libre a esa hora',
        $uno->fresh()->libreEntre($inicio->toDateTimeString(), $inicio->copy()->addHour()->toDateTimeString()));

    $reusada = $programador->programar($materias[0], 'zoom', "{$marca} reusa", $inicio->copy(), 60, $usuario->id);
    verificar('Y otra clase la puede tomar', $reusada->cuenta_id === $uno->id);

    echo PHP_EOL.'7. Meet: una sola cuenta aguanta varias a la vez'.PHP_EOL;

    IntegracionVideo::para('meet')->update([
        'activa' => true,
        'credenciales' => ['cuenta_servicio_json' => '{"client_email":"a@b","private_key":"x"}', 'dominio' => 'x.mx'],
    ]);
    $meet = CuentaVideo::create(['proveedor' => 'meet', 'etiqueta' => "{$marca} WS", 'identificador' => "{$marca}@x.mx", 'activa' => true]);

    $enMeet = [];

    foreach ([0, 1, 2] as $i) {
        $enMeet[] = $programador->programar($materias[$i], 'meet', "{$marca} meet {$i}", $inicio->copy()->addDay(), 60, $usuario->id);
    }

    // Es la asimetría: con Zoom la tercera habría rebotado.
    verificar('Las tres entran con la misma cuenta',
        count($enMeet) === 3 && collect($enMeet)->every(fn ($s) => $s->cuenta_id === $meet->id));

    echo PHP_EOL.'8. Lo que se le puede enseñar al alumno'.PHP_EOL;

    $futura = $programador->programar($materias[1], 'zoom', "{$marca} futura", now()->addDays(3), 60, $usuario->id);
    $paraAlumno = $futura->paraElAlumno(10);

    // El `start_url` de Zoom entra como dueño de la sala sin pedir contraseña.
    verificar('NUNCA viaja el enlace de anfitrión',
        ! array_key_exists('url_anfitrion', $paraAlumno), implode(', ', array_keys($paraAlumno)));
    verificar('La clase se anuncia aunque falten días', $paraAlumno['titulo'] === "{$marca} futura");
    // El enlace de la semana que viene no tiene por qué estar en el HTML de hoy.
    verificar('Pero sin enlace mientras no esté abierta',
        $paraAlumno['url'] === null && $paraAlumno['abierta'] === false);

    // Y una que empieza dentro de 5 minutos, con antelación de 10.
    $inminente = $programador->programar($materias[2], 'zoom', "{$marca} inminente", now()->addMinutes(5), 30, $usuario->id);
    $abierta = $inminente->paraElAlumno(10);

    verificar('A 5 minutos, con antelación de 10, ya está abierta', $abierta['abierta']);
    verificar('Y ahí sí trae enlace', filled($abierta['url']));
    // La antelación es de la escuela: con 0 sólo se entra a la hora exacta.
    verificar('Con antelación 0 todavía no', ! $inminente->paraElAlumno(0)['abierta']);

    // Cancelada no se puede abrir aunque sea su hora.
    $programador->cancelar($inminente);
    verificar('Una cancelada no abre', ! $inminente->fresh()->paraElAlumno(10)['abierta']);

    echo PHP_EOL.'9. El pasado no se programa'.PHP_EOL;

    $motivo = motivoDe(fn () => $programador->programar($materias[0], 'zoom', "{$marca} ayer", now()->subDay(), 60));

    // El proveedor aceptaría la fecha y crearía una reunión que nadie va a usar.
    verificar('Rebota una hora que ya pasó', $motivo !== null, (string) $motivo);

    echo PHP_EOL.'10. Un proveedor sin cuentas no se ofrece'.PHP_EOL;

    $disponibles = $asignador->disponibles();

    verificar('Zoom está disponible', in_array('zoom', $disponibles, true));

    // Apagar la única cuenta de Meet lo saca de la lista aunque siga encendido:
    // encendido sin anfitriones no puede dar una sola clase.
    $meet->update(['activa' => false]);

    verificar('Meet deja de ofrecerse al apagar su única cuenta',
        ! in_array('meet', app(AsignadorDeCuenta::class)->disponibles(), true));

    // Y apagar una cuenta NO cancela lo que ya sostenía.
    verificar('Pero sus clases programadas siguen en pie',
        Videoconferencia::query()->whereKey($enMeet[0]->id)->where('estado', Videoconferencia::PROGRAMADA)->exists());
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $f) {
    echo '  - '.$f.PHP_EOL;
}
