<?php

/**
 * La asignación docente ahora se RETIRA en vez de borrarse. Con rollback.
 *
 * Se corre con `php scripts/prueba-asignacion-docente.php` desde la raíz.
 *
 * ── Qué cambió, y qué se vigila ───────────────────────────────────────────
 * `docente_asignatura_grupo` nació con PK compuesta `(asignatura_grupo_id,
 * persona_id)` y sin `id`. Eso cerraba DOS puertas a la vez:
 *
 *  - **Retirar conservando el rastro era imposible.** La tabla declara
 *    `auditoria()` desde el principio, pero con esa llave la fila dada de baja
 *    seguía ocupando el par: reasignar al mismo docente esa misma materia
 *    reventaba con `Duplicate entry` PARA SIEMPRE. Por eso la pantalla usaba
 *    `detach()`, que BORRA — y de quien dio una materia medio semestre no
 *    quedaba ni rastro, mientras el acta que firmó sigue nombrándolo.
 *  - **No se podía recorrer por lotes**, así que un reporte con grano de
 *    ASIGNACIÓN era imposible.
 *
 * Lo que se comprueba:
 *  1. El ciclo completo: asignar → retirar → REASIGNAR la misma materia.
 *  2. Lo retirado desaparece de las relaciones VIGENTES y sigue en el histórico.
 *  3. **Y desaparece de la AUTORIZACIÓN**, que es lo que hace grave el cambio:
 *     por esa relación pasan cuatro caminos que deciden si un docente entra a
 *     su aula, captura calificaciones y abre su clase en línea.
 *  4. El único admite muchas retiradas y UNA SOLA viva.
 */

use App\Models\ControlEscolar\AsignacionDocente;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

DB::beginTransaction();

