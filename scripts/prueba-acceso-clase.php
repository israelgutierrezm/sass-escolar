<?php

/**
 * Quién entró a la clase en línea. Con rollback.
 *
 * Se corre con `php scripts/prueba-acceso-clase.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. El alumno inscrito entra y queda ANOTADO. Sin eso, la tabla no sirve.
 *  2. El segundo clic NO crea otra fila: sube `veces`. Contar asistentes tiene
 *     que ser un `count()`, no un `count(distinct)` que alguien olvidará.
 *  3. Quien NO es de la materia recibe 404 —no 403—: no tiene por qué enterarse
 *     de que la clase existe.
 *  4. La clase cerrada NO entrega el enlace, y lo dice. Es la misma regla que
 *     decide si se dibuja el botón.
 *  5. El docente recibe el enlace de ANFITRIÓN y el alumno NUNCA. El `start_url`
 *     de Zoom entra como dueño de la sala.
 *  6. `paraElAlumno` ya no lleva el enlace del proveedor: lleva la puerta
 *     propia. Si volviera a llevarlo, el clic dejaría de anotarse.
 */

use App\Http\Controllers\EntrarAClaseController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Lms\AccesoVideoconferencia;
use App\Models\Lms\Videoconferencia;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

/** Invoca la puerta como esa persona y devuelve a dónde manda, o el fallo. */
function entrar(?Usuario $como, Videoconferencia $clase): array
{
    $peticion = Request::create("/clases/{$clase->id}/entrar", 'GET');
    $peticion->setUserResolver(fn () => $como);
    $peticion->headers->set('referer', '/mis-cursos');

    try {
        $r = app(EntrarAClaseController::class)($peticion, $clase);
    } catch (HttpException $e) {
        return ['estado' => $e->getStatusCode(), 'destino' => null];
    }

    return ['estado' => $r->getStatusCode(), 'destino' => $r->headers->get('Location')];
}

$db->beginTransaction();

