<?php

/**
 * Prueba de integración del ARCHIVADO DE GRABACIONES: la recolección, el trabajo
 * que copia y quién puede abrir el archivo. Contra la base real, con rollback.
 *
 * Se corre con `php scripts/prueba-grabaciones.php` desde la raíz.
 *
 * No sale a internet: las respuestas HTTP se fingen con `Http::fake` y el
 * destino es el disco propio. Lo que se prueba es lo de este lado —la
 * idempotencia, el estado, la limpieza y el alcance—, que es lo que se puede
 * romper sin que nadie se entere.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\GrabacionController;
use App\Jobs\ArchivarGrabacion;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\CuentaVideo;
use App\Models\Lms\DestinoGrabacion;
use App\Models\Lms\Grabacion;
use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Models\Tenant;
use App\Services\Grabaciones\ConsultorDeGrabacionesMeet;
use App\Services\Grabaciones\Destinos;
use App\Services\Grabaciones\RecolectorDeGrabaciones;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['video.modo' => 'fake']);

tenancy()->initialize(Tenant::find('demo'));

$ok = 0;
$fallos = [];
$aBorrar = [];

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

/** El estado con que rebota una petición, o 200 si no rebotó. */
function estadoDe(callable $accion): int
{
    try {
        $accion();

        return 200;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }
}

DB::beginTransaction();

