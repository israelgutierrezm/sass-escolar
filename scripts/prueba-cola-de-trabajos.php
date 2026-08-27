<?php

/**
 * La COLA de trabajos: que exista quien la procese, y que se note si no.
 *
 * Se corre con `php scripts/prueba-cola-de-trabajos.php` desde la raíz.
 *
 * ── El hueco que esto vigila ──────────────────────────────────────────────
 * Tres sitios encolan trabajo —`TimbrarFactura` desde el controlador de facturas
 * y desde `EmisorFactura`, y `ArchivarGrabacion` desde el recolector— y hasta el
 * 2026-08-27 **no había nadie que lo procesara**: ni en el despachador, ni en
 * `deploy/scheduler/`, ni en `docs/scheduler.md`.
 *
 * Una cola sin trabajador NO FALLA. El trabajo se inserta, nadie lo toma, y no
 * hay excepción ni log ni alerta: la factura simplemente nunca se timbra y quien
 * la emitió cree que sí.
 *
 * ── Y por qué hay que SEMBRAR el escenario ────────────────────────────────
 * La cola del demo está vacía y esos caminos nunca se han ejercitado con datos
 * —por eso el hueco duró tanto—. Sin sembrar un trabajo viejo, «la cola no está
 * atorada» se cumple sola.
 *
 * ── Ojo con el rollback ───────────────────────────────────────────────────
 * Los trabajos caen en la base CENTRAL, no en la del tenant. Una transacción
 * sobre el tenant NO los deshace; hay que envolver la conexión central. Mordió
 * al escribir esto: una prueba dejó una fila suelta en `jobs`.
 */

use App\Models\Tenant;
use App\Services\Plataforma\EstadoDeLaCola;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

$central = config('tenancy.database.central_connection', 'mysql');

DB::connection($central)->beginTransaction();

