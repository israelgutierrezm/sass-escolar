<?php

/**
 * La fuente de CARTERA y sus tres reportes. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-finanzas.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. El saldo del reporte es EL MISMO que el de `/finanzas`. No se recalcula:
 *     sale de `SaldosDeCartera`, que existe porque esa agregación había estado
 *     escrita dos veces y ya había divergido. Si el reporte trajera otra cifra,
 *     habría una tercera verdad sobre cuánto debe un alumno.
 *  2. **El grano no se multiplica.** La fuente hace DOS `leftJoinSub` y un
 *     `leftJoin`, y la bitácora de situación financiera tiene VARIAS filas por
 *     matrícula: un join mal puesto convertiría a un alumno con cuatro cambios
 *     de situación en cuatro renglones, y el conteo de «alumnos» diría cuatro.
 *     No da error: da otro número.
 *  3. El bloqueo lo dice la BANDERA `situaciones_pago.bloquea`, no el saldo.
 *     Se puede deber sin estar bloqueado y estar bloqueado sin deber.
 *  4. El RECORTE por campus acota de verdad, y por la OFERTA: `matricula_oferta`
 *     no tiene `campus_id`.
 *  5. La columna CURP se omite para quien no administra expedientes.
 *  6. **El orden por una columna NULABLE de un JOIN no trunca la exportación.**
 *     `f.saldo` es NULL para 30 de las 32 matrículas del demo, así que ésta es
 *     la fuente donde esa trampa vive de verdad. Y se comprueba que la columna
 *     viaje al SELECT SIN transformar: con un `coalesce` en el SQL el cursor
 *     leería 0 y compararía contra NULL.
 *
 * ── El demo tiene casi todo en cero, así que se SIEMBRA ───────────────────
 * Hay 7 adeudos y CERO pagos. Todo lo que dependa de un pago se construye
 * dentro de la transacción y se mide POR DIFERENCIA contra una línea base — la
 * lección que este proyecto pagó dos veces en un solo día.
 */

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SituacionPago;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Services\Finanzas\SaldosDeCartera;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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

