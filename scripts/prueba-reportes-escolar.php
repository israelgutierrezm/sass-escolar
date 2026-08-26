<?php

/**
 * La fuente de GRUPOS y sus dos reportes. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-escolar.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **La ocupación es la MISMA que la de la pantalla y la del panel.** Sale de
 *     `Grupo::scopeConAlumnos()`, que ya decide qué cuenta como alumno de un
 *     grupo. Un `count` propio daría una segunda ocupación del mismo grupo.
 *  2. **La asignación RETIRADA no cuenta como titular.**
 *     `docente_asignatura_grupo` tiene `deleted_at` y la relación del modelo NO
 *     lo filtra, así que quitarle la materia a un docente dejaría la materia
 *     contada como cubierta y la cola de trabajo diría cero donde hay una.
 *  3. El grano no se multiplica: un grupo con seis materias y treinta alumnos
 *     sale UNA vez.
 *  4. La ocupación sin cupo va en BLANCO, no en cero: «no está capturado» y
 *     «está vacío» son cosas distintas.
 *  5. El recorte por campus acota, y por la COLUMNA PROPIA del grupo.
 *  6. `porRelacion` ya no deja pasar lo que no completa la cadena a menos que la
 *     fuente lo pida — se comprueba con el objeto `Recorte` directamente.
 */

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Grupo;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\Recorte;
use App\Reportes\RegistroReportes;
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
        'primer_apellido' => 'Escolar',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_esc_'.random_int(100000, 999999),
        'email' => 'prueba_esc_'.random_int(100000, 999999).'@ejemplo.mx',
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
    $registro = app(RegistroReportes::class);
    $ejecutor = app(Ejecutor::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. La ocupación es la del SCOPE, no una cuenta propia'.PHP_EOL;

    $delScope = Grupo::query()->conAlumnos()->get()->keyBy('id');

    $filas = collect($ejecutor->ejecutar($global, 'ocupacion-de-grupos', [
        'columnas' => ['clave', 'alumnos', 'cupo', 'ocupacion'],
    ])->filas);

    verificar('El reporte trae grupos', $filas->isNotEmpty(), $filas->count().' grupos');

    $descuadran = [];

    foreach ($filas as $f) {
        $g = $delScope->first(fn (Grupo $x) => $x->clave === $f['clave']);

        if ($g === null) {
            continue;
        }

        if ((int) $f['alumnos'] !== (int) ($g->alumnos_count ?? 0)) {
            $descuadran[] = $f['clave'].': reporte '.$f['alumnos'].' vs scope '.($g->alumnos_count ?? 0);
        }
    }

    verificar('Cada conteo de alumnos es el del scope del modelo',
        $descuadran === [], $descuadran === [] ? 'los '.$filas->count().' cuadran' : implode(' | ', $descuadran));

    echo PHP_EOL.'2. Una asignación RETIRADA no cuenta como titular'.PHP_EOL;

    /*
     * ESTE es el defecto que la fuente vigila y que ninguna relación del modelo
     * detiene: `docente_asignatura_grupo` tiene `deleted_at` y
     * `AsignaturaGrupo::docentes()` NO lo filtra. Se construye el caso:
     * una materia con titular, se le retira, y la cola tiene que crecer.
     */
    /*
     * Una materia SIN ninguna asignación: la llave primaria de
     * `docente_asignatura_grupo` es (asignatura_grupo_id, persona_id) y no tiene
     * `id`, así que una fila dada de baja SIGUE ocupando el par —reasignar al
     * mismo docente choca con su propio rastro—. Tomar la primera materia se
     * llevaba justo la única del demo que ya tiene docente.
     */
    $materia = AsignaturaGrupo::query()
        ->whereHas('grupo')
        ->whereNotExists(fn ($q) => $q->from('docente_asignatura_grupo as d')
            ->whereColumn('d.asignatura_grupo_id', 'asignatura_grupo.id'))
        ->firstOrFail();

    $grupo = $materia->grupo;

    $docente = DB::table('docentes')->value('persona_id');

    verificar('Hay un docente en el catálogo para el escenario', $docente !== null, (string) $docente);

    $antes = collect($ejecutor->ejecutar($global, 'ocupacion-de-grupos', [
        'columnas' => ['clave', 'sin_titular'],
    ])->filas)->firstWhere('clave', $grupo->clave);

    // Se le pone titular: la cola tiene que BAJAR en uno.
    DB::table('docente_asignatura_grupo')->insert([
        'asignatura_grupo_id' => $materia->id,
        'persona_id' => $docente,
        'tipo' => 'titular',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $conTitular = collect($ejecutor->ejecutar($global, 'ocupacion-de-grupos', [
        'columnas' => ['clave', 'sin_titular'],
    ])->filas)->firstWhere('clave', $grupo->clave);

    verificar('Asignar un titular baja la cola en uno',
        (int) $conTitular['sin_titular'] === (int) $antes['sin_titular'] - 1,
        $antes['sin_titular'].' -> '.$conTitular['sin_titular']);

    // Y ahora se RETIRA con baja lógica, que es lo que hace la pantalla.
    DB::table('docente_asignatura_grupo')
        ->where('asignatura_grupo_id', $materia->id)
        ->where('persona_id', $docente)
        ->update(['deleted_at' => now()]);

    $retirado = collect($ejecutor->ejecutar($global, 'ocupacion-de-grupos', [
        'columnas' => ['clave', 'sin_titular'],
    ])->filas)->firstWhere('clave', $grupo->clave);

    verificar('Retirarlo vuelve a subir la cola: la asignación dada de baja NO cuenta',
        (int) $retirado['sin_titular'] === (int) $antes['sin_titular'],
        $conTitular['sin_titular'].' -> '.$retirado['sin_titular'].' (esperado '.$antes['sin_titular'].')');

    /*
     * Y la RELACIÓN da el mismo número que la subconsulta.
     *
     * Esto decía lo contrario —«la relación SÍ devuelve la retirada, por eso no
     * se usa»— y era cierto hasta que la asignación pasó a retirarse con baja
     * lógica: entonces `AsignaturaGrupo::docentes()` ganó su `wherePivotNull`,
     * que además es lo que impide que un docente retirado siga entrando a su
     * aula. El defecto ahora sería que las dos cifras se separaran.
     */
    $porLaRelacion = AsignaturaGrupo::with('docentes')->find($materia->id)->docentes->count();

    verificar('La relación del modelo tampoco cuenta la retirada',
        $porLaRelacion === 0, $porLaRelacion.' docentes según la relación');

    echo PHP_EOL.'3. El grano no se multiplica'.PHP_EOL;

    $conMaterias = collect($ejecutor->ejecutar($global, 'ocupacion-de-grupos', [
        'columnas' => ['clave', 'materias', 'alumnos'],
    ])->filas);

    $repetidos = $conMaterias->groupBy('clave')->filter(fn ($g) => $g->count() > 1);

    verificar('Ningún grupo sale más de una vez',
        $repetidos->isEmpty(), $repetidos->isEmpty() ? 'sin repetidos' : $repetidos->keys()->implode(', '));

    $conVarias = $conMaterias->first(fn (array $f) => (int) $f['materias'] > 1);

    verificar('Y hay un grupo con varias materias (si no, la prueba sería vacua)',
        $conVarias !== null, $conVarias === null ? 'ninguno' : $conVarias['clave'].' con '.$conVarias['materias']);

    echo PHP_EOL.'4. Sin cupo, la ocupación va en BLANCO'.PHP_EOL;

    $sinCupo = Grupo::query()->first();
    $cupoOriginal = $sinCupo->cupo;
    DB::table('grupos')->where('id', $sinCupo->id)->update(['cupo' => 0]);

    $conCero = collect($ejecutor->ejecutar($global, 'ocupacion-de-grupos', [
        'columnas' => ['clave', 'cupo', 'ocupacion'],
    ])->filas)->firstWhere('clave', $sinCupo->clave);

    /*
     * Con `array_key_exists` y NO con `?? 'x'`.
     *
     * `($f['ocupacion'] ?? 'x') === null` es FALSO pase lo que pase: el
     * coalescente reemplaza justamente el null que se quiere ver. Es la misma
     * comprobación vacua que ya se corrigió en `prueba-bolsa-colocaciones`, y
     * volvió a escribirse aquí sola.
     */
    verificar('El grupo sin cupo sale en el reporte',
        $conCero !== null && array_key_exists('ocupacion', $conCero));

    verificar('Un grupo sin cupo NO dice 0 % de ocupación',
        $conCero !== null && $conCero['ocupacion'] === null,
        $conCero === null ? 'no encontré la fila' : var_export($conCero['ocupacion'], true));

    DB::table('grupos')->where('id', $sinCupo->id)->update(['cupo' => $cupoOriginal]);

    echo PHP_EOL.'5. El recorte acota por la COLUMNA PROPIA del grupo'.PHP_EOL;

    $campusId = Grupo::query()->whereNotNull('campus_id')->value('campus_id');
    $acotado = usuarioConRol('director_general', $campusId);
    auth()->login($acotado);

    $suyos = collect($ejecutor->ejecutar($acotado, 'ocupacion-de-grupos', [
        'columnas' => ['clave', 'campus'],
    ])->filas);

    auth()->login($global);
    $todos = collect($ejecutor->ejecutar($global, 'ocupacion-de-grupos', ['columnas' => ['clave', 'campus']])->filas);

    $nombreCampus = Campus::find($campusId)?->nombre;

    verificar('El acotado ve sólo los grupos de su campus',
        $suyos->every(fn (array $f) => $f['campus'] === $nombreCampus || $f['campus'] === null),
        'ajenos: '.$suyos->reject(fn (array $f) => $f['campus'] === $nombreCampus || $f['campus'] === null)->count());

    verificar('Y el global ve al menos tantos como el acotado',
        $todos->count() >= $suyos->count(), $suyos->count().' de '.$todos->count());

    echo PHP_EOL.'6. `porRelacion` ya no falla ABIERTO'.PHP_EOL;

    /*
     * El hallazgo que destapó la revisión: `porRelacion` llevaba siempre
     * `orWhereDoesntHave`, así que una fila que no completa la cadena pasaba
     * para TODOS los campus. Y no hacía falta una referencia rota: bastaba con
     * dar de baja un eslabón intermedio, que es una operación normal.
     *
     * Se comprueba sobre el objeto, no sobre una fuente: hoy ninguna lo usa, y
     * la regla tiene que estar fija ANTES de que la primera lo haga.
     */
    $estricto = Recorte::porRelacion('campus');
    $tolerante = Recorte::porRelacion('campus', incluirSinAsignar: true);

    $sqlEstricto = $estricto->aplicar(Grupo::query()->newQuery(), [$campusId])->toSql();
    $sqlTolerante = $tolerante->aplicar(Grupo::query()->newQuery(), [$campusId])->toSql();

    verificar('Por omisión NO lleva la rama que deja pasar lo incompleto',
        ! str_contains($sqlEstricto, 'not exists'),
        str_contains($sqlEstricto, 'not exists') ? 'la lleva' : 'cerrado');

    verificar('Y pidiéndolo explícitamente SÍ la lleva',
        str_contains($sqlTolerante, 'not exists'),
        str_contains($sqlTolerante, 'not exists') ? 'la lleva' : 'no la lleva');

    echo PHP_EOL.'7. Los dos reportes contestan cosas distintas'.PHP_EOL;

    $cola = collect($ejecutor->ejecutar($global, 'materias-sin-titular', [
        'columnas' => ['clave', 'sin_titular'],
    ])->filas);

    verificar('«Materias sin titular» sólo trae grupos con pendientes',
        $cola->every(fn (array $f) => (int) $f['sin_titular'] > 0),
        'con cero: '.$cola->reject(fn (array $f) => (int) $f['sin_titular'] > 0)->count());

    verificar('Y trae menos grupos que el listado completo',
        $cola->count() <= $todos->count(), $cola->count().' de '.$todos->count());

    // El filtro fijo no se afloja desde la petición.
    $conTrampa = collect($ejecutor->ejecutar($global, 'materias-sin-titular', [
        'columnas' => ['clave', 'sin_titular'],
        'filtros' => ['solo_sin_titular' => false],
    ])->filas);

    verificar('El filtro fijo gana sobre la petición',
        $conTrampa->every(fn (array $f) => (int) $f['sin_titular'] > 0),
        'con cero: '.$conTrampa->reject(fn (array $f) => (int) $f['sin_titular'] > 0)->count());

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