try {
    /*
     * El escenario se CONSTRUYE entero.
     *
     * El demo no tiene ninguna clase en línea programada, y una prueba que se
     * salta la comprobación cuando no encuentra el caso es una prueba que se
     * apaga sola el día que cambian los datos.
     */
    $inscripcion = Inscripcion::query()
        ->whereNotNull('asignatura_grupo_id')
        ->whereNotNull('matricula_oferta_id')
        ->first();

    if ($inscripcion === null) {
        echo 'Esta escuela no tiene inscripciones; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $matricula = MatriculaOferta::query()->findOrFail($inscripcion->matricula_oferta_id);
    $alumno = Usuario::query()->where('persona_id', $matricula->persona_id)->first();

    if ($alumno === null) {
        $alumno = Usuario::create([
            'persona_id' => $matricula->persona_id,
            'usuario' => 'prueba.acceso.'.random_int(100000, 999999),
            'password' => bcrypt('x'),
        ]);
    }

    // Alguien AJENO a la materia, para el 404.
    $ajeno = Usuario::query()
        ->whereNotNull('persona_id')
        ->where('persona_id', '!=', $matricula->persona_id)
        ->whereNotIn('persona_id', MatriculaOferta::query()
            ->whereIn('id', Inscripcion::query()
                ->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)
                ->select('matricula_oferta_id'))
            ->select('persona_id'))
        ->first();

    $clase = Videoconferencia::create([
        'asignatura_grupo_id' => $inscripcion->asignatura_grupo_id,
        'proveedor' => 'zoom',
        'titulo' => 'Clase de prueba',
        'meeting_id' => 'prueba-'.random_int(100000, 999999),
        'url_join' => 'https://zoom.test/j/invitado',
        'url_anfitrion' => 'https://zoom.test/s/ANFITRION-ES-UNA-LLAVE',
        'inicio' => now()->subMinutes(5),
        'fin' => now()->addHour(),
        'estado' => Videoconferencia::PROGRAMADA,
    ]);

    echo '1. El alumno inscrito entra, y queda anotado'.PHP_EOL;

    $r = entrar($alumno, $clase);

    verificar('Lo redirige (302)', $r['estado'] === 302, (string) $r['estado']);
    verificar('Al enlace del INVITADO', $r['destino'] === 'https://zoom.test/j/invitado', (string) $r['destino']);

    $fila = AccesoVideoconferencia::query()
        ->where('videoconferencia_id', $clase->id)
        ->where('persona_id', $matricula->persona_id)
        ->first();

    verificar('Quedó la fila del acceso', $fila !== null);
    verificar('Con el papel de alumno', $fila?->papel === AccesoVideoconferencia::ALUMNO, (string) $fila?->papel);
    verificar('Y `veces` en 1', (int) $fila?->veces === 1, (string) $fila?->veces);

    echo PHP_EOL.'2. El segundo clic NO duplica la fila'.PHP_EOL;

    entrar($alumno, $clase);
    entrar($alumno, $clase);

    $cuantas = AccesoVideoconferencia::query()
        ->where('videoconferencia_id', $clase->id)
        ->where('persona_id', $matricula->persona_id)
        ->count();

    verificar('Sigue habiendo UNA sola fila', $cuantas === 1, (string) $cuantas);

    $fila->refresh();
    verificar('Y `veces` subió a 3', (int) $fila->veces === 3, (string) $fila->veces);
    verificar('El primer acceso NO se movió',
        $fila->primer_acceso->lte($fila->ultimo_acceso));

    echo PHP_EOL.'3. Quien no es de la materia recibe 404, no 403'.PHP_EOL;

    if ($ajeno === null) {
        verificar('Hay alguien ajeno con quien probarlo', false, 'sin datos');
    } else {
        $r = entrar($ajeno, $clase);

        verificar('404 —un 403 ya revelaría que la clase existe—',
            $r['estado'] === 404, (string) $r['estado']);

        verificar('Y no le quedó fila',
            ! AccesoVideoconferencia::query()
                ->where('videoconferencia_id', $clase->id)
                ->where('persona_id', $ajeno->persona_id)
                ->exists());
    }

    echo PHP_EOL.'4. La clase que ya terminó no entrega el enlace'.PHP_EOL;

    $vieja = Videoconferencia::create([
        'asignatura_grupo_id' => $inscripcion->asignatura_grupo_id,
        'proveedor' => 'zoom',
        'titulo' => 'Clase de ayer',
        'meeting_id' => 'vieja-'.random_int(100000, 999999),
        'url_join' => 'https://zoom.test/j/vieja',
        'url_anfitrion' => 'https://zoom.test/s/vieja',
        'inicio' => now()->subDays(2),
        'fin' => now()->subDays(2)->addHour(),
        'estado' => Videoconferencia::TERMINADA,
    ]);

    $r = entrar($alumno, $vieja);

    verificar('No redirige al proveedor (303 de vuelta)', $r['estado'] === 303, (string) $r['estado']);
    verificar('Y NO le quedó anotado un acceso que no ocurrió',
        ! AccesoVideoconferencia::query()->where('videoconferencia_id', $vieja->id)->exists());

    echo PHP_EOL.'5. El anfitrión es del docente, y sólo del docente'.PHP_EOL;

    $docentePersona = $clase->materia?->docentes()->first()?->persona_id;

    if ($docentePersona === null) {
        verificar('La materia tiene docente asignado con quien probarlo', false, 'sin docente');
    } else {
        $docente = Usuario::query()->where('persona_id', $docentePersona)->first()
            ?? Usuario::create([
                'persona_id' => $docentePersona,
                'usuario' => 'prueba.doc.'.random_int(100000, 999999),
                'password' => bcrypt('x'),
            ]);

        $r = entrar($docente, $clase);

        verificar('Al docente se le da el enlace de ANFITRIÓN',
            $r['destino'] === 'https://zoom.test/s/ANFITRION-ES-UNA-LLAVE', (string) $r['destino']);

        $suya = AccesoVideoconferencia::query()
            ->where('videoconferencia_id', $clase->id)
            ->where('persona_id', $docentePersona)
            ->first();

        verificar('Y su llegada también se anota', $suya !== null);
        verificar('Con el papel de docente',
            $suya?->papel === AccesoVideoconferencia::DOCENTE, (string) $suya?->papel);

        // La clase terminada SÍ la puede abrir el docente: es su sala.
        $r = entrar($docente, $vieja);
        verificar('El docente entra aunque la clase ya haya pasado —es su sala—',
            $r['estado'] === 302, (string) $r['estado']);
    }

    echo PHP_EOL.'6. El enlace del proveedor ya no viaja al alumno'.PHP_EOL;

    $paraElAlumno = $clase->paraElAlumno(10);

    verificar('`url` es la puerta propia, no la de Zoom',
        $paraElAlumno['url'] === "/clases/{$clase->id}/entrar", (string) $paraElAlumno['url']);

    verificar('En NINGÚN campo viaja el url_join',
        ! str_contains(json_encode($paraElAlumno), 'zoom.test/j/'));

    verificar('Ni el de anfitrión, por supuesto',
        ! str_contains(json_encode($paraElAlumno), 'ANFITRION'));

    $cerrada = $vieja->paraElAlumno(10);
    verificar('Una clase cerrada ni siquiera manda la puerta',
        $cerrada['url'] === null, (string) $cerrada['url']);

    echo PHP_EOL.'7. Lo que el docente ve de su clase'.PHP_EOL;

    $accesos = app(App\Services\Videoconferencia\RegistroDeAcceso::class)->deLaClase($clase);

    verificar('Trae a los dos que entraron', $accesos->count() === 2, (string) $accesos->count());
    verificar('Ordenados por hora de llegada',
        $accesos->first()->primer_acceso->lte($accesos->last()->primer_acceso));
    verificar('Con la persona cargada, para poder nombrarla',
        $accesos->first()->persona !== null);
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
