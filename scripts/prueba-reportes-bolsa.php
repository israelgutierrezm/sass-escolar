<?php

/**
 * La fuente de EGRESADOS Y COLOCACIÓN. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-bolsa.php` desde la raíz.
 *
 * ── El demo tiene CERO colocaciones, y es deliberado ──────────────────────
 * Se sembraron en agosto para mirar las pantallas y se retiraron por decisión
 * del cliente; las ocho tablas del módulo están vacías. Así que esta suite
 * CONSTRUYE su escenario dentro de la transacción y mide POR DIFERENCIA contra
 * una línea base.
 *
 * No es una formalidad: este proyecto ya pagó dos veces la lección en un solo
 * día. `prueba-bolsa-colocaciones` afirmaba «hay dos colocados» dando por hecho
 * que el demo no tenía ninguna, pasaba aislada y se cayó con diez fallas en el
 * barrido en cuanto la escuela tuvo colocaciones sembradas.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. **El denominador sale del CATÁLOGO**: quién cuenta como egresado lo dice
 *     `situaciones_alumno.cuenta_como_egresado`, no una lista de claves.
 *  2. **Quien cambió de trabajo dos veces sigue siendo UN egresado colocado.**
 *     Es lo que impide que el porcentaje pase del 100 %.
 *  3. **`relacionado_con_carrera` tiene TRES estados.** «No se preguntó» no es
 *     «no es de su área», y la columna lo respeta.
 *  4. La empresa y el puesto que se enseñan son los de la ÚLTIMA colocación,
 *     no los alfabéticamente mayores.
 *  5. Lo que el reporte NO puede contar —colocaciones sin matrícula y de quien
 *     no ha egresado— queda fuera, y el grano lo dice.
 */

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Services\Bolsa\IndicadorEmpleabilidad;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

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