try {
    echo PHP_EOL.'1. La tabla tiene llave propia'.PHP_EOL;

    verificar('`docente_asignatura_grupo` tiene columna `id`',
        Schema::hasColumn('docente_asignatura_grupo', 'id'));

    $pk = collect(DB::select('SHOW KEYS FROM docente_asignatura_grupo WHERE Key_name = ?', ['PRIMARY']))
        ->pluck('Column_name');

    verificar('Y la PK es sólo esa columna', $pk->all() === ['id'], $pk->implode(', '));

    $unico = collect(DB::select('SHOW KEYS FROM docente_asignatura_grupo WHERE Key_name = ?', ['docente_asignatura_grupo_una_vigente']))
        ->pluck('Column_name');

    /*
     * El único va sobre `vigente`, una columna VIRTUAL derivada de
     * `deleted_at` — no sobre `deleted_at` misma. Con la fecha, dos retiros del
     * mismo par dentro del MISMO SEGUNDO chocan: `timestamp` tiene precisión de
     * segundo y esta misma suite lo reproduce sin esfuerzo. Con la columna
     * generada el único dice lo que se quiere decir —a lo más una VIGENTE— y no
     * hay segunda verdad que mantener.
     */
    verificar('El único va sobre la columna generada `vigente`',
        $unico->contains('vigente'), $unico->implode(', '));

    verificar('Y `vigente` se DERIVA, no se escribe',
        collect(DB::select("SHOW COLUMNS FROM docente_asignatura_grupo WHERE Field='vigente'"))
            ->first()?->Extra !== null
        && str_contains(collect(DB::select("SHOW COLUMNS FROM docente_asignatura_grupo WHERE Field='vigente'"))->first()->Extra, 'GENERATED'),
        collect(DB::select("SHOW COLUMNS FROM docente_asignatura_grupo WHERE Field='vigente'"))->first()?->Extra ?? 'sin Extra');

    echo PHP_EOL.'2. Asignar → retirar → REASIGNAR la misma materia'.PHP_EOL;

    $materia = AsignaturaGrupo::query()
        ->whereHas('grupo')
        ->whereNotExists(fn ($q) => $q->from('docente_asignatura_grupo as d')
            ->whereColumn('d.asignatura_grupo_id', 'asignatura_grupo.id'))
        ->firstOrFail();

    $docente = Docente::query()->firstOrFail();

    $materia->docentes()->syncWithoutDetaching([$docente->persona_id => ['tipo' => 'titular']]);
    $materia->unsetRelation('docentes');

    verificar('Asignado: la materia tiene un docente vigente',
        $materia->docentes()->count() === 1, (string) $materia->docentes()->count());

    // Se retira EXACTAMENTE como lo hace la pantalla.
    $materia->docentes()->updateExistingPivot($docente->persona_id, ['deleted_at' => now()]);
    $materia->unsetRelation('docentes');

    verificar('Retirado: ya no tiene docentes vigentes',
        $materia->docentes()->count() === 0, (string) $materia->docentes()->count());

    verificar('Pero el rastro SIGUE en el histórico',
        $materia->docentesHistoricos()->count() === 1, (string) $materia->docentesHistoricos()->count());

    /*
     * LA comprobación que justifica la migración: reasignar la misma materia al
     * mismo docente. Con la llave compuesta vieja esto reventaba con
     * `Duplicate entry` y no había forma de arreglarlo sin borrar la historia.
     */
    $reasignado = true;
    $error = null;

    try {
        $materia->docentes()->syncWithoutDetaching([$docente->persona_id => ['tipo' => 'titular']]);
    } catch (\Throwable $e) {
        $reasignado = false;
        $error = class_basename($e).': '.mb_substr($e->getMessage(), 0, 60);
    }

    $materia->unsetRelation('docentes');

    verificar('Se le puede volver a asignar LA MISMA materia',
        $reasignado, $error ?? 'sin error');

    verificar('Y queda UNA vigente con DOS renglones de historia',
        $materia->docentes()->count() === 1 && $materia->docentesHistoricos()->count() === 2,
        $materia->docentes()->count().' vigente(s), '.$materia->docentesHistoricos()->count().' histórico(s)');

    echo PHP_EOL.'3. Lo retirado NO autoriza'.PHP_EOL;

    /*
     * Es lo más delicado del cambio. Por `AsignaturaGrupo::docentes()` pasan
     * cuatro caminos de autorización —`AutorizaMateriaPropia`,
     * `DocenciaController`, `EntrarAClaseController` y `SalaDeMateria`—, así
     * que si la relación no filtrara, a quien se le quitó la materia seguiría
     * entrando a su aula y capturando sus calificaciones.
     *
     * Se comprueba con la MISMA consulta que usan esos cuatro, no con una
     * parecida.
     */
    $usuario = Usuario::query()->where('persona_id', $docente->persona_id)->first();

    $imparteAntes = $materia->docentes()->where('docentes.persona_id', $docente->persona_id)->exists();

    verificar('Estando asignado, la comprobación de autorización lo reconoce', $imparteAntes);

    /*
     * Se retira POR LA RELACIÓN, que es lo que hace la pantalla.
     *
     * La primera versión de esta prueba usaba un `update` masivo sobre el par y
     * reventaba con `Duplicate entry`: tocaba también las YA retiradas y les
     * ponía a todas el mismo `deleted_at`, que es justo lo que el único
     * prohíbe. La app no puede caer en eso —`updateExistingPivot` hereda el
     * `where deleted_at is null` de la relación, comprobado leyendo su SQL— y
     * la prueba tenía que ejercitar ESE camino, no uno parecido.
     */
    $materia->docentes()->updateExistingPivot($docente->persona_id, ['deleted_at' => now()]);

    $materia->unsetRelation('docentes');

    $imparteDespues = $materia->docentes()->where('docentes.persona_id', $docente->persona_id)->exists();

    verificar('Retirado, la MISMA comprobación ya NO lo reconoce',
        ! $imparteDespues, $imparteDespues ? 'sigue autorizado' : 'pierde el acceso');

    verificar('Aunque la fila siga existiendo en la tabla',
        DB::table('docente_asignatura_grupo')
            ->where('asignatura_grupo_id', $materia->id)
            ->where('persona_id', $docente->persona_id)->exists());

    echo PHP_EOL.'4. Un solo VIGENTE por par, pero muchas retiradas'.PHP_EOL;

    // Ya hay dos retiradas de ese par. Se agrega una tercera asignación viva.
    $materia->docentes()->syncWithoutDetaching([$docente->persona_id => ['tipo' => 'adjunto']]);
    $materia->unsetRelation('docentes');

    verificar('Cabe otra viva encima de las retiradas',
        $materia->docentes()->count() === 1, (string) $materia->docentes()->count());

    // Y DOS vivas del mismo par no caben: es lo que el único sigue impidiendo.
    $dosVivas = false;

    try {
        DB::table('docente_asignatura_grupo')->insert([
            'asignatura_grupo_id' => $materia->id,
            'persona_id' => $docente->persona_id,
            'tipo' => 'adjunto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dosVivas = true;
    } catch (\Throwable $e) {
        // Lo esperado.
    }

    verificar('Pero DOS vigentes del mismo par siguen sin caber',
        ! $dosVivas, $dosVivas ? 'entraron dos' : 'el único lo impide');

    echo PHP_EOL.'5. La carga académica sale del grano de ASIGNACIÓN'.PHP_EOL;

    $ejecutor = app(App\Reportes\Ejecutor::class);

    $global = Usuario::query()->whereNotNull('rol_activo_id')->get()
        ->first(fn (Usuario $u) => $u->can('ver-reportes') && $u->can('ver-docentes'));

    verificar('Hay un usuario que puede ejecutar el reporte', $global !== null);

    auth()->login($global);

    $ciclo = $materia->grupo?->ciclo_id;

    $filas = collect($ejecutor->ejecutar($global, 'carga-academica', [
        'columnas' => ['docente', 'tipo', 'materia', 'grupo', 'inscritos'],
        'filtros' => ['ciclo_id' => [(string) $ciclo]],
    ])->filas);

    verificar('El reporte trae asignaciones', $filas->isNotEmpty(), $filas->count().' asignaciones');

    // La retirada NO sale, la viva sí: es el mismo criterio que la relación.
    $suyas = $filas->where('docente', $docente->persona?->nombreCompleto());

    verificar('Sale UNA sola vez por esa materia, no una por renglón histórico',
        $suyas->where('materia', $materia->planMateria?->asignatura?->nombre)->count() === 1,
        $suyas->count().' filas suyas en total');

    echo PHP_EOL.'6. Y la PANTALLA retira, no borra'.PHP_EOL;

    /*
     * Las comprobaciones de arriba llaman a `updateExistingPivot` directamente,
     * o sea que prueban el MECANISMO y no el camino. Se vio mutando: devolver
     * el `detach()` al controlador no tumbaba ninguna, porque ninguna pasaba por
     * él. Y el `detach()` es precisamente lo que borra la historia.
     *
     * Es la lección que este proyecto ya tenía escrita —«prueba-listados es la
     * primera que invoca a los CONTROLADORES en vez de reimplementar la
     * consulta»— y que aquí volvió a hacer falta.
     */
    $otraMateria = AsignaturaGrupo::query()
        ->whereHas('grupo')
        ->whereNotExists(fn ($q) => $q->from('docente_asignatura_grupo as d')
            ->whereColumn('d.asignatura_grupo_id', 'asignatura_grupo.id'))
        ->firstOrFail();

    $otroDocente = Docente::query()->firstOrFail();

    $otraMateria->docentes()->syncWithoutDetaching([$otroDocente->persona_id => ['tipo' => 'titular']]);

    $antesDelBoton = DB::table('docente_asignatura_grupo')
        ->where('asignatura_grupo_id', $otraMateria->id)
        ->where('persona_id', $otroDocente->persona_id)
        ->count();

    verificar('Antes de pulsar «retirar» hay una fila', $antesDelBoton === 1, (string) $antesDelBoton);

    // El MISMO método que ejecuta el botón de la pantalla.
    app(App\Http\Controllers\AsignaturaGrupoController::class)
        ->quitarDocente($otraMateria->grupo, $otraMateria, $otroDocente->persona_id);

    $trasElBoton = DB::table('docente_asignatura_grupo')
        ->where('asignatura_grupo_id', $otraMateria->id)
        ->where('persona_id', $otroDocente->persona_id)
        ->count();

    verificar('Tras pulsarlo la fila SIGUE ahí: se retiró, no se borró',
        $trasElBoton === 1, $antesDelBoton.' → '.$trasElBoton.' filas');

    $marcada = DB::table('docente_asignatura_grupo')
        ->where('asignatura_grupo_id', $otraMateria->id)
        ->where('persona_id', $otroDocente->persona_id)
        ->first();

    verificar('Y está marcada con su fecha de retiro',
        $marcada?->deleted_at !== null, (string) ($marcada?->deleted_at ?? 'sin marcar'));

    $otraMateria->unsetRelation('docentes');

    verificar('La materia ya no la cuenta como vigente',
        $otraMateria->docentes()->count() === 0, (string) $otraMateria->docentes()->count());

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

} finally {
    DB::rollBack();
}