try {
    $cola = app(EstadoDeLaCola::class);

    echo PHP_EOL.'1. Hay quien procese la cola'.PHP_EOL;

    /*
     * Se pregunta al REGISTRO del despachador, no al texto de
     * `routes/console.php`. La primera versión de esta prueba leía el archivo con
     * `str_contains` y pasaba con las banderas quitadas del comando: el comentario
     * que las explica también las nombra, así que la prueba estaba midiendo su
     * propia prosa. Lo cazaron dos mutaciones que sobrevivieron.
     */
    $trabajadores = array_values(array_filter(
        app(Illuminate\Console\Scheduling\Schedule::class)->events(),
        fn ($evento) => str_contains((string) $evento->command, 'queue:work'),
    ));

    verificar('El despachador levanta un trabajador de la cola',
        count($trabajadores) === 1,
        count($trabajadores).' declarado(s) en routes/console.php');

    $trabajador = $trabajadores[0] ?? null;
    $comando = $trabajador?->command ?? '';

    verificar('Y sale cuando la cola se vacía, en vez de quedarse corriendo',
        str_contains($comando, '--stop-when-empty'),
        'sin esto, un proceso por minuto se queda vivo para siempre');

    /*
     * Un trabajador eterno se queda con el CÓDIGO VIEJO tras un despliegue y
     * sigue procesando con él sin que nadie lo note.
     */
    verificar('Y tiene tope de vida, para no quedarse con el código viejo',
        (bool) preg_match('/--max-time=\d+/', $comando),
        trim(str_replace('"', '', substr($comando, (int) strpos($comando, 'queue:work')))));

    verificar('Se revisa cada minuto: una factura no espera una hora a timbrarse',
        $trabajador?->expression === '* * * * *',
        (string) $trabajador?->expression);

    /*
     * En SEGUNDO PLANO, o el despachador se queda hasta 55 segundos dentro del
     * trabajador y todo lo demás de ese minuto —el latido incluido— se retrasa
     * detrás de él.
     */
    verificar('Y en segundo plano, para no detener a las demás tareas del minuto',
        $trabajador?->runInBackground === true);

    /*
     * Con candado, para que un trabajo largo no acumule un trabajador nuevo cada
     * minuto. Y con caducidad CORTA: el valor por omisión de Laravel es un día
     * entero, así que un trabajador que muera —un reinicio, un corte— deja la cola
     * trabada hasta veinticuatro horas después. Se exige que caduque ANTES de que
     * la cola se dé por atorada, o el aviso llegaría por un candado nuestro y no
     * por un problema de verdad.
     *
     * Sin la comparación, `is_int()` a secas pasaba también con el candado por
     * omisión: la mutación sobrevivió.
     */
    verificar('Con candado que caduca pronto, para no apilar trabajadores ni quedarse trabado',
        $trabajador?->withoutOverlapping === true
            && is_int($trabajador?->expiresAt)
            && $trabajador->expiresAt <= EstadoDeLaCola::TOLERANCIA_MINUTOS,
        'caduca a los '.var_export($trabajador?->expiresAt, true).' min, tolerancia '.EstadoDeLaCola::TOLERANCIA_MINUTOS);

    /*
     * Y que systemd no se lo lleve por delante.
     *
     * `runInBackground()` lanza el trabajo con `&` y `schedule:run` vuelve
     * enseguida; con un servicio `oneshot` y el modo de terminación por omisión,
     * systemd limpia el cgroup en cuanto sale el proceso principal y mata a los
     * hijos que acaba de lanzar. Nada falla y nada se registra: las tareas
     * simplemente no ocurren.
     *
     * Se busca la DIRECTIVA al principio de renglón, no el texto: en un archivo
     * de unidad los comentarios empiezan por `#`, así que la prosa que lo explica
     * no puede satisfacer esta comprobación. Es la trampa que ya se cobró la
     * primera versión de esta prueba.
     */
    $unidad = file_get_contents(__DIR__.'/../deploy/scheduler/acadion-scheduler.service');

    verificar('El servicio de systemd no mata lo que el despachador lanza en segundo plano',
        (bool) preg_match('/^KillMode=process\s*$/m', $unidad),
        'sin KillMode=process, un servicio oneshot se lleva a sus hijos al salir');

    echo PHP_EOL.'2. La cola es CENTRAL: un trabajador sirve a todas'.PHP_EOL;

    verificar('La base central tiene la tabla `jobs`',
        Schema::connection($central)->hasTable('jobs'));

    tenancy()->initialize(Tenant::find('demo'));

    $antesTenant = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
    $antesCentral = DB::connection($central)->table('jobs')->count();

    App\Jobs\TimbrarFactura::dispatch(999999);

    $despuesTenant = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
    $despuesCentral = DB::connection($central)->table('jobs')->count();

    verificar('Un trabajo despachado DENTRO de una escuela cae en la central',
        $despuesCentral === $antesCentral + 1 && $despuesTenant === $antesTenant,
        "central {$antesCentral}→{$despuesCentral}, tenant {$antesTenant}→{$despuesTenant}");

    $encolado = DB::connection($central)->table('jobs')->orderByDesc('id')->first();

    /*
     * Y lleva la escuela dentro. Sin eso, un trabajador central lo ejecutaría
     * contra la base equivocada — o contra ninguna.
     */
    verificar('Y lleva la escuela en su payload, para poder ejecutarlo',
        str_contains($encolado->payload, 'demo'),
        'sin esto, el trabajador no sabría de qué escuela es');

    echo PHP_EOL.'3. Una cola atorada se NOTA'.PHP_EOL;

    $sana = $cola->estado();

    verificar('Recién encolado, la cola no está atorada',
        ! $sana['atorada'] && $sana['pendientes'] > 0,
        $sana['pendientes'].' pendientes, espera '.$sana['espera_minutos'].' min');

    /*
     * Se envejece el trabajo, que es lo que pasa cuando nadie lo toma. Es el
     * caso que el demo no tiene y sin el cual esta sección no prueba nada.
     */
    $hace2h = now()->subHours(2);

    DB::connection($central)->table('jobs')->where('id', $encolado->id)
        ->update(['available_at' => $hace2h->timestamp]);

    $atorada = $cola->estado();

    verificar('Con un trabajo esperando dos horas, SÍ está atorada',
        $atorada['atorada'],
        'espera '.$atorada['espera_minutos'].' min, tolerancia '.EstadoDeLaCola::TOLERANCIA_MINUTOS);

    /*
     * Y la marca va en la hora de la APLICACIÓN, no en UTC.
     *
     * La primera versión sólo pedía que no fuera nula y que la espera pasara de
     * cien minutos, y eso se cumple igual en UTC: la espera son dos instantes
     * absolutos y sale bien de cualquier modo. Lo que salía mal era la marca
     * impresa —«hace 180 minutos (18:04)» con el reloj en las 15:04, una hora en
     * el futuro—. Se vio corriendo el comando, no la prueba.
     */
    verificar('Y dice desde cuándo espera el más viejo, en la hora de la escuela',
        $atorada['mas_viejo'] === $hace2h->toDateTimeString() && $atorada['espera_minutos'] >= 100,
        $atorada['mas_viejo'].' (se sembró '.$hace2h->toDateTimeString().')');

    /*
     * Se mira `available_at` y no `created_at`: un trabajo con reintento espera
     * a propósito hasta su siguiente turno, y contarlo como atorado daría una
     * alarma cada vez que el PAC devuelva un error transitorio.
     */
    DB::connection($central)->table('jobs')->where('id', $encolado->id)->update([
        'created_at' => now()->subHours(2)->timestamp,
        'available_at' => now()->addMinutes(5)->timestamp,
    ]);

    verificar('Un trabajo ESPERANDO su reintento no cuenta como atorado',
        ! $cola->estado()['atorada'],
        'creado hace 2 h pero disponible en 5 min');

    echo PHP_EOL.'4. Los trabajos FALLIDOS se dicen aunque la cola esté sana'.PHP_EOL;

    if (Schema::connection($central)->hasTable('failed_jobs')) {
        DB::connection($central)->table('jobs')->where('id', $encolado->id)->delete();

        $limpia = $cola->estado();

        verificar('Sin pendientes, la cola está al día',
            ! $limpia['atorada'] && $limpia['pendientes'] === 0);

        DB::connection($central)->table('failed_jobs')->insert([
            'uuid' => (string) Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Prueba',
            'failed_at' => now(),
        ]);

        $conFallidos = $cola->estado();

        /*
         * Un fallido no atora la cola —nadie está esperando por él— pero sigue
         * ahí y nadie lo va a reintentar solo. Un timbrado fallido es una
         * factura que no existe ante el SAT.
         */
        verificar('Un fallido se cuenta sin marcar la cola como atorada',
            $conFallidos['fallidos'] > 0 && ! $conFallidos['atorada'],
            $conFallidos['fallidos'].' fallidos');
    } else {
        echo '  (esta instalación no tiene `failed_jobs`; se omite)'.PHP_EOL;
    }

    echo PHP_EOL.'5. La RESERVA dura más que el trabajo más largo'.PHP_EOL;

    /*
     * `retry_after` es cuánto dura la reserva de una fila. Pasado ese tiempo la
     * cola da el trabajo por perdido y otro trabajador se lo lleva, **aunque el
     * primero siga trabajando**. Con el valor por omisión de Laravel —90
     * segundos— y `ArchivarGrabacion` declarando media hora, el mismo video de
     * cientos de megas se bajaba en paralelo consigo mismo, quemaba sus tres
     * intentos en duplicados y terminaba en `failed_jobs` sin que nada hubiera
     * fallado.
     *
     * No se veía porque no había trabajador: encolar y no procesar esconde todos
     * los defectos de la cola a la vez.
     *
     * Se compara contra TODOS los trabajos de `app/Jobs/`, no contra el que hoy
     * es el más largo: así, subirle el `timeout` a un trabajo sin subir la
     * reserva tumba esta prueba en vez de duplicar descargas en silencio.
     */
    $conexion = config('queue.default');
    $reserva = (int) config("queue.connections.{$conexion}.retry_after");

    $masLargo = 0;
    $quien = '(ninguno declara timeout)';

    foreach (glob(__DIR__.'/../app/Jobs/*.php') as $archivo) {
        $clase = 'App\\Jobs\\'.basename($archivo, '.php');

        if (! class_exists($clase)) {
            continue;
        }

        $suyo = (int) ((new ReflectionClass($clase))->getDefaultProperties()['timeout'] ?? 0);

        if ($suyo > $masLargo) {
            $masLargo = $suyo;
            $quien = class_basename($clase);
        }
    }

    verificar('La reserva de un trabajo dura más de lo que el trabajo puede tardar',
        $reserva > $masLargo,
        "retry_after {$reserva}s contra {$masLargo}s del más largo ({$quien})");

    echo PHP_EOL.'6. Ningún comando que deba correr solo se queda sin quien lo invoque'.PHP_EOL;

    /*
     * La red para la CLASE del defecto, no para el caso.
     *
     * La cola no era el único: `clases:recoger-grabaciones` llevaba desde el
     * 2026-08-19 construido, documentado y SIN NADIE QUE LO LLAMARA. Es el mismo
     * error dos veces —se arma el mecanismo y no se engancha a nada que corra— y
     * ninguno de los dos falla: simplemente no pasa nada.
     *
     * Los que se corren A MANO se enumeran aquí con su razón. Así, un comando
     * nuevo que no esté en ninguna de las dos listas TUMBA esta prueba, y quien
     * lo escribió tiene que decidir a cuál pertenece — que es la decisión que las
     * dos veces se saltó.
     */
    $aMano = [
        'acadion:auditar-datos' => 'diagnóstico: se corre cuando se sospecha de una resiembra',
        'acadion:oferta-demo' => 'apoyo para armar la escuela de ejemplo',
        'acadion:rubrica-demo' => 'apoyo para armar la escuela de ejemplo',
        'acadion:usuario-demo' => 'apoyo para armar la escuela de ejemplo',
        'pagos:probar' => 'diagnóstico de una pasarela, contra credenciales reales',
        'pagos:tunel' => 'desarrollo: expone el webhook de pagos a internet',
        'scheduler:estado' => 'lo invoca la vigilancia del servidor, no el despachador',
    ];

    $programados = [];

    foreach (app(Illuminate\Console\Scheduling\Schedule::class)->events() as $evento) {
        if (preg_match('/artisan"? ([a-z0-9:_-]+)/i', (string) $evento->command, $partes)) {
            $programados[] = $partes[1];
        }
    }

    $sinClasificar = [];

    foreach (glob(__DIR__.'/../app/Console/Commands/*.php') as $archivo) {
        if (! preg_match('/\$signature\s*=\s*.([a-z0-9:_-]+)/i', file_get_contents($archivo), $partes)) {
            continue;
        }

        $firma = $partes[1];

        if (! in_array($firma, $programados, true) && ! isset($aMano[$firma])) {
            $sinClasificar[] = $firma.' ('.basename($archivo).')';
        }
    }

    verificar('Todo comando propio está programado o declarado como manual',
        $sinClasificar === [],
        $sinClasificar === []
            ? count($programados).' programados, '.count($aMano).' a mano'
            : 'sin clasificar: '.implode(', ', $sinClasificar));

    /*
     * Y el que motivó la red: Meet NO tiene webhook, así que si nadie pregunta,
     * la grabación no se archiva nunca.
     */
    verificar('Las grabaciones de Meet se recogen solas, porque Google no avisa',
        in_array('clases:recoger-grabaciones', $programados, true),
        'sin esto, la mitad de Meet del archivado no corre jamás');

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    // La CENTRAL, que es donde caen los trabajos.
    DB::connection($central)->rollBack();
}