function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Bolsa',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_bol_'.random_int(100000, 999999),
        'email' => 'prueba_bol_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $ejecutor = app(Ejecutor::class);
    $indicador = app(IndicadorEmpleabilidad::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. La línea base, antes de sembrar nada'.PHP_EOL;

    $baseColocaciones = DB::table('colocaciones')->whereNull('deleted_at')->count();

    $baseFilas = collect($ejecutor->ejecutar($global, 'empleabilidad-de-egresados', [
        'columnas' => ['matricula', 'colocado', 'colocaciones'],
    ])->filas);

    $baseColocados = $baseFilas->where('colocado', true)->count();

    verificar('Hay egresados en el demo', $baseFilas->isNotEmpty(), $baseFilas->count().' egresados');

    echo '  ·    Línea base: '.$baseColocaciones.' colocaciones, '.$baseColocados.' egresados colocados'.PHP_EOL;

    // El denominador tiene que coincidir con el del servicio del indicador.
    $delIndicador = $indicador->resumen();

    verificar('El denominador es el MISMO que el del indicador',
        $baseFilas->count() === $delIndicador['egresados'],
        'reporte '.$baseFilas->count().' vs indicador '.$delIndicador['egresados']);

    echo PHP_EOL.'2. El denominador sale del CATÁLOGO, no de una lista de claves'.PHP_EOL;

    $deEgreso = SituacionAlumno::query()->deEgresados()->get();

    verificar('Hay situaciones marcadas como egreso',
        $deEgreso->isNotEmpty(), $deEgreso->pluck('nombre')->implode(', '));

    /*
     * Se APAGA la bandera de una de ellas: el reporte tiene que encoger. Es lo
     * que hace que una escuela pueda decidir sola si «Pasante» cuenta, sin
     * tocar código.
     */
    $unaSituacion = $deEgreso->first();
    $cuantasDeEsa = MatriculaOferta::where('situacion_id', $unaSituacion->id)->count();

    verificar('Esa situación tiene matrículas (si no, sería vacua)',
        $cuantasDeEsa > 0, $cuantasDeEsa.' con «'.$unaSituacion->nombre.'»');

    DB::table('situaciones_alumno')->where('id', $unaSituacion->id)->update(['cuenta_como_egresado' => false]);

    $trasApagar = collect($ejecutor->ejecutar($global, 'empleabilidad-de-egresados', [
        'columnas' => ['matricula'],
    ])->filas);

    verificar('Apagar la bandera del catálogo saca a esas matrículas del reporte',
        $trasApagar->count() === $baseFilas->count() - $cuantasDeEsa,
        $baseFilas->count().' → '.$trasApagar->count().' (esperado '.($baseFilas->count() - $cuantasDeEsa).')');

    DB::table('situaciones_alumno')->where('id', $unaSituacion->id)->update(['cuenta_como_egresado' => true]);

    echo PHP_EOL.'3. Dos empleos NO son dos egresados colocados'.PHP_EOL;

    /*
     * ESTA es la regla que impide que el porcentaje pase del 100 %. Se
     * construye: un egresado con DOS colocaciones.
     */
    $empresaA = DB::table('empresas')->insertGetId([
        'razon_social' => 'Zeta, Constructora y Asociados',
        'situacion_id' => DB::table('situaciones_empresa')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $empresaB = DB::table('empresas')->insertGetId([
        'razon_social' => 'Alfa Servicios Digitales',
        'situacion_id' => DB::table('situaciones_empresa')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $egresado = MatriculaOferta::query()
        ->whereIn('situacion_id', SituacionAlumno::query()->deEgresados()->pluck('id'))
        ->whereNotExists(fn ($q) => $q->from('colocaciones')
            ->whereColumn('colocaciones.matricula_oferta_id', 'matricula_oferta.id')
            ->whereNull('colocaciones.deleted_at'))
        ->firstOrFail();

    // El PRIMER empleo, más antiguo y de la empresa que va última en el alfabeto.
    DB::table('colocaciones')->insert([
        'persona_id' => $egresado->persona_id,
        'matricula_oferta_id' => $egresado->id,
        'empresa_id' => $empresaA,
        'puesto' => 'Auxiliar de obra',
        'fecha_ingreso' => now()->subYear()->toDateString(),
        'relacionado_con_carrera' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // El SEGUNDO, más reciente y de la que va primera en el alfabeto: así un
    // `max()` alfabético daría la equivocada.
    DB::table('colocaciones')->insert([
        'persona_id' => $egresado->persona_id,
        'matricula_oferta_id' => $egresado->id,
        'empresa_id' => $empresaB,
        'puesto' => 'Desarrollador',
        'fecha_ingreso' => now()->subMonth()->toDateString(),
        'relacionado_con_carrera' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $conDos = collect($ejecutor->ejecutar($global, 'empleabilidad-de-egresados', [
        'columnas' => ['matricula', 'colocado', 'colocaciones', 'empresa', 'puesto', 'en_su_area'],
    ])->filas);

    $suya = $conDos->where('matricula', $egresado->matricula);

    verificar('Con DOS empleos sale UNA sola fila',
        $suya->count() === 1, $suya->count().' filas');

    verificar('Y dice que tiene dos colocaciones',
        (int) ($suya->first()['colocaciones'] ?? 0) === 2, (string) ($suya->first()['colocaciones'] ?? 'null'));

    verificar('El total de egresados NO creció',
        $conDos->count() === $baseFilas->count(),
        $baseFilas->count().' → '.$conDos->count());

    verificar('Y los colocados subieron en UNO, no en dos',
        $conDos->where('colocado', true)->count() === $baseColocados + 1,
        $baseColocados.' → '.$conDos->where('colocado', true)->count());

    echo PHP_EOL.'4. La empresa que se enseña es la del ÚLTIMO empleo'.PHP_EOL;

    verificar('Enseña la empresa del empleo más reciente, no la mayor del alfabeto',
        ($suya->first()['empresa'] ?? null) === 'Alfa Servicios Digitales',
        (string) ($suya->first()['empresa'] ?? 'null'));

    verificar('Y su puesto',
        ($suya->first()['puesto'] ?? null) === 'Desarrollador',
        (string) ($suya->first()['puesto'] ?? 'null'));

    verificar('Y su marca de área (la reciente dice que SÍ, la vieja que no)',
        ($suya->first()['en_su_area'] ?? null) === 'Sí',
        (string) ($suya->first()['en_su_area'] ?? 'null'));

    echo PHP_EOL.'5. «No se preguntó» NO es «no es de su área»'.PHP_EOL;

    /*
     * `relacionado_con_carrera` es NULLABLE a propósito: con `false` por
     * omisión, una colocación capturada sin preguntar afirmaría algo que nadie
     * dijo. La columna tiene que tener TRES estados.
     */
    $otro = MatriculaOferta::query()
        ->whereIn('situacion_id', SituacionAlumno::query()->deEgresados()->pluck('id'))
        ->whereNotExists(fn ($q) => $q->from('colocaciones')
            ->whereColumn('colocaciones.matricula_oferta_id', 'matricula_oferta.id')
            ->whereNull('colocaciones.deleted_at'))
        ->where('id', '!=', $egresado->id)
        ->firstOrFail();

    DB::table('colocaciones')->insert([
        'persona_id' => $otro->persona_id,
        'matricula_oferta_id' => $otro->id,
        'empresa_id' => $empresaA,
        'puesto' => 'Sin preguntar',
        'fecha_ingreso' => now()->subDays(10)->toDateString(),
        'relacionado_con_carrera' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $conNulo = collect($ejecutor->ejecutar($global, 'empleabilidad-de-egresados', [
        'columnas' => ['matricula', 'colocado', 'en_su_area'],
    ])->filas)->firstWhere('matricula', $otro->matricula);

    verificar('Está colocado', ($conNulo['colocado'] ?? null) === true);

    verificar('Y su marca de área va en BLANCO, no en «No»',
        array_key_exists('en_su_area', $conNulo) && $conNulo['en_su_area'] === null,
        // Sin el coalescente en el DETALLE: '?? ausente' imprimia 'ausente'
        // justo cuando el valor era el null que se queria ver.
        array_key_exists('en_su_area', $conNulo) ? var_export($conNulo['en_su_area'], true) : 'la columna no vino');

    echo PHP_EOL.'6. Lo que el reporte no puede contar, queda fuera'.PHP_EOL;

    /*
     * Una colocación SIN matrícula no se puede atribuir a ningún programa, y
     * una de quien no ha egresado no pertenece al denominador. Las dos se
     * siembran para comprobar que no se cuelan.
     */
    $antesDeLasRaras = collect($ejecutor->ejecutar($global, 'empleabilidad-de-egresados', [
        'columnas' => ['matricula', 'colocado'],
    ])->filas);

    DB::table('colocaciones')->insert([
        'persona_id' => $egresado->persona_id,
        'matricula_oferta_id' => null,
        'empresa_id' => $empresaA,
        'puesto' => 'Sin carrera señalada',
        'fecha_ingreso' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $noEgresado = MatriculaOferta::query()
        ->whereNotIn('situacion_id', SituacionAlumno::query()->deEgresados()->pluck('id'))
        ->firstOrFail();

    DB::table('colocaciones')->insert([
        'persona_id' => $noEgresado->persona_id,
        'matricula_oferta_id' => $noEgresado->id,
        'empresa_id' => $empresaA,
        'puesto' => 'Práctica profesional',
        'fecha_ingreso' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $trasLasRaras = collect($ejecutor->ejecutar($global, 'empleabilidad-de-egresados', [
        'columnas' => ['matricula', 'colocado'],
    ])->filas);

    verificar('Ni la colocación sin matrícula ni la de quien no egresó cambian el reporte',
        $trasLasRaras->count() === $antesDeLasRaras->count()
        && $trasLasRaras->where('colocado', true)->count() === $antesDeLasRaras->where('colocado', true)->count(),
        $antesDeLasRaras->count().'/'.$antesDeLasRaras->where('colocado', true)->count()
        .' → '.$trasLasRaras->count().'/'.$trasLasRaras->where('colocado', true)->count());

    verificar('Y quien no egresó no aparece aunque tenga empleo',
        ! $trasLasRaras->contains('matricula', $noEgresado->matricula), $noEgresado->matricula);

    verificar('El grano lo DICE, en vez de dejar el descuadre sin explicar',
        str_contains(app(App\Reportes\RegistroReportes::class)->fuente('egresados-colocacion')->grano(), 'sin matrícula'));

    echo PHP_EOL.'7. La cola de trabajo trae a los que faltan'.PHP_EOL;

    $sinColocar = collect($ejecutor->ejecutar($global, 'egresados-sin-colocar', [
        'columnas' => ['matricula', 'egresado'],
    ])->filas);

    verificar('Los colocados NO salen en la cola',
        ! $sinColocar->contains('matricula', $egresado->matricula)
        && ! $sinColocar->contains('matricula', $otro->matricula));

    verificar('Y la cola más los colocados son todos los egresados',
        $sinColocar->count() + $trasLasRaras->where('colocado', true)->count() === $trasLasRaras->count(),
        $sinColocar->count().' + '.$trasLasRaras->where('colocado', true)->count().' = '.$trasLasRaras->count());

    echo PHP_EOL.'8. El módulo apagable cierra la puerta'.PHP_EOL;

    $fuente = app(App\Reportes\RegistroReportes::class)->fuente('egresados-colocacion');

    verificar('La fuente declara el módulo `bolsa_trabajo`',
        $fuente->modulo() === 'bolsa_trabajo', (string) $fuente->modulo());

    verificar('Y comprobado que ese módulo SÍ está encendido en el demo',
        app(App\Services\Plataforma\ModulosDeLaEscuela::class)->activo('bolsa_trabajo'));

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
