<?php

/**
 * Quién puede ver la CARA de quién.
 *
 * Se corre con `php scripts/prueba-foto-de-persona.php` desde la raíz.
 *
 * ── El hueco que esto vigila ──────────────────────────────────────────────
 * `FotoPersonaController::mostrar()` recibía la petición y NO la miraba: la
 * ruta va detrás de `auth` y sin un solo `can:`, así que cualquier persona con
 * cuenta —un alumno, un aspirante, un padre— podía pedir `/personas/1/foto`,
 * `/personas/2/foto`… y bajarse la cara de toda la escuela, menores incluidos.
 * Los ids son consecutivos: enumerarlos es trivial.
 *
 * Comprobado antes de arreglarlo: `alumno.demo.1`, sin ninguno de los permisos
 * de personal, recibía un 200 con la foto del director.
 *
 * El docblock del controlador YA decía la regla —«cualquiera que pueda ver su
 * ficha»— y no la aplicaba ninguna línea. Guardar el archivo en el disco
 * privado no sirve de nada si la ruta que lo sirve no pregunta quién llama.
 *
 * ── Por qué el escenario se SIEMBRA ───────────────────────────────────────
 * En el demo casi nadie tiene foto, así que «no se la pudo bajar» se cumpliría
 * solo —por 404 de archivo inexistente y no por la salvaguarda—. Cada caso pone
 * su foto dentro de la transacción.
 */

use App\Http\Controllers\FotoPersonaController;
use App\Models\Admisiones\Aspirante;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

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

/** ¿Le entrega el controlador la foto de esa persona a ese usuario? */
function seLaLleva(Usuario $usuario, Persona $persona): bool
{
    return codigoDe($usuario, $persona) === 200;
}

/** Con qué código responde el controlador. */
function codigoDe(Usuario $usuario, Persona $persona): int
{
    auth()->login($usuario);

    $peticion = Request::create('/personas/'.$persona->id.'/foto', 'GET');
    $peticion->setUserResolver(fn () => $usuario);

    try {
        return app(FotoPersonaController::class)->mostrar($peticion, $persona)->getStatusCode();
    } catch (Throwable $e) {
        return method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
    }
}

/** Le pone una foto de verdad, para que el 404 no venga del archivo ausente. */
function conFoto(Persona $persona): Persona
{
    global $temporales;

    $ruta = 'fotos/prueba-foto-'.$persona->id.'.jpg';
    Storage::disk('local')->put($ruta, 'CARA DE '.$persona->id);
    $persona->update(['foto_url' => $ruta]);
    $temporales[] = $ruta;

    return $persona->refresh();
}

