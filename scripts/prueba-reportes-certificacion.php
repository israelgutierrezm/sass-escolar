<?php

/**
 * La fuente de CERTIFICABLES y sus tres reportes. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-certificacion.php` desde la raíz.
 *
 * ── La comprobación central ───────────────────────────────────────────────
 * `EstadoCertificacion` declara sus reglas DOS VECES: una por matrícula, en
 * PHP, que usan la ficha y el buscador de candidatos; y otra en SQL, agrupada,
 * que usa el reporte. Las dos formas existen porque la de PHP consulta por fila
 * —64 consultas para 32 matrículas, medido— y un reporte no puede pagar eso.
 *
 * El riesgo de tener dos es evidente: que alguien toque una y no la otra, y que
 * la ficha diga que un alumno cerró su plan mientras el reporte dice que no.
 * Por eso lo primero que hace esta suite es compararlas **matrícula por
 * matrícula**, no en total: dos errores que se compensen darían el mismo conteo
 * y distintos alumnos.
 *
 * Lo demás:
 *  - Las tres condiciones de «listo para certificar» son las que el servicio ya
 *    usa para el buscador de candidatos, y se comprueba contra ÉL.
 *  - Total y parcial no se solapan: quien cerró su plan no sale en el parcial.
 *  - La `meta` cae al conteo de la malla cuando el plan no la fija —incluido el
 *    caso del CERO, que en PHP es falso y en SQL no lo sería sin el `nullif`—.
 *  - El identificador del campus viaja, porque hoy es lo que detiene TODO.
 */

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Emision\Certificacion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\Fuentes\Certificables;
use App\Services\EstadoCertificacion;
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
        'primer_apellido' => 'Certificacion',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_cert_'.random_int(100000, 999999),
        'email' => 'prueba_cert_'.random_int(100000, 999999).'@ejemplo.mx',
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
    $servicio = app(EstadoCertificacion::class);
    $fuente = app(Certificables::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'0. Los tres escenarios que el demo NO tiene'.PHP_EOL;

    /*
     * Se siembran ANTES de todo lo demás, para que todas las secciones los
     * ejerciten. Los tres salieron de mutaciones que sobrevivían: el demo no
     * tiene ni un recursamiento, ni un plan sin meta, ni una carrera que no
     * expida papel, así que tres reglas de verdad pasaban sin comprobarse.
     */

    // (a) Un RECURSAMIENTO: la misma materia aprobada DOS veces. Sin él,
    // `count(*)` y `count(distinct)` dan lo mismo y la regla no se ejercita.
    $conHistorial = MatriculaOferta::query()
        ->whereHas('historial')
        ->firstOrFail();

    $unRenglon = DB::table('historial')
        ->where('matricula_oferta_id', $conHistorial->id)
        ->whereNotNull('plan_materia_id')
        ->whereNull('deleted_at')
        ->first();

    verificar('Hay un renglón de historial para duplicar', $unRenglon !== null);

    $aprobada = DB::table('estatus_historial')->where('clave', 'aprobada')->value('id');

    DB::table('historial')->insert([
        'matricula_oferta_id' => $conHistorial->id,
        'plan_materia_id' => $unRenglon->plan_materia_id,
        'ciclo_id' => $unRenglon->ciclo_id,
        'tipo_evaluacion_id' => $unRenglon->tipo_evaluacion_id,
        'calificacion' => 9.0,
        'estatus_id' => $aprobada,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $renglones = DB::table('historial')
        ->where('matricula_oferta_id', $conHistorial->id)
        ->where('plan_materia_id', $unRenglon->plan_materia_id)
        ->whereNull('deleted_at')->count();

    verificar('(a) Hay una materia con DOS renglones aprobados',
        $renglones >= 2, $renglones.' renglones de la misma materia');

    // (b) Una CARRERA que no expide documentos oficiales: un diplomado.
    $carreraSinPapel = MatriculaOferta::query()
        ->whereHas('oferta.carrera')
        ->get()
        ->first(fn (MatriculaOferta $m) => $servicio->disponible($m) && $servicio->emiteDocumentos($m));

    verificar('Hay una matrícula que cerró su plan para el escenario',
        $carreraSinPapel !== null, $carreraSinPapel?->matricula ?? 'ninguna');

    $idCarreraSinPapel = $carreraSinPapel?->oferta?->carrera_id;

    DB::table('carreras')->where('id', $idCarreraSinPapel)->update(['emite_documentos_oficiales' => false]);

    verificar('(b) Esa carrera ya NO expide documentos oficiales',
        DB::table('carreras')->where('id', $idCarreraSinPapel)->value('emite_documentos_oficiales') == 0);

    echo PHP_EOL.'1. Las DOS formas de la regla dicen lo mismo'.PHP_EOL;

    /*
     * Matrícula por matrícula, no en total: dos errores que se compensen darían
     * el mismo conteo y distintos alumnos. Es la única forma de que esta suite
     * sirva para lo que existe — impedir que las dos declaraciones diverjan.
     */
    $discrepan = [];
    $miradas = 0;

    foreach ($fuente->consulta($global, [])->get() as $m) {
        $miradas++;

        $porSql = (int) ($m->meta ?? 0) > 0 && (int) ($m->aprobadas ?? 0) >= (int) $m->meta;
        $porPhp = $servicio->disponible($m);

        if ($porSql !== $porPhp) {
            $discrepan[] = $m->matricula.': sql='.var_export($porSql, true).' php='.var_export($porPhp, true);
        }

        $aprobadasPhp = $servicio->aprobadasDistintas($m->id);

        if ((int) ($m->aprobadas ?? 0) !== $aprobadasPhp) {
            $discrepan[] = $m->matricula.': aprobadas sql='.($m->aprobadas ?? 0).' php='.$aprobadasPhp;
        }

        $metaPhp = $servicio->metaMaterias($m->oferta?->plan);

        if ((int) ($m->meta ?? 0) !== $metaPhp) {
            $discrepan[] = $m->matricula.': meta sql='.($m->meta ?? 0).' php='.$metaPhp;
        }
    }

    verificar('Hay matrículas suficientes para comparar', $miradas >= 10, $miradas.' matrículas');

    verificar('«Cerró su plan», «aprobadas» y «meta» coinciden en TODAS',
        $discrepan === [], $discrepan === [] ? 'las '.$miradas.' cuadran' : implode(' | ', array_slice($discrepan, 0, 3)));

    // Y que la comparación no sea vacua: tiene que haber de los dos lados.
    $filas = collect($ejecutor->ejecutar($global, 'avance-de-certificacion', [
        'columnas' => ['matricula', 'aprobadas', 'meta', 'cerro_plan', 'ya_en_lote'],
    ])->filas);

    $cerraron = $filas->where('cerro_plan', true)->count();

    verificar('Hay matrículas que cerraron y matrículas que no (si no, sería vacua)',
        $cerraron > 0 && $cerraron < $filas->count(),
        $cerraron.' cerraron de '.$filas->count());

    echo PHP_EOL.'2. «Listos para certificar» es lo que el SERVICIO ya decide'.PHP_EOL;

    /*
     * El buscador de candidatos de un lote usa `elegibleParaLote()`. Si el
     * reporte trajera otra lista, la escuela vería un padrón en la pantalla y
     * otro en el archivo, y armaría el lote con el que tuviera a mano.
     */
    $listos = collect($ejecutor->ejecutar($global, 'listos-para-certificar', [
        'columnas' => ['matricula'],
    ])->filas)->pluck('matricula')->sort()->values();

    $porElServicio = MatriculaOferta::query()
        ->with('oferta.carrera', 'oferta.plan')
        ->get()
        ->filter(fn (MatriculaOferta $m) => $servicio->elegibleParaLote($m, 'total'))
        ->pluck('matricula')->sort()->values();

    verificar('El servicio encuentra candidatos (si no, sería vacua)',
        $porElServicio->isNotEmpty(), $porElServicio->count().' candidatos');

    verificar('El reporte trae EXACTAMENTE los del servicio',
        $listos->all() === $porElServicio->all(),
        'reporte '.$listos->count().' vs servicio '.$porElServicio->count());

    echo PHP_EOL.'3. Total y parcial no se solapan'.PHP_EOL;

    $parciales = collect($ejecutor->ejecutar($global, 'avance-certificado-parcial', [
        'columnas' => ['matricula', 'aprobadas', 'meta'],
    ])->filas);

    verificar('Hay candidatos a parcial', $parciales->isNotEmpty(), $parciales->count().' con avance sin cerrar');

    verificar('Ninguno del parcial está en «listos» (total)',
        $parciales->pluck('matricula')->intersect($listos)->isEmpty(),
        $parciales->pluck('matricula')->intersect($listos)->implode(', '));

    verificar('Y todos los del parcial tienen avance pero no cierran',
        $parciales->every(fn (array $f) => (int) $f['aprobadas'] > 0
            && ((int) $f['meta'] === 0 || (int) $f['aprobadas'] < (int) $f['meta'])),
        'incumplen: '.$parciales->reject(fn (array $f) => (int) $f['aprobadas'] > 0
            && ((int) $f['meta'] === 0 || (int) $f['aprobadas'] < (int) $f['meta']))->count());

    // Y contra el servicio, que es quien lo declara.
    $parcialesServicio = MatriculaOferta::query()
        ->with('oferta.plan')
        ->get()
        ->filter(fn (MatriculaOferta $m) => $servicio->disponibleParcial($m))
        ->pluck('matricula');

    verificar('El parcial del reporte cabe dentro del del servicio',
        $parciales->pluck('matricula')->diff($parcialesServicio)->isEmpty(),
        'de más: '.$parciales->pluck('matricula')->diff($parcialesServicio)->implode(', '));

    echo PHP_EOL.'4. La meta cae al conteo de la malla, incluido el CERO'.PHP_EOL;

    /*
     * `metaMaterias()` usa `?:`, y en PHP el CERO es falso: un plan con
     * `minimo_asignaturas = 0` cae al conteo de su malla. En SQL eso NO es
     * automático —`coalesce` sólo mira el NULL— y sin el `nullif(…, 0)` la meta
     * se quedaría en 0 y NADIE de ese plan podría certificarse: el reporte
     * saldría vacío y parecería que nadie ha terminado.
     */
    $unPlan = MatriculaOferta::query()->whereHas('oferta.plan')->first()?->oferta?->plan;

    verificar('Hay un plan para el escenario', $unPlan !== null);

    if ($unPlan !== null) {
        $original = $unPlan->minimo_asignaturas;
        $materiasDeLaMalla = DB::table('plan_materias')
            ->where('plan_id', $unPlan->id)->whereNull('deleted_at')->count();

        verificar('Ese plan tiene materias en su malla', $materiasDeLaMalla > 0, (string) $materiasDeLaMalla);

        foreach ([null, 0] as $valor) {
            DB::table('planes_estudio')->where('id', $unPlan->id)->update(['minimo_asignaturas' => $valor]);

            $meta = collect(DB::select(
                'select meta from ('.$servicio->metaPorPlanConsulta()->toSql().') as t where plan_id = ?',
                [...$servicio->metaPorPlanConsulta()->getBindings(), $unPlan->id],
            ))->first()?->meta;

            verificar('Con `minimo_asignaturas` en '.var_export($valor, true).' la meta es la malla',
                (int) $meta === $materiasDeLaMalla, $meta.' vs '.$materiasDeLaMalla);
        }

        DB::table('planes_estudio')->where('id', $unPlan->id)->update(['minimo_asignaturas' => $original]);
    }

    echo PHP_EOL.'5. Lo que ya está en trámite no se ofrece'.PHP_EOL;

    $candidato = MatriculaOferta::query()
        ->with('oferta.carrera', 'oferta.plan')
        ->get()
        ->first(fn (MatriculaOferta $m) => $servicio->elegibleParaLote($m, 'total'));

    verificar('Hay un candidato para el escenario', $candidato !== null, $candidato?->matricula ?? 'ninguno');

    if ($candidato !== null) {
        $antes = collect($ejecutor->ejecutar($global, 'listos-para-certificar', ['columnas' => ['matricula']])->filas);

        verificar('Antes de meterlo a un lote, SÍ sale',
            $antes->contains('matricula', $candidato->matricula));

        // Se le crea una certificación PENDIENTE, como al armar un lote.
        $lote = DB::table('lotes_certificacion')->whereNull('deleted_at')->first();

        verificar('Hay un lote en el demo para colgarle la certificación', $lote !== null);

        if ($lote !== null) {
            Certificacion::create([
                'lote_id' => $lote->id,
                'matricula_oferta_id' => $candidato->id,
                'estado' => Certificacion::PENDIENTE,
            ]);

            $despues = collect($ejecutor->ejecutar($global, 'listos-para-certificar', ['columnas' => ['matricula']])->filas);

            verificar('Con la certificación pendiente YA NO sale',
                ! $despues->contains('matricula', $candidato->matricula),
                $antes->count().' → '.$despues->count());

            // Pero uno en ERROR sí vuelve a ofrecerse: se puede reintentar.
            Certificacion::query()
                ->where('matricula_oferta_id', $candidato->id)
                ->update(['estado' => Certificacion::ERROR]);

            $conError = collect($ejecutor->ejecutar($global, 'listos-para-certificar', ['columnas' => ['matricula']])->filas);

            verificar('Y si quedó en ERROR vuelve a ofrecerse: se puede reintentar',
                $conError->contains('matricula', $candidato->matricula));
        }
    }

    echo PHP_EOL.'6. El identificador del campus viaja, porque hoy detiene TODO'.PHP_EOL;

    $conId = collect($ejecutor->ejecutar($global, 'listos-para-certificar', [
        'columnas' => ['matricula', 'campus', 'campus_identificador'],
    ])->filas);

    $sinIdentificador = $conId->filter(fn (array $f) => blank($f['campus_identificador']));

    verificar('La columna del identificador existe y se puede pedir',
        $conId->isEmpty() || array_key_exists('campus_identificador', $conId->first()));

    /*
     * En el demo los TRES campus lo tienen vacío, y por eso `ValidadorDec`
     * devuelve un error por renglón. Se comprueba que el reporte lo ENSEÑE en
     * vez de callarlo: es la casilla que hay que llenar antes que nada.
     */
    $campusSinId = Campus::query()->whereNull('identificador')->count();

    verificar('El reporte enseña vacío el identificador de los campus que lo tienen vacío',
        $campusSinId === 0 || $sinIdentificador->isNotEmpty(),
        $campusSinId.' campus sin identificador, '.$sinIdentificador->count().' filas lo enseñan vacío');

    // Y con identificador puesto, la columna lo trae.
    $unCampus = Campus::query()->first();
    $idOriginal = $unCampus->identificador;
    DB::table('campus')->where('id', $unCampus->id)->update(['identificador' => 'PRUEBA-01']);

    $trasPonerlo = collect($ejecutor->ejecutar($global, 'avance-de-certificacion', [
        'columnas' => ['matricula', 'campus', 'campus_identificador'],
    ])->filas)->firstWhere('campus', $unCampus->nombre);

    verificar('Y con el identificador puesto, la columna lo trae',
        ($trasPonerlo['campus_identificador'] ?? null) === 'PRUEBA-01',
        (string) ($trasPonerlo['campus_identificador'] ?? 'null'));

    DB::table('campus')->where('id', $unCampus->id)->update(['identificador' => $idOriginal]);

    echo PHP_EOL.'7. El grano no se multiplica'.PHP_EOL;

    $todas = collect($ejecutor->ejecutar($global, 'avance-de-certificacion', [
        'columnas' => ['matricula', 'aprobadas'],
    ])->filas);

    $repetidas = $todas->groupBy('matricula')->filter(fn ($g) => $g->count() > 1);

    verificar('Ninguna matrícula sale más de una vez',
        $repetidas->isEmpty(), $repetidas->isEmpty() ? 'sin repetidas' : $repetidas->keys()->implode(', '));

    verificar('Y son tantas como matrículas hay',
        $todas->count() === MatriculaOferta::query()->count(),
        $todas->count().' de '.MatriculaOferta::query()->count());

    echo PHP_EOL.'8. Y los tres escenarios se comportan como deben'.PHP_EOL;

    /*
     * (a) El RECURSAMIENTO cuenta UNA materia, no dos renglones. Sin el
     * `distinct`, quien aprobó dos veces la misma materia aparecería con una
     * materia de más y podría darse por cerrado sin serlo.
     */
    $delRecursamiento = collect($ejecutor->ejecutar($global, 'avance-de-certificacion', [
        'columnas' => ['matricula', 'aprobadas'],
    ])->filas)->firstWhere('matricula', $conHistorial->matricula);

    $porElServicioAprobadas = $servicio->aprobadasDistintas($conHistorial->id);

    verificar('(a) El recursamiento cuenta UNA materia, no dos renglones',
        (int) $delRecursamiento['aprobadas'] === $porElServicioAprobadas,
        'reporte '.$delRecursamiento['aprobadas'].' vs servicio '.$porElServicioAprobadas);

    $renglonesTotales = DB::table('historial as h')
        ->join('estatus_historial as eh', 'eh.id', '=', 'h.estatus_id')
        ->where('h.matricula_oferta_id', $conHistorial->id)
        ->whereNotNull('h.plan_materia_id')
        ->whereNull('h.deleted_at')
        ->where('eh.clave', 'aprobada')
        ->count();

    verificar('Y los renglones SON más que las materias (si no, sería vacua)',
        $renglonesTotales > $porElServicioAprobadas,
        $renglonesTotales.' renglones vs '.$porElServicioAprobadas.' materias');

    /*
     * (b) Una carrera que NO expide papel no se ofrece, aunque haya cerrado su
     * plan: un diplomado vive en el mismo catálogo y no tiene RVOE que respalde
     * un certificado.
     */
    $trasQuitarPapel = collect($ejecutor->ejecutar($global, 'listos-para-certificar', [
        'columnas' => ['matricula', 'carrera'],
    ])->filas);

    verificar('(b) Quien cerró su plan pero cuya carrera NO expide papel, no sale',
        ! $trasQuitarPapel->contains('matricula', $carreraSinPapel->matricula),
        $carreraSinPapel->matricula.' — '.$trasQuitarPapel->count().' listos');

    // Y la columna lo DICE, en vez de que desaparezca sin explicación.
    $suFila = collect($ejecutor->ejecutar($global, 'avance-de-certificacion', [
        'columnas' => ['matricula', 'cerro_plan', 'emite_documentos'],
    ])->filas)->firstWhere('matricula', $carreraSinPapel->matricula);

    verificar('Y el reporte de avance explica por qué: cerró, pero no expide',
        ($suFila['cerro_plan'] ?? null) === true && ($suFila['emite_documentos'] ?? null) === false,
        'cerro='.var_export($suFila['cerro_plan'] ?? null, true).' expide='.var_export($suFila['emite_documentos'] ?? null, true));

    echo PHP_EOL.'9. Un plan con META CERO no certifica a nadie'.PHP_EOL;

    /*
     * Este escenario va AL FINAL y no con los otros dos, por dos razones que se
     * descubrieron al ponerlo en medio:
     *
     *  1. Vaciar la malla de un plan afecta a TODAS las matriculas de ese plan,
     *     y la primera que el script elige puede compartirlo con las de los
     *     otros escenarios. Media suite se cayo por eso.
     *  2. **`metaMaterias()` CACHEA por plan dentro de la peticion.** Tras
     *     vaciar la malla, la forma de PHP seguia devolviendo 48 mientras la de
     *     SQL devolvia 0 — y la comparacion de la seccion 1 lo reportaba como
     *     divergencia. No es un defecto del producto: la malla de un plan no
     *     cambia a media peticion. Pero una prueba que la cambia tiene que
     *     pedirle al servicio una instancia limpia.
     */
    $servicioLimpio = new EstadoCertificacion;

    /*
     * (c) Un plan con META CERO y alguien sin nada aprobado.
     *
     * En el demo NO existe: las 32 matrículas tienen historial. Se construye
     * dando de baja el historial de una y vaciando la malla de su plan.
     *
     * La primera versión buscaba una matrícula `whereDoesntHave('historial')`,
     * no encontraba ninguna, y aun así la comprobación PASABA: `$planVacio`
     * quedaba en null y `metaMaterias(null)` devuelve 0 por contrato. Una
     * comprobación que se cumple porque el escenario no existe es exactamente lo
     * que estas mutaciones vienen a destapar.
     */
    /*
     * Y tiene que ser una que SÍ podría aparecer en «listos» si la regla se
     * aflojara: de otra carrera que sí expida papel y sin trámite abierto. La
     * primera versión tomaba la primera matrícula a secas y resultó ser de la
     * carrera que el escenario (b) acababa de dejar sin papel, así que quedaba
     * excluida por OTRA condición y quitarle el `meta > 0` no tumbaba nada.
     */
    $sinAvance = MatriculaOferta::query()
        ->whereHas('oferta.plan')
        ->whereHas('oferta.carrera', fn ($c) => $c->where('id', '!=', $idCarreraSinPapel))
        ->whereDoesntHave('certificaciones')
        ->firstOrFail();

    DB::table('historial')->where('matricula_oferta_id', $sinAvance->id)->update(['deleted_at' => now()]);

    $planVacio = $sinAvance->oferta?->plan_id;

    verificar('La matrícula del escenario tiene plan', $planVacio !== null, (string) $planVacio);

    DB::table('plan_materias')->where('plan_id', $planVacio)->update(['deleted_at' => now()]);
    DB::table('planes_estudio')->where('id', $planVacio)->update(['minimo_asignaturas' => null]);

    verificar('(c) Su plan quedó con meta CERO y ella sin nada aprobado',
        $planVacio !== null
        && $servicioLimpio->metaMaterias(App\Models\Academico\PlanEstudio::find($planVacio)) === 0
        && $servicioLimpio->aprobadasDistintas($sinAvance->id) === 0,
        'meta '.$servicioLimpio->metaMaterias(App\Models\Academico\PlanEstudio::find($planVacio))
        .', aprobadas '.$servicioLimpio->aprobadasDistintas($sinAvance->id));


    /*
     * (c) Un plan con META CERO no da por cerrado a quien no aprobó nada. Sin
     * el `meta > 0`, `0 >= 0` es cierto y TODA esa matrícula saldría lista para
     * certificarse sin haber cursado nada.
     */
    $conMetaCero = collect($ejecutor->ejecutar($global, 'avance-de-certificacion', [
        'columnas' => ['matricula', 'aprobadas', 'meta', 'cerro_plan'],
    ])->filas)->firstWhere('matricula', $sinAvance->matricula);

    verificar('(c) Su meta es cero y no aprobó nada (si no, sería vacua)',
        (int) ($conMetaCero['meta'] ?? -1) === 0 && (int) ($conMetaCero['aprobadas'] ?? -1) === 0,
        'meta='.($conMetaCero['meta'] ?? 'null').' aprobadas='.($conMetaCero['aprobadas'] ?? 'null'));

    verificar('Y NO se da por cerrado',
        ($conMetaCero['cerro_plan'] ?? null) === false,
        var_export($conMetaCero['cerro_plan'] ?? null, true));

    $listosFinal = collect($ejecutor->ejecutar($global, 'listos-para-certificar', ['columnas' => ['matricula']])->filas);

    verificar('Ni aparece entre los listos para certificar',
        ! $listosFinal->contains('matricula', $sinAvance->matricula));

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

} finally {
    DB::rollBack();
}
