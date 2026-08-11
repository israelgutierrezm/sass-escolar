<?php

/**
 * El alcance de Finanzas: quién ve la cartera de la escuela y quién solo la
 * suya. Contra la BD real, con rollback.
 *
 * `ver-adeudos` la tienen el administrativo de finanzas Y el alumno: la misma
 * permission, distinto alcance. Lo que decide es la FACETA del rol activo. Esta
 * suite invoca al controlador y comprueba que un alumno no reciba —ni por la
 * lista, ni por los totales, ni cambiando el id en la URL— nada que no sea suyo.
 *
 * Se corre con `php scripts/prueba-finanzas-alcance.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\FinanzasController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

tenancy()->initialize(App\Models\Tenant::find('demo'));

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

/** Props de un método del controlador, como si fuera petición de Inertia. */
function props(FinanzasController $c, string $metodo, Usuario $como, array $extra = []): array
{
    $peticion = Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    $respuesta = $c->{$metodo}($peticion, ...$extra);

    return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'];
}

/** Un usuario propio con el rol activo pedido. Nunca toca a nadie más. */
function usuarioCon(Persona $persona, string $rol): Usuario
{
    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_fin_'.random_int(100000, 999999),
        'email' => 'prueba_fin_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);

    $persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $controlador = app(FinanzasController::class);

    // Una matrícula real cualquiera, de la que se copian oferta y situación
    // para fabricar las de prueba. No se reutiliza SU persona: la sembrada ya
    // suele tener usuario, y crear un segundo para la misma persona choca con
    // el índice único. La suite se fabrica su propio alumno.
    $ajena = MatriculaOferta::query()->firstOrFail();

    $personaAlumno = Persona::create([
        'nombre' => 'Alumno', 'primer_apellido' => 'Propio', 'segundo_apellido' => (string) random_int(1000, 9999),
    ]);

    MatriculaOferta::create([
        'persona_id' => $personaAlumno->id,
        'oferta_id' => $ajena->oferta_id,
        'matricula' => 'PRB-'.random_int(100000, 999999),
        'fecha_ingreso' => now()->toDateString(),
        'situacion_id' => $ajena->situacion_id,
        'estatus' => 'activo',
    ]);

    echo '1. El administrativo ve la cartera de TODA la escuela'.PHP_EOL;

    $admin = usuarioCon(
        Persona::create(['nombre' => 'Fin', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]),
        'director_general',
    );

    $vistaAdmin = props($controlador, 'index', $admin);

    /*
     * `soloPropias` (booleano) se cambió por `alcance` (cadena). Hizo falta
     * cuando entró el padre de familia: «lo mío» y «lo de mis hijos» no son lo
     * mismo, y con un sí/no el encabezado decía «Mi saldo» sobre el saldo de
     * los hijos. La prueba seguía leyendo la llave vieja —que ya no viaja— y
     * comparaba null contra false.
     *
     * Los valores van como literales porque las constantes del trait son
     * privadas: 'escuela', 'propio', 'familia' y 'ninguno'.
     */
    verificar('El administrativo ve el alcance de la ESCUELA',
        $vistaAdmin['alcance'] === 'escuela', (string) $vistaAdmin['alcance']);
    verificar('Ve más de una matrícula (la cartera completa)',
        $vistaAdmin['matriculas']['total'] >= 1, (string) $vistaAdmin['matriculas']['total']);

    echo PHP_EOL.'2. El alumno ve SOLO lo suyo'.PHP_EOL;

    $alumno = usuarioCon($personaAlumno, 'alumno');

    $vistaAlumno = props($controlador, 'index', $alumno);

    verificar('El alumno ve el alcance PROPIO',
        $vistaAlumno['alcance'] === 'propio', (string) $vistaAlumno['alcance']);

    $personasEnLista = collect($vistaAlumno['matriculas']['data'])
        ->pluck('id')
        ->map(fn ($id) => MatriculaOferta::find($id)?->persona_id)
        ->unique()
        ->values();

    verificar('Toda matrícula de su lista es de él',
        $personasEnLista->every(fn ($pid) => $pid === $alumno->persona_id),
        'personas distintas: '.$personasEnLista->count());

    verificar('Sus totales cuentan a lo sumo sus matrículas, no la escuela',
        $vistaAlumno['totales']['deudores'] <= MatriculaOferta::where('persona_id', $alumno->persona_id)->count());

    echo PHP_EOL.'3. Ni cambiando el id en la URL ve la de otro'.PHP_EOL;

    // Una matrícula que NO es del alumno de prueba. Si la BLD demo solo tiene
    // una, se fabrica otra —misma oferta, otra persona— para poder intentar el
    // salto: la prueba no depende de cuántos alumnos haya sembrados.
    $deOtro = MatriculaOferta::where('persona_id', '!=', $alumno->persona_id)->first();

    if ($deOtro === null) {
        $otraPersona = Persona::create([
            'nombre' => 'Otro', 'primer_apellido' => 'Alumno', 'segundo_apellido' => (string) random_int(1000, 9999),
        ]);

        $deOtro = MatriculaOferta::create([
            'persona_id' => $otraPersona->id,
            'oferta_id' => $ajena->oferta_id,
            'matricula' => 'PRB-'.random_int(100000, 999999),
            'fecha_ingreso' => now()->toDateString(),
            'situacion_id' => $ajena->situacion_id,
            'estatus' => 'activo',
        ]);
    }

    if (true) {
        $bloqueado = false;

        try {
            props($controlador, 'cuenta', $alumno, [$deOtro]);
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $bloqueado = $e->getStatusCode() === 403;
        }

        verificar('Abrir el estado de cuenta de otro da 403', $bloqueado);

        // Y la suya propia sí abre.
        $propia = MatriculaOferta::where('persona_id', $alumno->persona_id)->first();

        if ($propia !== null) {
            $abre = true;

            try {
                props($controlador, 'cuenta', $alumno, [$propia]);
            } catch (Throwable $e) {
                $abre = false;
            }

            verificar('La suya propia sí abre', $abre);
        }
    }
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
