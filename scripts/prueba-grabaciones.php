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

use App\Http\Controllers\GrabacionController;
use App\Jobs\ArchivarGrabacion;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\DestinoGrabacion;
use App\Models\Lms\Grabacion;
use App\Models\Lms\Videoconferencia;
use App\Models\Tenant;
use App\Services\Grabaciones\Destinos;
use App\Services\Grabaciones\RecolectorDeGrabaciones;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
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
     * Foto del directorio temporal ANTES de archivar nada.
     *
     * Se compara contra la de después para ver si el trabajo dejó basura. No se
     * busca por prefijo: en Windows `tempnam()` recorta el prefijo a TRES
     * letras —«grabacion-» se vuelve «gra910D.tmp»—, así que un glob por
     * «grabacion-*» no encuentra nunca nada y la comprobación pasaría siempre.
     * Se descubrió mutando el borrado a propósito: la prueba seguía en verde.
     */
    $temporalesAntes = glob(sys_get_temp_dir().'/*.tmp') ?: [];

    echo PHP_EOL.'4. El trabajo descarga, sube y anota dónde quedó'.PHP_EOL;

    Grabacion::query()->where('videoconferencia_id', $clase->id)->update(['estado' => Grabacion::PENDIENTE]);
    $video = Grabacion::query()->where('id_externo', "{$marca}-v")->firstOrFail();

    $contenido = 'VIDEO-DE-PRUEBA-'.str_repeat('x', 500);
    Http::fake(['*' => Http::response($contenido, 200)]);

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
    Http::fake(['*' => Http::response('', 200)]);

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
    $nuevos = array_diff(glob(sys_get_temp_dir().'/*.tmp') ?: [], $temporalesAntes);

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

    echo PHP_EOL.'8. Sólo la abre quien es de esa materia'.PHP_EOL;

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

    echo PHP_EOL.'9. Cambiar de destino no mueve lo ya archivado'.PHP_EOL;

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

    echo PHP_EOL.'-- rollback aplicado y archivos de prueba borrados --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $f) {
    echo '  - '.$f.PHP_EOL;
}