/** Un usuario propio con su rol activo: nunca se toca el de nadie más. */
function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Finanzas',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_fin_'.random_int(100000, 999999),
        'email' => 'prueba_fin_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Illuminate\Support\Facades\Hash::make('secreto12345'),
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
    $saldos = app(SaldosDeCartera::class);
    $hoy = now()->toDateString();

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. La fuente está registrada y declara lo que debe'.PHP_EOL;

    $fuente = $registro->fuente('cartera');

    verificar('La fuente `cartera` está en el registro', $fuente->clave() === 'cartera');

    /*
     * NINGÚN módulo. `finanzas` existe en el catálogo pero no tiene fila en
     * `modulos_activos` y `ModulosDeLaEscuela` falla cerrado, así que
     * declararlo devolvería 404 en TODOS los reportes de finanzas de esta
     * escuela — que es justo la trampa latente que CLAUDE.md tenía anotada.
     */
    verificar('No declara módulo (`finanzas` está apagado y fallaría cerrado)',
        $fuente->modulo() === null, var_export($fuente->modulo(), true));

    verificar('Y comprobado que `finanzas` de verdad NO está encendido en el demo',
        ! app(ModulosDeLaEscuela::class)->activo('finanzas'));

    verificar('Sólo la faceta administrativa la ejecuta',
        $fuente->facetas() === ['administrativo'], implode(',', $fuente->facetas()));

    echo PHP_EOL.'2. El saldo es EL MISMO que el de la pantalla de cartera'.PHP_EOL;

    $delServicio = collect($saldos->porMatricula($hoy)->get())->keyBy('matricula_oferta_id');

    $resultado = $ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula', 'saldo', 'vencido', 'cargos_abiertos'],
    ]);

    $filas = collect($resultado->filas);

    verificar('El reporte trae filas', $filas->isNotEmpty(), $filas->count().' filas');

    // Se compara CADA fila contra el servicio, no sólo el total: dos errores
    // que se compensan darían el mismo total y distintas filas.
    $conSaldo = MatriculaOferta::query()
        ->whereIn('id', $delServicio->keys())
        ->pluck('matricula', 'id');

    $cuadran = 0;
    $descuadran = [];

    foreach ($filas as $fila) {
        $id = $conSaldo->search($fila['matricula']);

        if ($id === false) {
            // Sin cargos abiertos: el reporte tiene que decir 0, no NULL.
            if ((float) $fila['saldo'] !== 0.0) {
                $descuadran[] = $fila['matricula'].' sin cargos pero con saldo '.$fila['saldo'];
            }

            continue;
        }

        $esperado = round((float) $delServicio[$id]->saldo, 2);

        if (abs((float) $fila['saldo'] - $esperado) < 0.01) {
            $cuadran++;
        } else {
            $descuadran[] = $fila['matricula'].': reporte '.$fila['saldo'].' vs servicio '.$esperado;
        }
    }

    verificar('Cada saldo del reporte es el del servicio',
        $descuadran === [], $descuadran === [] ? $cuadran.' matrículas con saldo, todas cuadran' : implode(' | ', $descuadran));

    verificar('Y las que no deben salen en 0, no en blanco',
        $filas->every(fn (array $f) => $f['saldo'] !== null),
        'sin saldo: '.$filas->filter(fn (array $f) => $f['saldo'] === null)->count());

    echo PHP_EOL.'3. El grano NO se multiplica (la trampa de los joins)'.PHP_EOL;

    /*
     * Se le ponen CUATRO renglones de bitácora a una matrícula. Con un join a
     * `bitacora_situacion_financiera` en vez de la subconsulta, esa matrícula
     * saldría cuatro veces y el reporte contaría cuatro alumnos donde hay uno.
     */
    $victima = MatriculaOferta::query()->whereHas('oferta')->firstOrFail();

    $situaciones = SituacionPago::query()->orderBy('id')->get();

    verificar('Hay situaciones de pago en el catálogo', $situaciones->count() >= 2, $situaciones->count().' situaciones');

    $antesDeLaBitacora = collect($ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula'],
    ])->filas)->where('matricula', $victima->matricula)->count();

    verificar('Antes de sembrar, la matrícula sale UNA vez', $antesDeLaBitacora === 1, (string) $antesDeLaBitacora);

    foreach (range(0, 3) as $i) {
        BitacoraSituacionFinanciera::create([
            'matricula_oferta_id' => $victima->id,
            'situacion_id' => $situaciones[$i % $situaciones->count()]->id,
            'motivo' => 'Prueba de grano '.$i,
            'momento' => now()->subDays(4 - $i),
        ]);
    }

    verificar('La matrícula tiene ahora cuatro renglones de bitácora (si no, la prueba sería vacua)',
        BitacoraSituacionFinanciera::where('matricula_oferta_id', $victima->id)->count() >= 4,
        (string) BitacoraSituacionFinanciera::where('matricula_oferta_id', $victima->id)->count());

    $conBitacora = collect($ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula', 'situacion_financiera'],
    ])->filas);

    verificar('Con cuatro renglones de bitácora SIGUE saliendo una vez',
        $conBitacora->where('matricula', $victima->matricula)->count() === 1,
        $conBitacora->where('matricula', $victima->matricula)->count().' veces');

    // Y la que se enseña es la ÚLTIMA, no una cualquiera.
    $ultima = BitacoraSituacionFinanciera::query()
        ->where('matricula_oferta_id', $victima->id)
        ->orderByDesc('momento')->orderByDesc('id')
        ->first();

    $suNombre = SituacionPago::find($ultima->situacion_id)?->nombre;

    verificar('Y la situación que enseña es la VIGENTE, no una cualquiera',
        $conBitacora->firstWhere('matricula', $victima->matricula)['situacion_financiera'] === $suNombre,
        ($conBitacora->firstWhere('matricula', $victima->matricula)['situacion_financiera'] ?? 'null').' vs '.$suNombre);

    echo PHP_EOL.'4. El bloqueo lo dice la BANDERA, no el saldo'.PHP_EOL;

    $bloquea = SituacionPago::query()->where('bloquea', true)->first();
    $noBloquea = SituacionPago::query()->where('bloquea', false)->first();

    verificar('El catálogo tiene una situación que bloquea y una que no',
        $bloquea !== null && $noBloquea !== null,
        ($bloquea?->nombre ?? 'ninguna').' / '.($noBloquea?->nombre ?? 'ninguna'));

    /*
     * El caso que separa las dos preguntas: alguien SIN saldo y BLOQUEADO. Si
     * el reporte preguntara por el saldo, no saldría — y es exactamente quien
     * se va a plantar en ventanilla el día de la reinscripción.
     */
    $sinDeuda = MatriculaOferta::query()
        ->whereHas('oferta')
        ->whereNotIn('id', $delServicio->keys())
        ->where('id', '!=', $victima->id)
        ->firstOrFail();

    BitacoraSituacionFinanciera::create([
        'matricula_oferta_id' => $sinDeuda->id,
        'situacion_id' => $bloquea->id,
        'motivo' => 'Prueba: bloqueado sin deber',
        'momento' => now(),
    ]);

    $bloqueados = collect($ejecutor->ejecutar($global, 'bloqueados-por-adeudo', [
        'columnas' => ['matricula', 'saldo'],
    ])->filas);

    verificar('Un alumno BLOQUEADO SIN SALDO sale en el reporte de bloqueados',
        $bloqueados->contains('matricula', $sinDeuda->matricula),
        $bloqueados->count().' bloqueados');

    verificar('Y su saldo es cero, o sea que no salió por deber',
        (float) ($bloqueados->firstWhere('matricula', $sinDeuda->matricula)['saldo'] ?? -1) === 0.0);

    /*
     * Y al revés: quien debe pero no está bloqueado NO sale ahí.
     *
     * Se exige que la matrícula EXISTA. Tres de los siete adeudos del demo
     * apuntan a la matrícula 288, que no está en `matricula_oferta` —restos de
     * una resiembra con las foráneas apagadas, y de las que
     * `acadion:auditar-datos` no puede reparar porque la columna participa en
     * el CHECK del titular—. Tomar la primera del servicio se llevaba ésa.
     */
    $deudorLibre = MatriculaOferta::query()
        ->whereIn('id', $delServicio->keys())
        ->where('id', '!=', $sinDeuda->id)
        ->value('id');

    verificar('Hay un deudor cuya matrícula existe de verdad',
        $deudorLibre !== null, (string) $deudorLibre);

    BitacoraSituacionFinanciera::create([
        'matricula_oferta_id' => $deudorLibre,
        'situacion_id' => $noBloquea->id,
        'motivo' => 'Prueba: debe con convenio',
        'momento' => now(),
    ]);

    $bloqueados2 = collect($ejecutor->ejecutar($global, 'bloqueados-por-adeudo', ['columnas' => ['matricula']])->filas);
    $suMatricula = MatriculaOferta::find($deudorLibre)?->matricula;

    verificar('Quien DEBE pero no está bloqueado NO sale en bloqueados',
        ! $bloqueados2->contains('matricula', $suMatricula), (string) $suMatricula);

    /*
     * Y la COLUMNA `bloqueado` también lo dice por la bandera.
     *
     * El filtro y la columna son dos caminos distintos —uno arma el `where`, el
     * otro pinta la celda— y hay que comprobar los dos: derivar la celda del
     * saldo pasaba en verde mientras sólo se miraba el filtro. Es la misma
     * lección que dejó `prueba-listados` con la insignia de «Inscrito».
     */
    $conBandera = collect($ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula', 'saldo', 'bloqueado'],
    ])->filas);

    $celdaDelBloqueado = $conBandera->firstWhere('matricula', $sinDeuda->matricula);
    $celdaDelDeudor = $conBandera->firstWhere('matricula', MatriculaOferta::find($deudorLibre)?->matricula);

    verificar('La columna dice BLOQUEADO de quien no debe nada',
        ($celdaDelBloqueado['bloqueado'] ?? null) === true && (float) $celdaDelBloqueado['saldo'] === 0.0,
        var_export($celdaDelBloqueado['bloqueado'] ?? null, true).' con saldo '.($celdaDelBloqueado['saldo'] ?? '?'));

    verificar('Y NO bloqueado de quien debe con convenio',
        ($celdaDelDeudor['bloqueado'] ?? null) === false && (float) $celdaDelDeudor['saldo'] > 0,
        var_export($celdaDelDeudor['bloqueado'] ?? null, true).' con saldo '.($celdaDelDeudor['saldo'] ?? '?'));

    // Pero sí sale en la cartera vencida, que es la otra pregunta.
    $vencida = collect($ejecutor->ejecutar($global, 'cartera-vencida', ['columnas' => ['matricula', 'vencido']])->filas);

    verificar('Los reportes de bloqueo y de vencido contestan cosas DISTINTAS',
        $vencida->pluck('matricula')->sort()->values()->all()
            !== $bloqueados2->pluck('matricula')->sort()->values()->all(),
        'vencida: '.$vencida->count().', bloqueados: '.$bloqueados2->count());

    echo PHP_EOL.'5. El recorte por campus acota, y por la OFERTA'.PHP_EOL;

    $campusId = Campus::query()
        ->whereHas('ofertas')
        ->orderBy('id')
        ->value('id');

    $acotado = usuarioConRol('director_general', $campusId);
    auth()->login($acotado);

    $suyas = collect($ejecutor->ejecutar($acotado, 'estado-de-cartera', ['columnas' => ['matricula', 'campus']])->filas);

    auth()->login($global);
    $todas = collect($ejecutor->ejecutar($global, 'estado-de-cartera', ['columnas' => ['matricula', 'campus']])->filas);

    verificar('El acotado ve MENOS matrículas que el global',
        $suyas->count() < $todas->count(), $suyas->count().' de '.$todas->count());

    $nombreCampus = Campus::find($campusId)?->nombre;

    verificar('Y todas las que ve son de SU campus',
        $suyas->every(fn (array $f) => $f['campus'] === $nombreCampus),
        'ajenas: '.$suyas->reject(fn (array $f) => $f['campus'] === $nombreCampus)->count());

    echo PHP_EOL.'6. La CURP se omite para quien no administra expedientes'.PHP_EOL;

    $conCurp = $ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => ['matricula', 'curp'],
    ]);

    verificar('Con `editar-alumnos` la CURP viaja',
        in_array('curp', array_column($conCurp->columnas, 'clave'), true));

    /*
     * Un rol pelado al que se le concede `ver-reportes` y `ver-adeudos` pero NO
     * `editar-alumnos`. Se construye: los roles heredan los permisos de su
     * faceta, así que hay que quitárselo a la faceta del rol de prueba.
     */
    $rolPelado = Rol::create([
        'name' => 'prueba_fin_'.random_int(10000, 99999),
        'nombre' => 'Prueba finanzas',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::query()->where('name', 'administrativo')->value('id'),
    ]);

    $rolPelado->syncPermissions(['ver-reportes', 'ver-adeudos']);

    $sinCurp = usuarioConRol($rolPelado->name);

    // La faceta le hereda permisos: se comprueba que de verdad NO lo tenga.
    $faceta = Rol::query()->where('name', 'administrativo')->first();
    $teniaEditar = $faceta->permissions->pluck('name')->contains('editar-alumnos');

    if ($teniaEditar) {
        $faceta->revokePermissionTo('editar-alumnos');
    }

    $sinCurp = Usuario::find($sinCurp->id);
    auth()->login($sinCurp);

    verificar('El usuario de prueba NO tiene `editar-alumnos` (si no, la prueba sería vacua)',
        ! $sinCurp->can('editar-alumnos'));

    $suyo = $ejecutor->ejecutar($sinCurp, 'estado-de-cartera', [
        'columnas' => ['matricula', 'curp', 'saldo'],
    ]);

    verificar('Sin el permiso extra, la CURP NO viaja',
        ! in_array('curp', array_column($suyo->columnas, 'clave'), true),
        implode(',', array_column($suyo->columnas, 'clave')));

    verificar('Y se ANOTA que se omitió, en vez de callarlo',
        $suyo->columnasOmitidas !== [], implode(",", $suyo->columnasOmitidas));

    auth()->login($global);

    echo PHP_EOL.'7. Ordenar por una columna NULABLE del JOIN no trunca'.PHP_EOL;

    /*
     * ÉSTA es la fuente donde esa trampa vive: `f.saldo` es NULL para toda
     * matrícula sin cargos abiertos —30 de las 32 del demo—, así que un
     * recorrido por lotes mal escrito descarga un archivo corto que abre
     * perfectamente.
     */
    $lotesChicos = new class(app(RegistroReportes::class), app(ModulosDeLaEscuela::class)) extends Ejecutor
    {
        protected function tamanoDeLote(): int
        {
            return 5;
        }
    };

    $conNulos = MatriculaOferta::query()->whereHas('oferta')->count()
        - $delServicio->count();

    verificar('Hay más matrículas SIN saldo que el tamaño del lote (si no, la rama del NULL no se ejercita)',
        $conNulos > 5, $conNulos.' sin cargos abiertos, lotes de 5');

    foreach (['asc', 'desc'] as $direccion) {
        $exportacion = $lotesChicos->paraExportar($global, 'estado-de-cartera', [
            'columnas' => ['matricula', 'saldo'],
            'orden_por' => 'saldo',
            'orden_dir' => $direccion,
        ]);

        $emitidas = iterator_to_array($exportacion->recorrer(), false);

        verificar("Orden {$direccion} por saldo: salen TODAS las filas",
            count($emitidas) === $exportacion->total,
            count($emitidas).' de '.$exportacion->total);

        verificar("Orden {$direccion} por saldo: ninguna repetida",
            count(array_unique(array_column($emitidas, 'matricula'))) === count($emitidas));
    }

    /*
     * Y la regla que lo sostiene: la columna ordenable viaja al SELECT SIN
     * transformar. Con `coalesce(f.saldo,0) as saldo` el cursor leería 0 y
     * compararía contra NULL, y esto es lo único que lo detecta antes de que
     * alguien lo escriba.
     */
    $sql = app('App\Reportes\Fuentes\Cartera')->consulta($global, [])->toSql();

    /*
     * Ojo al leerlo: `porMatricula()` lleva su PROPIO `coalesce` dentro de la
     * subconsulta y ése es legítimo —agrega lo aplicado—. Lo que no puede haber
     * es un `coalesce` envolviendo la columna que sale al SELECT exterior, que
     * es la que el cursor compara.
     */
    preg_match_all('/coalesce\([^)]*saldo[^)]*\)\s+as\s+`?saldo`?/i', $sql, $envuelta);

    verificar('La columna ordenable viaja al SELECT SIN envolver',
        str_contains($sql, '`f`.`saldo`') && $envuelta[0] === [],
        $envuelta[0] === [] ? 'f.saldo crudo' : 'envuelta: '.$envuelta[0][0]);

    echo PHP_EOL.'8. Los pagos bajan el saldo, y sólo los COBRADOS'.PHP_EOL;

    /*
     * El demo tiene CERO pagos, así que esto se siembra. Y se mide por
     * DIFERENCIA: afirmar «el saldo es 8250» ataría la prueba a los datos de
     * hoy y se caería el día que alguien cobre algo en la demo.
     */
    $adeudo = Adeudo::query()
        ->whereNotNull('matricula_oferta_id')
        ->whereIn('estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL])
        ->whereHas('matriculaOferta.oferta')
        ->firstOrFail();

    $matriculaDelAdeudo = $adeudo->matricula_oferta_id;
    $metodo = MetodoPago::query()->firstOrFail();

    $saldoAntes = (float) ($saldos->porMatricula($hoy, [$matriculaDelAdeudo])->first()->saldo ?? 0);

    verificar('El adeudo elegido tiene saldo antes de pagar', $saldoAntes > 0, (string) $saldoAntes);

    // Un pago PENDIENTE de confirmar: no debe bajar nada.
    $enEspera = Pago::create([
        'matricula_oferta_id' => $matriculaDelAdeudo,
        'metodo_pago_id' => $metodo->id,
        'monto' => 100.00,
        'estatus' => Pago::ESTATUS_PENDIENTE,
        'momento' => now(),
    ]);

    DB::table('pago_adeudo')->insert([
        'pago_id' => $enEspera->id,
        'adeudo_id' => $adeudo->id,
        'monto_aplicado' => 100.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saldoConEspera = (float) ($saldos->porMatricula($hoy, [$matriculaDelAdeudo])->first()->saldo ?? 0);

    verificar('Un pago PENDIENTE de confirmar no baja el saldo',
        abs($saldoConEspera - $saldoAntes) < 0.01,
        $saldoAntes.' → '.$saldoConEspera);

    // Y uno COMPLETADO sí.
    $enEspera->update(['estatus' => Pago::ESTATUS_COMPLETADO]);

    $saldoConPago = (float) ($saldos->porMatricula($hoy, [$matriculaDelAdeudo])->first()->saldo ?? 0);

    verificar('Confirmarlo SÍ baja el saldo, y por el monto exacto',
        abs(($saldoAntes - $saldoConPago) - 100.00) < 0.01,
        $saldoAntes.' → '.$saldoConPago);

    // Y el reporte lo refleja, que es lo que se vino a comprobar.
    $tras = collect($ejecutor->ejecutar($global, 'estado-de-cartera', ['columnas' => ['matricula', 'saldo']])->filas);
    $suFila = $tras->firstWhere('matricula', MatriculaOferta::find($matriculaDelAdeudo)?->matricula);

    verificar('El reporte enseña el saldo YA con el pago aplicado',
        abs((float) $suFila['saldo'] - $saldoConPago) < 0.01,
        $suFila['saldo'].' vs '.$saldoConPago);

    echo PHP_EOL.'9. Los tres reportes contestan preguntas distintas'.PHP_EOL;

    foreach (['cartera-vencida', 'estado-de-cartera', 'bloqueados-por-adeudo'] as $clave) {
        $def = $registro->definicion($clave);

        verificar("«{$def->titulo()}» dice también qué NO contesta",
            mb_strlen($def->descripcion()) > 80 && (
                str_contains($def->descripcion(), 'NO ') || str_contains($def->descripcion(), 'No ')
            ),
            mb_substr($def->descripcion(), 0, 50).'…');

        verificar("«{$def->titulo()}» nace en el área de finanzas",
            $def->areaSugerida() === 'finanzas', $def->areaSugerida());
    }

    // Y el filtro fijo de la vencida no se puede aflojar desde la petición.
    $conTrampa = collect($ejecutor->ejecutar($global, 'cartera-vencida', [
        'columnas' => ['matricula', 'vencido'],
        'filtros' => ['solo_vencido' => false],
    ])->filas);

    verificar('El filtro fijo de «vencida» gana sobre la petición',
        $conTrampa->every(fn (array $f) => (float) $f['vencido'] > 0),
        'sin vencido: '.$conTrampa->reject(fn (array $f) => (float) $f['vencido'] > 0)->count());

    echo PHP_EOL.'10. Una fuente mal declarada NO cuelga al trabajador'.PHP_EOL;

    /*
     * El cuelgue que esto vigila lo descubrió una revisión adversaria y es peor
     * que una truncadura: con `columnaSql: 'f.saldo'` y `coalesce(f.saldo, 0) as
     * saldo` en el SELECT, el cursor compara el ATRIBUTO (0) contra la COLUMNA
     * (NULL), el predicado no descarta el lote recién emitido y el recorrido
     * REPITE las mismas filas para siempre. Medido: 32 matrículas y 161 filas
     * emitidas sin señal de parar — un CSV que crece sin fin, de madrugada.
     *
     * Se construye una fuente rota A PROPÓSITO. No se puede comprobar mutando la
     * buena: la mutación cuelga la propia suite, que es exactamente lo que pasó
     * la primera vez que se intentó.
     */
    $rota = new class(app(SaldosDeCartera::class)) extends App\Reportes\Fuentes\Cartera
    {
        /** El defecto: la columna sale ENVUELTA y el ORDER BY apunta a la cruda. */
        public function consulta(Usuario $usuario, array $filtros): Illuminate\Database\Eloquent\Builder
        {
            return parent::consulta($usuario, $filtros)->select([
                'matricula_oferta.*',
                DB::raw('coalesce(f.saldo, 0) as saldo'),
            ]);
        }
    };

    /*
     * Se ata la instancia rota al contenedor: `RegistroReportes::registrarFuente`
     * resuelve con `app($clase)`, así que un registro nuevo se lleva ésta en vez
     * de la buena. Nada de esto toca al registro de la aplicación.
     */
    app()->instance(App\Reportes\Fuentes\Cartera::class, $rota);

    $registroRoto = new RegistroReportes;
    $registroRoto->registrarFuente(App\Reportes\Fuentes\Cartera::class);
    $registroRoto->registrarReporte(App\Reportes\Definiciones\EstadoDeCartera::class);

    $conRota = new class($registroRoto, app(ModulosDeLaEscuela::class)) extends Ejecutor
    {
        protected function tamanoDeLote(): int
        {
            return 5;
        }
    };

    $seDetuvo = null;
    $emitidas = 0;

    try {
        $exp = $conRota->paraExportar($global, 'estado-de-cartera', [
            'columnas' => ['matricula', 'saldo'],
            'orden_por' => 'saldo',
            'orden_dir' => 'desc',
        ]);

        foreach ($exp->recorrer() as $f) {
            $emitidas++;

            // Tope de seguridad de la PRUEBA: si el motor no se detuviera, esto
            // impide que la suite se cuelgue como pasó la primera vez.
            if ($emitidas > $exp->total * 3) {
                break;
            }
        }
    } catch (RuntimeException $e) {
        $seDetuvo = $e->getMessage();
    }

    verificar('El recorrido no avanza y el motor lo DETIENE',
        $seDetuvo !== null, $seDetuvo === null ? "siguió, {$emitidas} filas emitidas" : 'se detuvo');

    verificar('Y el error dice qué arreglar, no sólo que falló',
        $seDetuvo !== null && str_contains($seDetuvo, 'sin transformar'),
        mb_substr((string) $seDetuvo, 0, 70).'…');

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
