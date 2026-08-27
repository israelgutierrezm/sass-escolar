<?php

/**
 * La fuente de MOVILIDAD SALIENTE. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-movilidad.php` desde la raíz.
 *
 * ── El módulo está VACÍO en el demo ───────────────────────────────────────
 * Cero convenios, cero convocatorias, cero postulaciones, cero estancias y cero
 * revalidaciones. La suite construye el escenario completo dentro de la
 * transacción.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El titular DUAL obliga a elegir rama, y la fuente elige SALIENTES.**
 *     Un entrante es una persona externa sin matrícula nuestra y sin campus por
 *     ningún camino. Mezclarlos habría forzado `sinCampus`, que lanza 403 a
 *     todo rol acotado a un plantel: un coordinador se quedaría sin el área
 *     entera para poder ver a gente que ni siquiera es suya.
 *  2. **El cupo lo dice la BANDERA `acepta`, no la clave de la etapa.** Quien
 *     está en curso o concluyó sigue ocupando su lugar; contando sólo la etapa
 *     llamada «aceptado», el cupo se liberaría en cuanto alguien avanzara y la
 *     escuela mandaría a dos personas a la misma plaza.
 *  3. **El grano no se multiplica**: quien revalidó ocho materias sigue siendo
 *     UNA postulación.
 *  4. Una revalidación REVOCADA no cuenta, aunque se conserve.
 *  5. El recorte por campus llega por la oferta de la matrícula.
 */

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Movilidad\EtapaMovilidad;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
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
        'primer_apellido' => 'Movilidad',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_mov_'.random_int(100000, 999999),
        'email' => 'prueba_mov_'.random_int(100000, 999999).'@ejemplo.mx',
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
    $registro = app(RegistroReportes::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. Se siembra el módulo, que está vacío'.PHP_EOL;

    verificar('El demo no tiene postulaciones de movilidad',
        DB::table('postulaciones_movilidad')->count() === 0);

    $institucion = DB::table('instituciones_aliadas')->insertGetId([
        'nombre' => 'Universidad de Prueba',
        'pais_id' => 1,
        'tipo_id' => DB::table('tipos_institucion')->value('id'),
        'activa' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $convenio = DB::table('convenios')->insertGetId([
        'institucion_aliada_id' => $institucion,
        'tipo_convenio_id' => DB::table('tipos_convenio')->value('id'),
        'folio' => 'CONV-PRUEBA',
        'vigente_desde' => now()->subYear()->toDateString(),
        'situacion_id' => DB::table('situaciones_convenio')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $convocatoria = DB::table('convocatorias_movilidad')->insertGetId([
        'convenio_id' => $convenio,
        'titulo' => 'Intercambio de prueba',
        'direccion' => 'saliente',
        'periodo' => '2026-2',
        'cupo' => 5,
        'fecha_apertura' => now()->subMonths(3)->toDateString(),
        'fecha_cierre' => now()->addMonth()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    verificar('Se sembró la convocatoria', $convocatoria > 0);

    echo PHP_EOL.'2. El cupo lo dice la BANDERA, no la clave de la etapa'.PHP_EOL;

    /*
     * Se postula a tres alumnos en tres etapas distintas: una que NO acepta, y
     * dos que sí —«aceptado» y «concluido»—. La segunda es la que importa: con
     * `clave = 'aceptado'` el que ya concluyó dejaría de ocupar su lugar y la
     * escuela mandaría a dos personas a la misma plaza.
     */
    $postulado = EtapaMovilidad::query()->where('clave', 'postulado')->firstOrFail();
    $aceptado = EtapaMovilidad::query()->where('clave', 'aceptado')->firstOrFail();
    $concluido = EtapaMovilidad::query()->where('clave', 'concluido')->firstOrFail();

    verificar('«Postulado» NO ocupa lugar y «concluido» SÍ (si no, sería vacua)',
        ! $postulado->acepta && $concluido->acepta,
        'postulado='.var_export((bool) $postulado->acepta, true).' concluido='.var_export((bool) $concluido->acepta, true));

    /*
     * Los tres, de DOS campus distintos a propósito.
     *
     * `limit(3)` a secas devolvía tres matrículas que en el demo caen todas en
     * el mismo plantel, y entonces la comprobación del recorte —«el acotado ve
     * menos que el global»— pasaba o fallaba según qué tres tocaran. Una prueba
     * que depende de qué devuelva un `limit` sin orden no prueba el recorte:
     * prueba la suerte.
     */
    $primerCampus = MatriculaOferta::query()->whereHas('oferta')
        ->with('oferta:id,campus_id')->first()?->oferta?->campus_id;

    $deOtroCampus = MatriculaOferta::query()
        ->whereHas('oferta', fn ($o) => $o->where('campus_id', '!=', $primerCampus))
        ->first();

    if ($deOtroCampus === null) {
        throw new RuntimeException(
            'No hay matrículas en dos campus distintos: sin eso el recorte de movilidad no se puede comprobar.',
        );
    }

    $alumnos = MatriculaOferta::query()
        ->whereHas('oferta', fn ($o) => $o->where('campus_id', $primerCampus))
        ->limit(2)->get()
        ->push($deOtroCampus)
        ->values();

    verificar('Hay tres matrículas para el escenario', $alumnos->count() === 3, (string) $alumnos->count());

    $ids = [];

    foreach ([[$alumnos[0], $postulado], [$alumnos[1], $aceptado], [$alumnos[2], $concluido]] as $i => [$m, $etapa]) {
        $ids[$etapa->clave] = DB::table('postulaciones_movilidad')->insertGetId([
            'convocatoria_id' => $convocatoria,
            'matricula_oferta_id' => $m->id,
            'etapa_id' => $etapa->id,
            'promedio_acreditado' => 8.5 + $i / 10,
            'fecha_postulacion' => now()->subDays(30 - $i)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $todas = collect($ejecutor->ejecutar($global, 'movilidad-del-periodo', [
        'columnas' => ['matricula', 'etapa', 'acepta', 'institucion'],
    ])->filas);

    verificar('Las tres postulaciones salen', $todas->count() === 3, $todas->count().' postulaciones');

    verificar('Y trae la institución destino',
        $todas->every(fn (array $f) => $f['institucion'] === 'Universidad de Prueba'));

    $ocupan = $todas->where('acepta', true);

    verificar('Ocupan lugar DOS: el aceptado y el que ya concluyó',
        $ocupan->count() === 2, $ocupan->count().' ocupan de 3');

    $soloAceptados = collect($ejecutor->ejecutar($global, 'movilidad-del-periodo', [
        'columnas' => ['matricula', 'etapa'],
        'filtros' => ['solo_aceptados' => '1'],
    ])->filas);

    verificar('El filtro por bandera trae los mismos dos',
        $soloAceptados->count() === 2, $soloAceptados->count());

    verificar('Y el que sólo se postuló NO está entre ellos',
        ! $soloAceptados->contains('matricula', $alumnos[0]->matricula));

    echo PHP_EOL.'3. Las revalidaciones se CUENTAN, no se despliegan'.PHP_EOL;

    /*
     * Quien revalidó ocho materias sigue siendo UNA postulación. Con un join en
     * crudo saldría ocho veces y «tres postulaciones» pasaría a ser diez.
     */
    $estancia = DB::table('estancias')->insertGetId([
        'postulacion_id' => $ids['concluido'],
        'fecha_inicio' => now()->subMonths(6)->toDateString(),
        'fecha_fin' => now()->subMonth()->toDateString(),
        'concluida_en' => now()->subMonth()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $materias = DB::table('plan_materias')
        ->where('plan_id', $alumnos[2]->oferta?->plan_id)
        ->whereNull('deleted_at')
        ->limit(3)->pluck('id');

    verificar('Hay materias del plan para revalidar', $materias->count() >= 2, (string) $materias->count());

    $dictamen = DB::table('dictamenes_revalidacion')->value('id');
    $ciclo = DB::table('ciclos')->whereNull('deleted_at')->value('id');

    foreach ($materias as $i => $planMateria) {
        DB::table('revalidaciones')->insert([
            'estancia_id' => $estancia,
            'materia_externa' => 'Materia externa '.$i,
            'calificacion_externa' => 'B+',
            'plan_materia_id' => $planMateria,
            'calificacion_equivalente' => 8.6,
            'dictamen_id' => $dictamen,
            'ciclo_id' => $ciclo,
            'dictaminada_en' => now()->subDays(10)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $conRevalidaciones = collect($ejecutor->ejecutar($global, 'movilidad-del-periodo', [
        'columnas' => ['matricula', 'revalidaciones'],
    ])->filas);

    verificar('Sigue habiendo TRES postulaciones, no una por revalidación',
        $conRevalidaciones->count() === 3, $conRevalidaciones->count().' filas');

    $suya = $conRevalidaciones->firstWhere('matricula', $alumnos[2]->matricula);

    verificar('Y la del que volvió dice cuántas se le revalidaron',
        (int) ($suya['revalidaciones'] ?? 0) === $materias->count(),
        ($suya['revalidaciones'] ?? 'null').' de '.$materias->count());

    echo PHP_EOL.'4. Una revalidación REVOCADA no cuenta'.PHP_EOL;

    /*
     * Revocar da de BAJA LÓGICA el renglón: es historia escolar y se conserva
     * con su auditoría, igual que los renglones de un acta corregida. Pero deja
     * de contar como revalidada, porque la materia vuelve a estar pendiente.
     */
    DB::table('revalidaciones')
        ->where('estancia_id', $estancia)
        ->where('plan_materia_id', $materias[0])
        ->update(['deleted_at' => now()]);

    $trasRevocar = collect($ejecutor->ejecutar($global, 'movilidad-del-periodo', [
        'columnas' => ['matricula', 'revalidaciones'],
    ])->filas)->firstWhere('matricula', $alumnos[2]->matricula);

    verificar('Revocar una baja el conteo en uno',
        (int) ($trasRevocar['revalidaciones'] ?? 0) === $materias->count() - 1,
        ($suya['revalidaciones'] ?? '?').' → '.($trasRevocar['revalidaciones'] ?? '?'));

    verificar('Pero la fila SIGUE en la tabla: es historia escolar',
        DB::table('revalidaciones')->where('estancia_id', $estancia)->count() === $materias->count(),
        (string) DB::table('revalidaciones')->where('estancia_id', $estancia)->count());

    echo PHP_EOL.'5. «Estancias concluidas» sólo trae a los que volvieron'.PHP_EOL;

    $concluidas = collect($ejecutor->ejecutar($global, 'estancias-concluidas', [
        'columnas' => ['matricula', 'estancia_concluida', 'revalidaciones'],
    ])->filas);

    verificar('Sólo sale el que concluyó su estancia',
        $concluidas->count() === 1 && $concluidas->first()['matricula'] === $alumnos[2]->matricula,
        $concluidas->count().' concluidas');

    // Y una estancia SIN concluir no entra, aunque exista.
    DB::table('estancias')->insert([
        'postulacion_id' => $ids['aceptado'],
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'concluida_en' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $trasLaEnCurso = collect($ejecutor->ejecutar($global, 'estancias-concluidas', [
        'columnas' => ['matricula'],
    ])->filas);

    verificar('Una estancia EN CURSO no entra: sus calificaciones aún pueden cambiar',
        $trasLaEnCurso->count() === 1,
        $trasLaEnCurso->count().' concluidas tras agregar una en curso');

    echo PHP_EOL.'6. Los ENTRANTES quedan fuera, y se dice'.PHP_EOL;

    /*
     * El titular dual: un entrante es una persona externa. Se siembra uno y se
     * comprueba que no se cuele — mezclarlo habría obligado a `sinCampus`, que
     * le niega el área entera a cualquier rol acotado a un plantel.
     */
    $externa = Persona::create([
        'nombre' => 'Entrante',
        'primer_apellido' => 'Externo',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    DB::table('postulaciones_movilidad')->insert([
        'convocatoria_id' => $convocatoria,
        'matricula_oferta_id' => null,
        'persona_externa_id' => $externa->id,
        'etapa_id' => $aceptado->id,
        'fecha_postulacion' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $conEntrante = collect($ejecutor->ejecutar($global, 'movilidad-del-periodo', [
        'columnas' => ['matricula', 'alumno'],
    ])->filas);

    verificar('El entrante NO se cuela en la fuente de salientes',
        $conEntrante->count() === 3, $conEntrante->count().' filas (deberían seguir siendo 3)');

    verificar('Y el grano lo DICE, para que el total no se lea como toda la movilidad',
        str_contains($registro->fuente('movilidad-saliente')->grano(), 'ENTRANTES'));

    echo PHP_EOL.'7. El recorte llega por la oferta de la matrícula'.PHP_EOL;

    $campusDelPrimero = $alumnos[0]->oferta?->campus_id;
    $otroCampus = DB::table('campus')->where('id', '!=', $campusDelPrimero)->value('id');

    verificar('Hay dos campus para separar', $otroCampus !== null, $campusDelPrimero.' y '.$otroCampus);

    $acotado = usuarioConRol('director_general', $campusDelPrimero);
    auth()->login($acotado);

    $suyas = collect($ejecutor->ejecutar($acotado, 'movilidad-del-periodo', [
        'columnas' => ['matricula', 'campus'],
    ])->filas);

    $nombreCampus = DB::table('campus')->where('id', $campusDelPrimero)->value('nombre');

    verificar('El acotado sólo ve las de su campus',
        $suyas->every(fn (array $f) => $f['campus'] === $nombreCampus),
        'ajenas: '.$suyas->reject(fn (array $f) => $f['campus'] === $nombreCampus)->count());

    auth()->login($global);

    /*
     * Pasaba `true` literal. La regla que dice cubrir —que esta fuente NO usa
     * `sinCampus`, que lanzaría 403 a todo rol acotado— la sostiene de verdad la
     * línea de arriba: si lo usara, la ejecución habría muerto ahí y la suite
     * entera con ella. Aquí se comprueba lo único que quedaba sin mirar: que el
     * acotado ve MENOS que el global y no cero.
     */
    verificar('Y NO se le niega el área entera con un 403',
        $suyas->isNotEmpty() && $suyas->count() < $conEntrante->count(),
        've '.$suyas->count().' de '.$conEntrante->count());

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
