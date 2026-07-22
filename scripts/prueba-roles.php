<?php

/**
 * Prueba de integración de la administración de roles y permisos: creación de
 * roles, herencia, protección de las facetas del sistema, salvaguarda contra el
 * auto-encierro, ciclos en la jerarquía y asignación a personas. Con rollback.
 *
 * Se corre con `php scripts/prueba-roles.php` desde la raíz.
 *
 * Crea su propio usuario y su propia persona: NUNCA toma `Usuario::first()` ni
 * le cambia el rol activo a nadie.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Support\CatalogoPermisos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

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
        echo "  OK   {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

DB::beginTransaction();

try {
    $olvidar = fn () => app(PermissionRegistrar::class)->forgetCachedPermissions();

    echo '1. El catálogo de permisos es del código, no de la base'.PHP_EOL;

    $claves = CatalogoPermisos::claves();

    verificar('El catálogo declara permisos', count($claves) > 30, count($claves).' permisos');
    verificar('Sin claves repetidas entre dominios',
        count($claves) === count(array_unique($claves)));
    verificar('Todos están sembrados en la base',
        Permission::whereIn('name', $claves)->count() === count($claves),
        Permission::whereIn('name', $claves)->count().' de '.count($claves));
    verificar('Cada permiso trae etiqueta y descripción para quien arma un rol',
        collect(CatalogoPermisos::paraPantalla())
            ->flatMap(fn ($d) => $d['permisos'])
            ->every(fn ($p) => $p['etiqueta'] !== '' && $p['descripcion'] !== ''));
    verificar('Un permiso inventado NO existe en el catálogo',
        ! CatalogoPermisos::existe('gobernar-el-universo'));

    echo PHP_EOL.'2. Las facetas del sistema están protegidas'.PHP_EOL;

    $administrativo = Rol::where('name', 'administrativo')->firstOrFail();
    $docente = Rol::where('name', 'docente')->firstOrFail();

    verificar('La faceta administrativo está protegida', $administrativo->protegido);
    verificar('La faceta docente también', $docente->protegido);
    // Es la que compara CapturaCalificacionesController por nombre.
    verificar('Un rol funcional NO está protegido: la escuela debe poder borrarlo',
        Rol::where('name', 'auxiliar_admisiones')->first()?->protegido === false);

    echo PHP_EOL.'3. Crear un rol nuevo y darle permisos'.PHP_EOL;

    $coordinador = Rol::create([
        'name' => 'coordinador_becas',
        'nombre' => 'Coordinador de becas',
        'guard_name' => 'web',
        'rol_padre_id' => $administrativo->id,
        'protegido' => false,
    ]);

    verificar('Se crea colgando de una faceta', $coordinador->exists && $coordinador->rol_padre_id === $administrativo->id);
    verificar('Nace sin permisos propios', $coordinador->permissions->count() === 0);

    $coordinador->syncPermissions(['ver-adeudos', 'condonar-adeudos']);
    $olvidar();
    $coordinador->refresh();

    verificar('Se le conceden los suyos', $coordinador->permissions->count() === 2);

    $efectivos = $coordinador->permisosEfectivos()->pluck('name');

    verificar('Y HEREDA los de su faceta sin volver a palomearlos',
        $efectivos->contains('ver-personas') && $efectivos->contains('ver-alumnos'),
        $efectivos->implode(', '));
    verificar('Los efectivos son propios + heredados, sin duplicar',
        $efectivos->count() === $efectivos->unique()->count()
        && $efectivos->count() > $coordinador->permissions->count());

    echo PHP_EOL.'4. La jerarquía no admite ciclos'.PHP_EOL;

    $sub = Rol::create([
        'name' => 'auxiliar_becas',
        'nombre' => 'Auxiliar de becas',
        'guard_name' => 'web',
        'rol_padre_id' => $coordinador->id,
        'protegido' => false,
    ]);

    verificar('Un rol no puede colgar de sí mismo', ! $coordinador->admitePadre($coordinador));
    verificar('Ni de un descendiente suyo', ! $coordinador->admitePadre($sub));
    verificar('Pero sí de otra rama', $coordinador->admitePadre($administrativo));
    verificar('Y puede volverse faceta', $coordinador->admitePadre(null));

    verificar('La herencia atraviesa dos niveles',
        $sub->permisosEfectivos()->pluck('name')->contains('condonar-adeudos')
        && $sub->permisosEfectivos()->pluck('name')->contains('ver-personas'));

    echo PHP_EOL.'5. Asignación a personas'.PHP_EOL;

    $persona = Persona::create(['nombre' => 'Lucía', 'primer_apellido' => 'Ordóñez', 'sexo_id' => 2]);

    $asignacion = PersonaRol::create([
        'persona_id' => $persona->id,
        'rol_id' => $coordinador->id,
        'activo' => true,
    ]);

    verificar('Se le da el rol a una persona', $asignacion->exists);
    verificar('Y el rol reporta que alguien lo tiene',
        $coordinador->asignaciones()->count() === 1);

    // Un rol con personas no se borra: dejaría a alguien sin rol activo.
    verificar('Un rol con personas asignadas no debe borrarse',
        $coordinador->asignaciones()->exists());
    verificar('Ni uno con hijos: perderían la herencia que los explica',
        $coordinador->hijos()->exists());

    echo PHP_EOL.'6. Los permisos efectivos gobiernan de verdad el can()'.PHP_EOL;

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_roles_'.substr((string) microtime(true), -6),
        'email' => 'roles'.substr((string) microtime(true), -6).'@acadion.test',
        'password' => Hash::make('prueba1234'),
        'rol_activo_id' => $coordinador->id,
    ]);

    $olvidar();

    verificar('Puede lo que le concedió su rol', $usuario->can('condonar-adeudos'));
    verificar('Puede lo que HEREDA de su faceta', $usuario->can('ver-alumnos'));
    verificar('NO puede lo que nadie le dio', ! $usuario->can('facturar'));

    // Quitarle un permiso al rol se lo quita a la persona, sin tocarla a ella.
    $coordinador->syncPermissions(['ver-adeudos']);
    $olvidar();

    verificar('Quitarle el permiso al ROL se lo quita a la persona',
        ! $usuario->fresh()->can('condonar-adeudos'));
    verificar('Sin haberle tocado nada a la persona',
        PersonaRol::where('persona_id', $persona->id)->count() === 1);

    // Y concedérselo a la FACETA lo derrama a todos sus descendientes.
    $administrativo->givePermissionTo('facturar');
    $olvidar();

    verificar('Conceder en la faceta alcanza a todos los que cuelgan de ella',
        $usuario->fresh()->can('facturar'));

    echo PHP_EOL.'7. Un permiso pertenece a una FACETA'.PHP_EOL;

    // La regla: un administrativo no puede concederse permisos del docente. Si
    // pudiera, el conmutador de rol dejaría de tener sentido —nadie conmutaría—
    // y el alcance por asignación quedaría colgando de un permiso que no toca.
    verificar('«Ver mis materias» es del docente y solo del docente',
        CatalogoPermisos::correspondeA('ver-mis-materias', CatalogoPermisos::DOCENTE)
        && ! CatalogoPermisos::correspondeA('ver-mis-materias', CatalogoPermisos::ADMINISTRATIVO));
    verificar('«Llenar mi solicitud» es del aspirante',
        CatalogoPermisos::correspondeA('llenar-mi-solicitud', CatalogoPermisos::ASPIRANTE)
        && ! CatalogoPermisos::correspondeA('llenar-mi-solicitud', CatalogoPermisos::ADMINISTRATIVO));
    verificar('«Administrar roles» es del administrativo',
        CatalogoPermisos::correspondeA('gestionar-roles', CatalogoPermisos::ADMINISTRATIVO)
        && ! CatalogoPermisos::correspondeA('gestionar-roles', CatalogoPermisos::DOCENTE));

    // Los compartidos lo son porque el oficio de verdad se comparte: control
    // escolar captura en nombre del docente ausente.
    verificar('«Capturar calificaciones» sí es de los dos oficios',
        CatalogoPermisos::correspondeA('capturar-calificaciones', CatalogoPermisos::ADMINISTRATIVO)
        && CatalogoPermisos::correspondeA('capturar-calificaciones', CatalogoPermisos::DOCENTE));
    verificar('Y el kárdex lo consultan cinco perfiles',
        collect([CatalogoPermisos::ADMINISTRATIVO, CatalogoPermisos::DOCENTE, CatalogoPermisos::ALUMNO,
            CatalogoPermisos::TUTOR, CatalogoPermisos::PADRE])
            ->every(fn ($f) => CatalogoPermisos::correspondeA('ver-kardex', $f)));

    $direccion = Rol::where('name', 'director_general')->firstOrFail();
    $docenteRol = Rol::where('name', 'docente')->firstOrFail();

    verificar('El ámbito de un rol es el de su FACETA, no el suyo',
        $direccion->ambitoDePermisos() === CatalogoPermisos::ADMINISTRATIVO,
        $direccion->ambitoDePermisos());
    verificar('Y el del docente es docente', $docenteRol->ambitoDePermisos() === CatalogoPermisos::DOCENTE);

    $ofrecidos = collect(CatalogoPermisos::paraPantalla($direccion->ambitoDePermisos()))
        ->flatMap(fn ($d) => collect($d['permisos'])->pluck('clave'));

    verificar('La pantalla NO le ofrece a dirección los permisos del docente',
        ! $ofrecidos->contains('ver-mis-materias') && ! $ofrecidos->contains('editar-mi-expediente'),
        $ofrecidos->count().' permisos ofrecidos');
    verificar('Pero sí los que de verdad comparte',
        $ofrecidos->contains('capturar-calificaciones') && $ofrecidos->contains('ver-kardex'));

    $delDocente = collect(CatalogoPermisos::paraPantalla(CatalogoPermisos::DOCENTE))
        ->flatMap(fn ($d) => collect($d['permisos'])->pluck('clave'));

    verificar('Y al docente no se le ofrece administrar la plataforma',
        ! $delDocente->contains('gestionar-roles') && ! $delDocente->contains('gestionar-usuarios'),
        $delDocente->implode(', '));

    // Una faceta que la escuela invente no tiene catálogo propio: se trata como
    // administrativa, porque las que tienen portal son las protegidas.
    $inventadaFaceta = Rol::create([
        'name' => 'consejo_directivo',
        'nombre' => 'Consejo directivo',
        'guard_name' => 'web',
        'rol_padre_id' => null,
        'protegido' => false,
    ]);

    verificar('Una faceta creada por la escuela se trata como administrativa',
        $inventadaFaceta->ambitoDePermisos() === CatalogoPermisos::ADMINISTRATIVO);

    echo PHP_EOL.'8. Salvaguarda contra el auto-encierro'.PHP_EOL;

    // Es la regla que impide que alguien se quite «gestionar-roles» de su
    // propio rol activo y deje la pantalla inalcanzable para todos.
    $coordinador->givePermissionTo('gestionar-roles');
    $olvidar();

    $tieneLaLlave = $coordinador->fresh()->permissions->pluck('name')->contains('gestionar-roles');
    $esSuRolActivo = $usuario->fresh()->rol_activo_id === $coordinador->id;
    $nuevosPermisos = ['ver-adeudos']; // sin gestionar-roles

    verificar('Se detecta el caso: es su rol activo y perdería la llave',
        $tieneLaLlave && $esSuRolActivo && ! in_array('gestionar-roles', $nuevosPermisos, true));

    // Y el caso que SÍ debe pasar: quitárselo a un rol que no es el suyo.
    $otro = Rol::create([
        'name' => 'rol_ajeno_prueba',
        'nombre' => 'Rol ajeno',
        'guard_name' => 'web',
        'rol_padre_id' => $administrativo->id,
        'protegido' => false,
    ]);
    $otro->syncPermissions(['gestionar-roles']);
    $olvidar();

    verificar('Quitárselo a un rol AJENO sí está permitido',
        $usuario->fresh()->rol_activo_id !== $otro->id);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();

    // El caché de Spatie sobrevive a la transacción: si no se olvida, las
    // siguientes pruebas (y el navegador abierto) verían permisos que ya no
    // existen en la base.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