/** Una cuenta con EXACTAMENTE los permisos que se le den, y ninguno más. */
function usuarioCon(array $permisos): Usuario
{
    static $n = 0;
    $n++;

    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Foto',
        'curp' => 'PFO'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).random_int(100000, 999999).'XY',
    ]);

    $rol = Rol::create([
        'name' => 'prueba-foto-'.$persona->id,
        'nombre' => 'Prueba foto',
        'guard_name' => 'web',
    ]);

    foreach ($permisos as $clave) {
        $rol->givePermissionTo(Permission::findOrCreate($clave, 'web'));
    }

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba.foto.'.$persona->id,
        'password' => bcrypt('sin-importancia'),
        'activo' => true,
    ]);

    DB::table('persona_rol')->insert([
        'persona_id' => $persona->id,
        'rol_id' => $rol->id,
        'activo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $usuario->update(['rol_activo_id' => $rol->id]);

    return $usuario->refresh();
}

tenancy()->initialize(App\Models\Tenant::find('demo'));
DB::beginTransaction();

$temporales = [];

try {
    $alumno = conFoto(Persona::query()->whereHas('matriculas')->firstOrFail());
    $docente = conFoto(Persona::query()->whereHas('docente')->firstOrFail());

    echo PHP_EOL.'1. El desconocido con cuenta NO se lleva la cara de nadie'.PHP_EOL;

    $curioso = usuarioCon([]);

    verificar('Una cuenta sin permisos no baja la foto de una alumna',
        ! seLaLleva($curioso, $alumno),
        'era el defecto: devolvía 200 con la imagen');

    verificar('Ni la de un docente',
        ! seLaLleva($curioso, $docente));

    /*
     * 404 y no 403: un 403 confirma que esa persona existe y tiene foto, así
     * que enumerando ids se levanta el padrón sin ver una sola cara.
     */
    verificar('Y se le niega con 404, no con 403 —un 403 confirmaría que existe',
        codigoDe($curioso, $alumno) === 404,
        'respondió '.codigoDe($curioso, $alumno));

    echo PHP_EOL.'2. Cada oficio ve lo suyo, y sólo lo suyo'.PHP_EOL;

    verificar('Control escolar ve a la alumna',
        seLaLleva(usuarioCon(['ver-alumnos']), $alumno));

    verificar('…pero con ver-alumnos NO ve al docente',
        ! seLaLleva(usuarioCon(['ver-alumnos']), $docente),
        'el permiso de un oficio no abre el de otro');

    verificar('El catálogo de docentes ve al docente',
        seLaLleva(usuarioCon(['gestionar-docentes']), $docente));

    verificar('…y con gestionar-docentes NO ve a la alumna',
        ! seLaLleva(usuarioCon(['gestionar-docentes']), $alumno));

    $aspirante = Aspirante::query()->whereNotNull('persona_id')
        ->whereDoesntHave('persona.matriculas')
        ->firstOrFail();
    $aspirantePersona = conFoto($aspirante->persona);

    verificar('Admisiones ve al prospecto',
        seLaLleva(usuarioCon(['ver-aspirantes']), $aspirantePersona));

    verificar('…y quien sólo ve alumnos, no',
        ! seLaLleva(usuarioCon(['ver-alumnos']), $aspirantePersona),
        'por eso el prospecto elegido NO es además alumno');

    echo PHP_EOL.'3. Uno mismo, siempre'.PHP_EOL;

    $suya = usuarioCon([]);
    conFoto($suya->persona);

    verificar('Sin un solo permiso, cada quien ve su propia cara',
        seLaLleva($suya, $suya->persona->refresh()),
        'las usan Mi perfil y Mi expediente');

    echo PHP_EOL.'4. Los vínculos: el permiso dice QUÉ, el vínculo dice SOBRE QUIÉN'.PHP_EOL;

    $otroAlumno = conFoto(Persona::query()->whereHas('matriculas')
        ->where('id', '!=', $alumno->id)->firstOrFail());

    $padre = usuarioCon(['ver-mis-hijos']);
    DB::table('tutores_alumno')->insert([
        'tutor_persona_id' => $padre->persona_id,
        'alumno_persona_id' => $alumno->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    verificar('El padre ve a SU hijo',
        seLaLleva($padre, $alumno));

    verificar('…y NO ve al hijo de otro',
        ! seLaLleva($padre, $otroAlumno),
        'el permiso solo no basta');

    $tutor = usuarioCon(['ver-mis-tutorados']);
    DB::table('tutorias')->insert([
        'tutor_persona_id' => $tutor->persona_id,
        'alumno_persona_id' => $alumno->id,
        'ciclo_id' => DB::table('ciclos')->value('id'),
        'activa' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    verificar('El tutor educativo ve a SU tutorado',
        seLaLleva($tutor, $alumno));

    verificar('…y no al de otro tutor',
        ! seLaLleva($tutor, $otroAlumno));

    $asesor = usuarioCon(['ver-mis-prospectos']);
    DB::table('asesores')->insert([
        'persona_id' => $asesor->persona_id,
        'situacion_id' => DB::table('situaciones_asesor')->value('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('aspirante_asesor')->insert([
        'aspirante_id' => $aspirante->id,
        'persona_id' => $asesor->persona_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    verificar('El asesor ve al prospecto que le tocó',
        seLaLleva($asesor, $aspirantePersona));

    verificar('…y otro asesor, con el mismo permiso y sin la asignación, no',
        ! seLaLleva(usuarioCon(['ver-mis-prospectos']), $aspirantePersona));

    echo PHP_EOL.'5. Los tres consumidores que la primera versión habría roto'.PHP_EOL;

    /*
     * Se enumeraron todos los `urlFoto()` del backend antes de dar la regla por
     * buena, y tres pantallas no encajaban en ella. El síntoma no habría sido un
     * error: habría sido una pantalla llena de avatares rotos, que es de las
     * cosas que nadie reporta como defecto.
     */
    verificar('La inscripción por grupo ve a los alumnos que va a inscribir',
        seLaLleva(usuarioCon(['inscribir-alumnos']), $alumno),
        '/escolar/inscripciones/masiva los pinta con foto');

    $tutorFamiliar = Persona::query()->whereHas('hijos')->firstOrFail();
    conFoto($tutorFamiliar);

    verificar('El padrón de padres y tutores ve a un tutor',
        seLaLleva(usuarioCon(['ver-tutores']), $tutorFamiliar),
        'un tutor no es alumno ni docente ni aspirante');

    /*
     * Y `ver-tutores` no es un pase libre: sin este caso, quitarle la condición
     * de «tiene a alguien a cargo» dejaba la prueba en verde con el permiso
     * abriendo la cara de cualquiera. Sobrevivió una mutación por esto.
     */
    $noEsTutor = conFoto(Persona::query()
        ->whereHas('docente')
        ->whereDoesntHave('hijos')
        ->firstOrFail());

    verificar('…y con ver-tutores NO ve a quien no tiene a nadie a cargo',
        ! seLaLleva(usuarioCon(['ver-tutores']), $noEsTutor),
        'el permiso abre el padrón de tutores, no el de toda la escuela');

    /*
     * Y el caso que va al revés de todos los demás: el ALUMNO mirando hacia
     * arriba. «Mi materia» enumera a sus docentes con foto.
     */
    $inscripcion = App\Models\ControlEscolar\Inscripcion::query()
        ->whereHas('asignaturaGrupo.docentes')
        ->with(['matriculaOferta', 'asignaturaGrupo.docentes'])
        ->firstOrFail();

    $suDocente = conFoto(
        Persona::findOrFail($inscripcion->asignaturaGrupo->docentes->first()->persona_id)
    );

    $suAlumno = Usuario::query()
        ->where('persona_id', $inscripcion->matriculaOferta->persona_id)
        ->first() ?? usuarioCon(['ver-mis-cursos']);

    if ($suAlumno->persona_id === $inscripcion->matriculaOferta->persona_id) {
        // La cuenta del alumno del demo existe; se le asegura el permiso.
        $rol = App\Models\Identidad\Rol::find($suAlumno->rol_activo_id);
        $rol?->givePermissionTo(Permission::findOrCreate('ver-mis-cursos', 'web'));
        $suAlumno = $suAlumno->refresh();

        verificar('El alumno ve la cara de SU docente',
            seLaLleva($suAlumno, $suDocente),
            'sin esto, «Mi materia» se llenaba de avatares rotos');
    }

    verificar('…y otro alumno, con el mismo permiso y sin esa clase, no',
        ! seLaLleva(usuarioCon(['ver-mis-cursos']), $suDocente),
        'el vínculo es la inscripción, no el permiso');

    echo PHP_EOL.'6. La comprobación vive en el CONTROLADOR, no en la ruta'.PHP_EOL;

    $rutas = file_get_contents(__DIR__.'/../routes/tenant.php');
    preg_match('/FotoPersonaController::class\)(.*?)\}\);/s', $rutas, $bloque);

    verificar('La ruta sigue sin `can:`, porque por ahí entran siete oficios',
        isset($bloque[1]) && ! str_contains($bloque[1], 'can:'),
        'un middleware con el permiso de uno rebotaría a los otros seis');

    verificar('Y por eso el controlador comprueba al LEER, no sólo al escribir',
        str_contains(
            file_get_contents(__DIR__.'/../app/Http/Controllers/FotoPersonaController.php'),
            '$this->autorizarVer($request, $persona);'
        ));

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    foreach (array_filter($temporales) as $ruta) {
        Storage::disk('local')->delete($ruta);
    }

    DB::rollBack();
}