try {
    $usuario = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    auth()->login($usuario);

    $recolector = app(RecolectorDeGrabaciones::class);
    $destinos = app(Destinos::class);
    $marca = 'P-'.uniqid();

    // Una materia con alumnos, para probar el alcance con gente de verdad.
    $inscripcion = Inscripcion::query()
        ->whereNotNull('asignatura_grupo_id')
        ->whereHas('matriculaOferta')
        ->firstOrFail();
    $materia = AsignaturaGrupo::findOrFail($inscripcion->asignatura_grupo_id);

    $clase = Videoconferencia::create([
        'asignatura_grupo_id' => $materia->id,
        'proveedor' => 'zoom',
        'titulo' => "{$marca} clase",
        'meeting_id' => "{$marca}-reunion",
        'inicio' => now()->subHours(2),
        'fin' => now()->subHour(),
        'estado' => Videoconferencia::TERMINADA,
    ]);

    $archivos = [
        ['id' => "{$marca}-v", 'tipo' => 'video', 'nombre' => "{$marca}.mp4", 'bytes' => 1024, 'url' => 'https://zoom.test/v'],
        ['id' => "{$marca}-c", 'tipo' => 'chat', 'nombre' => "{$marca}.txt", 'bytes' => 20, 'url' => 'https://zoom.test/c'],
    ];

    echo '1. Sin destino encendido, se ANOTA pero no se encola'.PHP_EOL;

    DestinoGrabacion::query()->update(['activo' => false]);
    Queue::fake();

    $recolector->registrar($clase, 'zoom', $archivos, 'tok');
    $anotadas = Grabacion::query()->where('videoconferencia_id', $clase->id)->get();

    /*
     * Anotar igual es deliberado: así la escuela ve que hubo grabación y puede
     * encender el archivado. Si no se anotara, el aviso se perdería y con él la
     * única señal de que esa clase se grabó.
     */
    verificar('Quedan anotadas las dos', $anotadas->count() === 2, (string) $anotadas->count());
    verificar('Marcadas como fallidas', $anotadas->every(fn ($g) => $g->estado === Grabacion::FALLIDA));
    verificar('Y diciendo por qué', str_contains((string) $anotadas->first()->error, 'destino'));

    echo PHP_EOL.'2. Con destino encendido, se encolan'.PHP_EOL;

    Grabacion::query()->where('videoconferencia_id', $clase->id)->forceDelete();
    DestinoGrabacion::para('disco')->update(['activo' => true]);
    Queue::fake();

    $encoladas = $recolector->registrar($clase, 'zoom', $archivos, 'tok');

    verificar('Se encolan las dos', $encoladas === 2, (string) $encoladas);
    verificar('Y nacen pendientes',
        Grabacion::query()->where('videoconferencia_id', $clase->id)->where('estado', Grabacion::PENDIENTE)->count() === 2);

    echo PHP_EOL.'3. El mismo aviso otra vez no duplica ni vuelve a bajar'.PHP_EOL;

    Grabacion::query()->where('videoconferencia_id', $clase->id)->update(['estado' => Grabacion::ARCHIVADA]);
    $repetidas = $recolector->registrar($clase, 'zoom', $archivos, 'tok');

    // Zoom reenvía su aviso si no se le contesta rápido. Sin la llave única, la
    // misma clase se archivaría tres veces y se pagaría tres veces el disco.
    verificar('No se encola nada de nuevo', $repetidas === 0, (string) $repetidas);
    verificar('Y siguen siendo dos filas',
        Grabacion::query()->where('videoconferencia_id', $clase->id)->count() === 2);

    /*
     * Foto de los temporales DE ESTE TRABAJO antes de archivar nada.
     *
     * Se compara contra la de después para ver si dejó basura. Los dos patrones
     * hacen falta porque `tempnam()` no se comporta igual en los dos sistemas:
     * en Windows recorta el prefijo a TRES letras —«grabacion-» se vuelve
     * «gra910D.tmp»— y en Linux lo conserva entero y sin extensión. Un glob por
     * «grabacion-*» a secas no encuentra nada en Windows y la comprobación
     * pasaría siempre; se descubrió mutando el borrado a propósito.
     *
     * ── Y NO se mira todo `*.tmp` ────────────────────────────────────────
     * La versión anterior lo hacía, y con eso la prueba pasaba SOLA y fallaba en
     * el barrido: las otras suites escriben sus propios temporales ahí
     * —`lote*.zip`, `xls*.xlsx`, `encuesta*`— y cualquiera que apareciera entre
     * las dos fotos se contaba como basura de este trabajo. Una prueba que sólo
     * pasa cuando la corres sola no prueba nada el día que alguien la mete en el
     * barrido; es la segunda vez que muerde en este proyecto.
     */
    $mios = fn () => array_merge(
        glob(sys_get_temp_dir().'/gra*.tmp') ?: [],
        glob(sys_get_temp_dir().'/grabacion-*') ?: [],
    );

    $temporalesAntes = $mios();

    echo PHP_EOL.'4. El trabajo descarga, sube y anota dónde quedó'.PHP_EOL;

    Grabacion::query()->where('videoconferencia_id', $clase->id)->update(['estado' => Grabacion::PENDIENTE]);
    $video = Grabacion::query()->where('id_externo', "{$marca}-v")->firstOrFail();

    $contenido = 'VIDEO-DE-PRUEBA-'.str_repeat('x', 500);

    /*
     * Acotado a `zoom.test` y no `*`. `Http::fake` ACUMULA stubs y gana el
     * primero que coincide, así que un comodín aquí ensombrecería los de los
     * pasos siguientes —y los de Google devolverían un cuerpo vacío, que se ve
     * como «credenciales rechazadas» y manda a diagnosticar el sitio
     * equivocado—.
     */
    Http::fake(['zoom.test/*' => Http::response($contenido, 200)]);

    (new ArchivarGrabacion('demo', $video->id, 'https://zoom.test/v', 'tok'))->handle($destinos);
    $video->refresh();
    $aBorrar[] = $video->ruta_destino;

    verificar('Queda archivada', $video->estado === Grabacion::ARCHIVADA, $video->estado);
    verificar('Con el destino anotado', $video->destino === 'disco');
    verificar('Y el archivo existe de verdad',
        filled($video->ruta_destino) && Storage::disk('local')->exists($video->ruta_destino));
    // Un archivo a medias se subiría sin error y al abrirlo no tendría nada.
    verificar('Con el tamaño que se descargó',
        $video->bytes === strlen($contenido), "{$video->bytes} vs ".strlen($contenido));
    verificar('Y con fecha de archivado', $video->archivada_en !== null);

    echo PHP_EOL.'5. Una descarga vacía NO se da por buena'.PHP_EOL;

    $chat = Grabacion::query()->where('id_externo', "{$marca}-c")->firstOrFail();
    Http::fake(['zoom.test/c' => Http::response('', 200)]);

    $reventó = false;

    try {
        (new ArchivarGrabacion('demo', $chat->id, 'https://zoom.test/c', 'tok'))->handle($destinos);
    } catch (Throwable $e) {
        $reventó = true;
    }

    $chat->refresh();

    verificar('Rebota', $reventó);
    verificar('Y queda marcada fallida', $chat->estado === Grabacion::FALLIDA);
    verificar('Con el motivo escrito', str_contains((string) $chat->error, 'vacía'), (string) $chat->error);
    verificar('Y contando el intento', $chat->intentos === 1, (string) $chat->intentos);

    echo PHP_EOL.'6. No se deja basura en el disco del servidor'.PHP_EOL;

    /*
     * El temporal se borra en `finally`, también cuando la subida falla. Sin
     * eso, cada reintento de cada clase deja medio giga en la partición del
     * servidor y acaba tirando todo lo demás que escribe ahí.
     *
     * Se miran los archivos NUEVOS respecto de la foto de antes: es lo único
     * observable desde fuera del trabajo, y lo que de verdad falla si se quita
     * el borrado.
     */
    $nuevos = array_diff($mios(), $temporalesAntes);

    verificar('No queda ningún temporal nuevo', $nuevos === [],
        implode(', ', array_map('basename', $nuevos)));

    echo PHP_EOL.'7. La grabación nace INVISIBLE para el alumno'.PHP_EOL;

    // Trae caras y voces de menores: publicarla es una decisión, no un efecto
    // secundario de haber encendido el archivado.
    verificar('Archivada pero apagada', ! $video->visible_alumnos);
    verificar('Y por tanto el alumno no la ve', ! $video->laVeElAlumno());

    $video->update(['visible_alumnos' => true]);
    verificar('Encendida, sí la ve', $video->fresh()->laVeElAlumno());

    // Y una que no llegó a archivarse no se ve ni encendida.
    $chat->update(['visible_alumnos' => true]);
    verificar('Una fallida no se ve aunque esté encendida', ! $chat->fresh()->laVeElAlumno());

    echo PHP_EOL.'8. Si se publican solas lo decide la escuela'.PHP_EOL;

    $ajustes = app(Ajustes::class);

    /*
     * La foto de ANTES de tocar el interruptor.
     *
     * No se compara contra `false` escrito a mano: el paso anterior dejó esta
     * grabación encendida a propósito, así que un literal estaría midiendo el
     * guion y no la regla.
     */
    $visibleAntes = (bool) $video->fresh()->visible_alumnos;

    // Con el interruptor ENCENDIDO, lo que llegue nace visible.
    $ajustes->guardar([CatalogoAjustes::VIDEO_PUBLICAR_GRABACIONES => true]);

    $otraClase = Videoconferencia::create([
        'asignatura_grupo_id' => $materia->id,
        'proveedor' => 'zoom',
        'titulo' => "{$marca} segunda",
        'meeting_id' => "{$marca}-r2",
        'inicio' => now()->subHours(4),
        'fin' => now()->subHours(3),
        'estado' => Videoconferencia::TERMINADA,
    ]);

    Queue::fake();
    $recolector->registrar($otraClase, 'zoom', [
        ['id' => "{$marca}-auto", 'tipo' => 'video', 'nombre' => "{$marca}-auto.mp4", 'bytes' => 10, 'url' => 'https://zoom.test/a'],
    ], 'tok');

    $automatica = Grabacion::query()->where('id_externo', "{$marca}-auto")->firstOrFail();

    verificar('Encendido, la nueva nace visible', $automatica->visible_alumnos);

    /*
     * Y lo que ya existía NO se toca al cambiar la regla.
     *
     * Es la parte que de verdad importa: publicar de un plumazo un semestre de
     * clases con menores dentro no puede ser el efecto de mover un interruptor.
     * `$video` se anotó antes, con el ajuste apagado.
     */
    verificar('Y la anterior no se movió', $video->fresh()->visible_alumnos === $visibleAntes,
        $visibleAntes ? 'estaba visible' : 'estaba oculta');

    // Apagado otra vez, lo nuevo vuelve a nacer oculto.
    $ajustes->guardar([CatalogoAjustes::VIDEO_PUBLICAR_GRABACIONES => false]);

    $terceraClase = Videoconferencia::create([
        'asignatura_grupo_id' => $materia->id,
        'proveedor' => 'zoom',
        'titulo' => "{$marca} tercera",
        'meeting_id' => "{$marca}-r3",
        'inicio' => now()->subHours(6),
        'fin' => now()->subHours(5),
        'estado' => Videoconferencia::TERMINADA,
    ]);

    Queue::fake();
    $recolector->registrar($terceraClase, 'zoom', [
        ['id' => "{$marca}-oculta", 'tipo' => 'video', 'nombre' => "{$marca}-oculta.mp4", 'bytes' => 10, 'url' => 'https://zoom.test/o'],
    ], 'tok');

    verificar('Apagado, la nueva nace oculta',
        ! Grabacion::query()->where('id_externo', "{$marca}-oculta")->firstOrFail()->visible_alumnos);

    // Y la que nació visible con el interruptor encendido no cambia al apagarlo.
    verificar('Apagarlo tampoco esconde lo que ya estaba publicado',
        $automatica->fresh()->visible_alumnos);

    echo PHP_EOL.'9. Sólo la abre quien es de esa materia'.PHP_EOL;

    $controlador = app(GrabacionController::class);

    /** Una petición a nombre de quien se diga. */
    $comoQuien = function (Usuario $quien) {
        $peticion = Request::create('/clases/grabaciones/1', 'GET');
        $peticion->setUserResolver(fn () => $quien);

        return $peticion;
    };

    $alumno = Usuario::query()
        ->where('persona_id', $inscripcion->matriculaOferta->persona_id)
        ->firstOrFail();

    verificar('El alumno inscrito la abre',
        estadoDe(fn () => $controlador->ver($comoQuien($alumno), $video->fresh())) === 200);

    /*
     * Alguien AJENO de verdad: 404 y no 403 — si no es suya, para quien pregunta
     * no existe.
     *
     * Se busca a quien NO esté inscrito en ESTA materia, y no «un alumno de otra
     * materia»: en el demo los alumnos llevan varias, así que el primero de otro
     * grupo resultó estar también en éste y la comprobación pasaba por el motivo
     * equivocado. Es la misma trampa que ya mordió con el asesor «que no era
     * asesor» y que sí lo era.
     */
    $personasDeLaMateria = Inscripcion::query()
        ->where('asignatura_grupo_id', $materia->id)
        ->with('matriculaOferta')
        ->get()
        ->pluck('matriculaOferta.persona_id')
        ->filter()
        ->all();

    $ajeno = Usuario::query()
        ->whereNotNull('persona_id')
        ->whereNotIn('persona_id', $personasDeLaMateria)
        // Y que sea alumno: alguien de control escolar entra por otro camino.
        ->whereHas('persona', fn ($q) => $q->whereHas('matriculas'))
        ->first();

    if ($ajeno === null) {
        verificar('Hay alguien ajeno con quien probar', false, 'no se encontró');
    } else {
        verificar('Alguien que no lleva la materia recibe 404',
            estadoDe(fn () => $controlador->ver($comoQuien($ajeno), $video->fresh())) === 404,
            "usuario {$ajeno->id}");
    }

    // Y apagada, ni el propio alumno.
    $video->update(['visible_alumnos' => false]);

    verificar('Apagada, ni el alumno inscrito la abre',
        estadoDe(fn () => $controlador->ver($comoQuien($alumno), $video->fresh())) === 404);

    echo PHP_EOL.'10. Meet: se consulta a Google y se traduce lo que devuelve'.PHP_EOL;

    /*
     * Contra respuestas FINGIDAS con la forma documentada de la API v2. El viaje
     * real contra Google no se puede ejercitar sin un Workspace; lo que sí se
     * comprueba aquí es la traducción, que es donde están las decisiones de
     * Acadion: qué se registra, qué se descarta y qué no se copia.
     */
    /*
     * Una llave RSA de usar y tirar, generada aquí.
     *
     * No una cadena inventada: `TokenDeServicio` firma el JWT de verdad con
     * `openssl_sign`, así que con un texto cualquiera reventaría antes de llegar
     * a lo que se quiere probar. Con una llave real se ejercita también la
     * firma, que es la parte que no se puede mirar contra Google.
     */
    $opciones = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    /*
     * El PHP de WAMP no trae `openssl.cnf` en la ruta que openssl busca, así que
     * `openssl_pkey_new` falla con «configuration file routines::no such file».
     * Es la misma familia de trampa que la de los certificados raíz. Se le pasa
     * el que sí existe; en Linux no hace falta y por eso se comprueba antes.
     */
    foreach ([
        'C:/wamp64/bin/php/php8.3.6/extras/ssl/openssl.cnf',
        'C:/wamp64/bin/apache/apache2.4.59/conf/openssl.cnf',
    ] as $cnf) {
        if (is_file($cnf)) {
            $opciones['config'] = $cnf;

            break;
        }
    }

    $par = openssl_pkey_new($opciones);

    if ($par === false) {
        throw new RuntimeException('No se pudo generar una llave de prueba: '.openssl_error_string());
    }

    openssl_pkey_export($par, $llavePrivada, null, $opciones);

    $cuentaServicio = json_encode([
        'client_email' => 'svc@x.iam.gserviceaccount.com',
        'private_key' => $llavePrivada,
    ]);

    $integracionMeet = IntegracionVideo::para('meet');
    $integracionMeet->update([
        'activa' => true,
        'credenciales' => ['cuenta_servicio_json' => $cuentaServicio, 'dominio' => 'x.mx'],
    ]);

    $cuentaMeet = CuentaVideo::create([
        'proveedor' => 'meet', 'etiqueta' => "{$marca} WS",
        'identificador' => "{$marca}@x.mx", 'activa' => true,
    ]);

    $claseMeet = Videoconferencia::create([
        'asignatura_grupo_id' => $materia->id,
        'cuenta_id' => $cuentaMeet->id,
        'proveedor' => 'meet',
        'titulo' => "{$marca} meet",
        'meeting_id' => 'evento-calendar-123',
        // De aquí sale el código de reunión: es el puente con la API de Meet.
        'url_join' => 'https://meet.google.com/abc-defg-hij',
        'inicio' => now()->subHours(3),
        'fin' => now()->subHours(2),
        'estado' => Videoconferencia::TERMINADA,
    ]);

    /**
     * Las tres llamadas que hace el consultor, con la forma que documenta Google.
     *
     * REINICIA los stubs antes de poner los suyos. `Http::fake` ACUMULA y gana
     * el primero que coincide, así que sin esto el paso siguiente seguiría
     * viendo la respuesta del anterior. Se descubrió aquí mismo: (b) recibía la
     * respuesta «aún grabando» de (a) y la prueba parecía decir que una
     * grabación lista no se registra, que es lo contrario de lo que pasa.
     *
     * Y no basta `Http::clearResolvedInstances()`: eso limpia el cache de la
     * fachada, pero la fábrica es un SINGLETON del contenedor y sigue siendo la
     * misma con sus stubs dentro. Hay que reponerla.
     */
    $googleResponde = function (array $grabaciones) {
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstances();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok-google'], 200),
            'meet.googleapis.com/v2/conferenceRecords?*' => Http::response([
                'conferenceRecords' => [['name' => 'conferenceRecords/rec-1']],
            ], 200),
            'meet.googleapis.com/v2/conferenceRecords/*/recordings' => Http::response([
                'recordings' => $grabaciones,
            ], 200),
        ]);
    };

    $consultor = app(ConsultorDeGrabacionesMeet::class);

    // a) Una que Google todavía está generando: NO se registra.
    DestinoGrabacion::query()->update(['activo' => false]);
    DestinoGrabacion::para('disco')->update(['activo' => true]);
    Queue::fake();
    /*
     * Con `driveDestination` puesto, a propósito.
     *
     * Sin él, esta comprobación pasaba aunque se quitara la regla del estado:
     * lo que la detenía era la falta de archivo, no el `STARTED`. Se vio
     * mutando. Google llena el destino en cuanto lo sabe, así que esto además
     * se parece más a lo que manda de verdad.
     */
    $googleResponde([
        [
            'name' => 'conferenceRecords/rec-1/recordings/aun-no',
            'state' => 'STARTED',
            'driveDestination' => ['file' => 'FILEID-EN-CURSO'],
        ],
    ]);

    $consultor->revisar($claseMeet);
    // Google la anuncia desde que empieza a grabar; hasta `FILE_GENERATED` el
    // archivo no existe y registrarla dejaría una pendiente imposible de bajar.
    verificar('Una grabación aún en curso no se registra',
        Grabacion::query()->where('videoconferencia_id', $claseMeet->id)->doesntExist());

    // b) Una lista: se registra y se encola la copia.
    Queue::fake();
    $googleResponde([
        [
            'name' => 'conferenceRecords/rec-1/recordings/lista',
            'state' => 'FILE_GENERATED',
            'driveDestination' => ['file' => 'FILEID-1', 'exportUri' => 'https://drive.google.com/file/d/FILEID-1/view'],
        ],
    ]);

    $nuevasMeet = $consultor->revisar($claseMeet);
    $deMeet = Grabacion::query()->where('videoconferencia_id', $claseMeet->id)->firstOrFail();

    verificar('Una ya generada sí se registra', $nuevasMeet === 1, (string) $nuevasMeet);
    verificar('Con el nombre del recurso como llave',
        $deMeet->id_externo === 'conferenceRecords/rec-1/recordings/lista', $deMeet->id_externo);
    verificar('Y queda pendiente de copiar al disco', $deMeet->estado === Grabacion::PENDIENTE);

    // c) Volver a preguntar no duplica: es el caso de todos los días, porque el
    //    comando corre cada tanto sobre las mismas clases.
    $repetidasMeet = $consultor->revisar($claseMeet);

    // Ni fila nueva ni copia encolada: lo que ya va en camino no se reencola,
    // o dos trabajadores bajarían el mismo video.
    verificar('Volver a consultar no encola otra copia', $repetidasMeet === 0, (string) $repetidasMeet);
    verificar('Y sigue habiendo una sola fila',
        Grabacion::query()->where('videoconferencia_id', $claseMeet->id)->count() === 1);

    // d) Con destino DRIVE no se copia nada: el archivo ya está ahí.
    Grabacion::query()->where('videoconferencia_id', $claseMeet->id)->forceDelete();
    DestinoGrabacion::query()->update(['activo' => false]);
    DestinoGrabacion::para('drive')->update([
        'activo' => true,
        'credenciales' => [
            'cuenta_servicio_json' => $cuentaServicio,
            'como_quien' => 'archivo@x.mx',
            'carpeta_id' => 'CARPETA',
        ],
    ]);

    Queue::fake();
    $googleResponde([
        [
            'name' => 'conferenceRecords/rec-1/recordings/en-drive',
            'state' => 'FILE_GENERATED',
            'driveDestination' => ['file' => 'FILEID-2', 'exportUri' => 'https://drive.google.com/file/d/FILEID-2/view'],
        ],
    ]);

    $consultor->revisar($claseMeet);
    $enDrive = Grabacion::query()->where('id_externo', 'conferenceRecords/rec-1/recordings/en-drive')->firstOrFail();

    /*
     * Copiar del mismo Drive al mismo Drive sería pagar dos veces el mismo
     * archivo y duplicar un video de menores sin ningún motivo.
     */
    verificar('Con destino Drive nace ya archivada', $enDrive->estado === Grabacion::ARCHIVADA, $enDrive->estado);
    verificar('Apuntando al archivo que Google dejó', $enDrive->ruta_destino === 'FILEID-2');
    verificar('Y no se encoló ninguna copia', count(Queue::pushedJobs()) === 0);

    // e) Si Google contesta con error, NO se inventa una lista vacía silenciosa.
    Grabacion::query()->where('videoconferencia_id', $claseMeet->id)->forceDelete();
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstances();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok-google'], 200),
        'meet.googleapis.com/*' => Http::response(['error' => ['message' => 'permiso denegado']], 403),
    ]);

    /*
     * Se escuchan los avisos del registro.
     *
     * Comprobar sólo que devuelve cero no probaba nada: con el manejo de
     * errores quitado también devuelve cero, porque un cuerpo de error no trae
     * `conferenceRecords`. Lo que de verdad separa «falló» de «no se grabó» es
     * que quede escrito, y eso es lo que hay que medir.
     */
    $avisos = [];
    Log::listen(function ($mensaje) use (&$avisos) {
        $avisos[] = $mensaje->message;
    });

    $conError = $consultor->revisar($claseMeet);

    verificar('Con error de Google no se registra nada', $conError === 0);
    verificar('Y no queda una grabación fantasma',
        Grabacion::query()->where('videoconferencia_id', $claseMeet->id)->doesntExist());
    // Una lista vacía en silencio es indistinguible de «esta clase no se grabó»,
    // y dejaría a la escuela creyendo que todo va bien.
    verificar('Pero el fallo SÍ queda registrado',
        collect($avisos)->contains(fn (string $m) => str_contains($m, 'Meet no devolvió')),
        implode(' | ', $avisos));

    // f) Un enlace del que no se puede sacar el código no revienta.
    $sinCodigo = Videoconferencia::create([
        'asignatura_grupo_id' => $materia->id,
        'cuenta_id' => $cuentaMeet->id,
        'proveedor' => 'meet',
        'titulo' => "{$marca} sin codigo",
        'url_join' => 'https://example.test/loquesea',
        'inicio' => now()->subHours(3),
        'fin' => now()->subHours(2),
        'estado' => Videoconferencia::TERMINADA,
    ]);

    verificar('Un enlace ilegible devuelve cero y no revienta', $consultor->revisar($sinCodigo) === 0);

    echo PHP_EOL.'11. Cambiar de destino no mueve lo ya archivado'.PHP_EOL;

    $rutaAntes = $video->fresh()->ruta_destino;
    $destinoAntes = $video->fresh()->destino;

    DestinoGrabacion::query()->update(['activo' => false]);
    DestinoGrabacion::para('dropbox')->update(['activo' => true]);

    $video->refresh();

    // Cada grabación guarda a dónde fue: lo viejo se sigue abriendo donde está.
    verificar('Sigue apuntando a donde se guardó', $video->destino === $destinoAntes);
    verificar('Y con la misma ruta', $video->ruta_destino === $rutaAntes);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();

    /*
     * El DISCO no entra en la transacción: lo que se escribió ahí hay que
     * borrarlo a mano, o cada corrida deja un archivo suelto. Es la misma
     * disciplina de «una suite crea sólo lo que su rollback puede deshacer»,
     * aplicada a lo que el rollback no alcanza.
     */
    foreach (array_filter($aBorrar) as $ruta) {
        Storage::disk('local')->delete($ruta);
    }

    // El cache de ajustes vive en memoria y no entra en el rollback: se olvida
    // para no dejar el interruptor cambiado para lo que corra después.
    app(Ajustes::class)->olvidar();

    echo PHP_EOL.'-- rollback aplicado y archivos de prueba borrados --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $f) {
    echo '  - '.$f.PHP_EOL;
}
