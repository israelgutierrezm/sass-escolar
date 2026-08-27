<?php

/**
 * La fuente de PLANTILLA LABORAL. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-rh.php` desde la raíz.
 *
 * ── El módulo está VACÍO en el demo ───────────────────────────────────────
 * Cero expedientes, cero adscripciones, cero esquemas, cero recibos y cero
 * checadas: la nómina se construyó y nunca se ejercitó con datos en esta
 * escuela. Así que esta suite siembra su escenario COMPLETO dentro de la
 * transacción — no hay contra qué comparar de otra forma.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **A quién se le paga lo dice una BANDERA, no la clave.** Licencia SIN
 *     goce sigue contratado y no cobra; comisión sí cobra. Preguntar por
 *     `clave = 'activo'` se equivoca en los dos casos, y ninguno se notaría
 *     hasta el día de pago.
 *  2. **«Baja» tiene UNA sola fuente de verdad: `fecha_baja`.** Por eso el
 *     catálogo no siembra ninguna situación de baja.
 *  3. **El grano no se multiplica**: quien cambió tres veces de puesto sigue
 *     siendo un empleado, y se enseña su adscripción VIGENTE.
 *  4. **El sueldo NO está en esta fuente.** `gestionar-rh` deja llevar
 *     expedientes; los importes viven detrás de `gestionar-percepciones`, que
 *     es otro permiso. Una columna de sueldo aquí regalaría por la puerta de
 *     atrás lo que el módulo separó en dos rutas.
 *  5. La antigüedad de quien se fue NO sigue creciendo.
 */

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\SituacionEmpleado;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
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
        'primer_apellido' => 'RH',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_rh_'.random_int(100000, 999999),
        'email' => 'prueba_rh_'.random_int(100000, 999999).'@ejemplo.mx',
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

/** Un expediente sembrado, con su adscripción. */
function contratar(string $nombre, array $opciones = []): ExpedienteLaboral
{
    $persona = Persona::create([
        'nombre' => $nombre,
        'primer_apellido' => 'Empleado',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
        'curp' => 'XXXX'.random_int(1000000000000, 9999999999999).'X',
    ]);

    $expediente = ExpedienteLaboral::create([
        'persona_id' => $persona->id,
        'numero_empleado' => 'EMP-'.random_int(10000, 99999),
        'tipo_contrato_id' => DB::table('tipos_contrato')->value('id'),
        'situacion_id' => $opciones['situacion_id'] ?? SituacionEmpleado::query()->where('entra_a_nomina', true)->value('id'),
        'fecha_ingreso' => $opciones['ingreso'] ?? now()->subYears(3)->toDateString(),
        'fecha_baja' => $opciones['baja'] ?? null,
        'motivo_baja_id' => ($opciones['baja'] ?? null) !== null ? DB::table('motivos_baja_laboral')->value('id') : null,
    ]);

    foreach ($opciones['adscripciones'] ?? [['principal' => true]] as $a) {
        DB::table('adscripciones')->insert([
            'expediente_laboral_id' => $expediente->id,
            'puesto_id' => $a['puesto_id'] ?? DB::table('puestos')->value('id'),
            'campus_id' => $a['campus_id'] ?? DB::table('campus')->value('id'),
            'vigente_desde' => $a['desde'] ?? now()->subYears(3)->toDateString(),
            'vigente_hasta' => $a['hasta'] ?? null,
            'es_principal' => $a['principal'] ?? false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $expediente->fresh();
}

DB::beginTransaction();

try {
    $ejecutor = app(Ejecutor::class);
    $registro = app(RegistroReportes::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. El módulo está vacío: se siembra su escenario'.PHP_EOL;

    $base = DB::table('expedientes_laborales')->whereNull('deleted_at')->count();

    verificar('El demo no tiene expedientes laborales', $base === 0, $base.' expedientes');

    $enNomina = SituacionEmpleado::query()->where('entra_a_nomina', true)->first();
    $sinGoce = SituacionEmpleado::query()->where('entra_a_nomina', false)->first();

    verificar('El catálogo distingue quién cobra y quién no',
        $enNomina !== null && $sinGoce !== null,
        ($enNomina?->nombre ?? '?').' cobra / '.($sinGoce?->nombre ?? '?').' no');

    $activo = contratar('Activa', ['situacion_id' => $enNomina->id]);
    $licencia = contratar('Licencia', ['situacion_id' => $sinGoce->id]);
    $dadoDeBaja = contratar('Baja', [
        'situacion_id' => $enNomina->id,
        'ingreso' => now()->subYears(5)->toDateString(),
        'baja' => now()->subYear()->toDateString(),
    ]);

    /*
     * Y uno en COMISIÓN: entra a nómina y su clave NO es «activo».
     *
     * Sin él, preguntar por la bandera y preguntar por `clave = 'activo'` dan lo
     * mismo en este demo, y la regla que separa las dos cosas pasaba sin
     * comprobarse. Se vio mutando. En una escuela real es el caso de quien está
     * comisionado a otra sede y cobra igual.
     */
    $comisionado = contratar('Comisionada', [
        'situacion_id' => SituacionEmpleado::query()->where('clave', 'comision')->value('id'),
    ]);

    /*
     * Y uno cuya ÚNICA adscripción ya está cerrada: sigue contratado pero no
     * ocupa ningún puesto hoy. Sin él, quitar el filtro de vigencia no cambiaba
     * ninguna fila —el orden por fecha ya devolvía la más reciente— y la regla
     * tampoco se comprobaba.
     */
    $sinPuestoHoy = contratar('SinPuesto', [
        'situacion_id' => $enNomina->id,
        'adscripciones' => [[
            'desde' => now()->subYears(3)->toDateString(),
            'hasta' => now()->subMonths(2)->toDateString(),
            'principal' => true,
        ]],
    ]);

    verificar('Se sembraron cinco expedientes',
        DB::table('expedientes_laborales')->whereNull('deleted_at')->count() === 5,
        (string) DB::table('expedientes_laborales')->whereNull('deleted_at')->count());

    echo PHP_EOL.'2. A quién se le PAGA lo dice la bandera, no la clave'.PHP_EOL;

    $vigentes = collect($ejecutor->ejecutar($global, 'plantilla-vigente', [
        'columnas' => ['numero_empleado', 'empleado', 'situacion', 'cobra'],
    ])->filas);

    verificar('El que está de licencia SÍ aparece como contratado',
        $vigentes->contains('numero_empleado', $licencia->numero_empleado),
        $vigentes->count().' vigentes');

    $aCobrar = collect($ejecutor->ejecutar($global, 'quien-entra-a-nomina', [
        'columnas' => ['numero_empleado', 'empleado', 'situacion', 'cobra'],
    ])->filas);

    verificar('Pero NO entra a nómina',
        ! $aCobrar->contains('numero_empleado', $licencia->numero_empleado),
        $aCobrar->count().' a cobrar');

    verificar('Y el activo sí',
        $aCobrar->contains('numero_empleado', $activo->numero_empleado));

    verificar('Las dos listas son DISTINTAS (si no, la prueba sería vacua)',
        $vigentes->count() !== $aCobrar->count(),
        $vigentes->count().' contratados vs '.$aCobrar->count().' a cobrar');

    /*
     * Y el COMISIONADO cobra aunque su clave no sea «activo». Es lo que separa
     * leer la bandera de leer la clave, y sin este caso las dos daban lo mismo.
     */
    verificar('El comisionado SÍ entra a nómina, aunque su clave no sea «activo»',
        $aCobrar->contains('numero_empleado', $comisionado->numero_empleado),
        SituacionEmpleado::find($comisionado->situacion_id)?->clave);

    echo PHP_EOL.'2b. Quien no tiene adscripción vigente no ocupa puesto'.PHP_EOL;

    $suyaSinPuesto = $vigentes->firstWhere('numero_empleado', $sinPuestoHoy->numero_empleado);

    verificar('Sigue contratado', $suyaSinPuesto !== null);

    $conPuesto = collect($ejecutor->ejecutar($global, 'plantilla-vigente', [
        'columnas' => ['numero_empleado', 'puesto', 'campus'],
    ])->filas)->firstWhere('numero_empleado', $sinPuestoHoy->numero_empleado);

    verificar('Pero su puesto va en BLANCO: la adscripción que tuvo ya cerró',
        $conPuesto !== null && $conPuesto['puesto'] === null,
        $conPuesto === null ? 'no salió' : var_export($conPuesto['puesto'], true));

    echo PHP_EOL.'3. «Baja» sale de `fecha_baja` y de nada más'.PHP_EOL;

    verificar('El dado de baja NO sale entre los vigentes',
        ! $vigentes->contains('numero_empleado', $dadoDeBaja->numero_empleado));

    // Y su situación SIGUE siendo una que cobra: es lo que hace la prueba
    // significativa —si se leyera la situación, saldría—.
    verificar('Aunque su SITUACIÓN siga siendo una que entra a nómina',
        (bool) SituacionEmpleado::find($dadoDeBaja->situacion_id)?->entra_a_nomina,
        SituacionEmpleado::find($dadoDeBaja->situacion_id)?->nombre);

    verificar('Tampoco entra a nómina',
        ! $aCobrar->contains('numero_empleado', $dadoDeBaja->numero_empleado));

    echo PHP_EOL.'4. La antigüedad de quien se fue NO sigue creciendo'.PHP_EOL;

    // Se pide por el reporte de plantilla AFLOJANDO su filtro fijo: aqui es
    // legitimo, porque lo que se mide es el calculo de la antiguedad y no la
    // lista. El reporte de verdad no deja aflojarlo — eso lo vigila otra suite.
    $conBajas = collect($ejecutor->ejecutar($global, 'bajas-de-personal', [
        'columnas' => ['numero_empleado', 'fecha_ingreso', 'fecha_baja', 'antiguedad_anios', 'motivo_baja'],
    ])->filas);

    $suya = $conBajas->firstWhere('numero_empleado', $dadoDeBaja->numero_empleado);

    // Ingresó hace 5 años y se fue hace 1: su antigüedad son 4, no 5.
    verificar('Su antigüedad se cuenta hasta la BAJA, no hasta hoy',
        $suya !== null && abs((float) $suya['antiguedad_anios'] - 4.0) < 0.2,
        $suya === null ? 'no salió' : $suya['antiguedad_anios'].' años (esperado ~4)');

    verificar('Y quien sigue contratado NO sale en las bajas',
        ! $conBajas->contains('numero_empleado', $activo->numero_empleado));

    $delActivo = $vigentes->firstWhere('numero_empleado', $activo->numero_empleado);

    $conAntiguedad = collect($ejecutor->ejecutar($global, 'plantilla-vigente', [
        'columnas' => ['numero_empleado', 'antiguedad_anios'],
    ])->filas)->firstWhere('numero_empleado', $activo->numero_empleado);

    verificar('Y la de quien sigue contratado se cuenta hasta HOY',
        $conAntiguedad !== null && abs((float) $conAntiguedad['antiguedad_anios'] - 3.0) < 0.2,
        ($conAntiguedad['antiguedad_anios'] ?? 'null').' años (esperado ~3)');

    echo PHP_EOL.'5. El grano no se multiplica con varias adscripciones'.PHP_EOL;

    /*
     * `adscripciones` es a-muchos: quien cambió de puesto tiene una por tramo.
     * Con un join en crudo saldría una vez por adscripción y «tres empleados»
     * donde hay uno.
     */
    $puestos = DB::table('puestos')->orderBy('orden')->pluck('id');

    verificar('Hay al menos dos puestos en el catálogo', $puestos->count() >= 2, $puestos->count().' puestos');

    $conVarias = contratar('Ascendida', [
        'situacion_id' => $enNomina->id,
        'adscripciones' => [
            // La vieja, ya cerrada.
            ['puesto_id' => $puestos[0], 'desde' => now()->subYears(3)->toDateString(),
                'hasta' => now()->subYear()->toDateString(), 'principal' => true],
            // La vigente.
            ['puesto_id' => $puestos[1], 'desde' => now()->subYear()->toDateString(),
                'hasta' => null, 'principal' => true],
        ],
    ]);

    $trasAscender = collect($ejecutor->ejecutar($global, 'plantilla-vigente', [
        'columnas' => ['numero_empleado', 'empleado', 'puesto'],
    ])->filas);

    $suyas = $trasAscender->where('numero_empleado', $conVarias->numero_empleado);

    verificar('Con DOS adscripciones sale UNA sola fila',
        $suyas->count() === 1, $suyas->count().' filas');

    $puestoVigente = DB::table('puestos')->where('id', $puestos[1])->value('nombre');
    $puestoViejo = DB::table('puestos')->where('id', $puestos[0])->value('nombre');

    // Si los dos puestos se llamaran igual, la comprobación de abajo pasaría
    // enseñando el equivocado.
    verificar('Los dos puestos tienen nombres distintos (si no, sería vacua)',
        $puestoVigente !== $puestoViejo, $puestoViejo.' → '.$puestoVigente);

    verificar('Y enseña el puesto VIGENTE, no el que dejó',
        ($suyas->first()['puesto'] ?? null) === $puestoVigente,
        ($suyas->first()['puesto'] ?? 'null').' vs '.$puestoVigente);

    echo PHP_EOL.'6. El SUELDO no está en esta fuente'.PHP_EOL;

    /*
     * Es la decisión que gobierna el módulo: `gestionar-rh` deja llevar
     * expedientes y `gestionar-percepciones` es OTRO permiso, con su propia
     * ruta. Una columna de sueldo aquí —aunque fuera `sensible`— regalaría por
     * la puerta de atrás lo que el módulo separó a propósito.
     */
    $columnas = array_keys($registro->fuente('plantilla-laboral')->columnas());

    $deDinero = array_filter($columnas, fn (string $c) => str_contains($c, 'sueldo')
        || str_contains($c, 'salario') || str_contains($c, 'percepcion') || str_contains($c, 'neto'));

    verificar('Ninguna columna de la plantilla habla de dinero',
        $deDinero === [], implode(', ', $deDinero));

    verificar('Y la fuente cuelga de `gestionar-rh`, no del permiso de sueldos',
        $registro->fuente('plantilla-laboral')->permiso() === 'gestionar-rh',
        $registro->fuente('plantilla-laboral')->permiso());

    echo PHP_EOL.'7. El recorte va por la ADSCRIPCIÓN'.PHP_EOL;

    $campusA = DB::table('campus')->orderBy('id')->value('id');
    $campusB = DB::table('campus')->orderBy('id', 'desc')->value('id');

    verificar('Hay dos campus distintos', $campusA !== $campusB, $campusA.' y '.$campusB);

    $delOtro = contratar('DeOtroCampus', [
        'situacion_id' => $enNomina->id,
        'adscripciones' => [['campus_id' => $campusB, 'principal' => true]],
    ]);

    $acotado = usuarioConRol('director_general', $campusA);
    auth()->login($acotado);

    $suyos = collect($ejecutor->ejecutar($acotado, 'plantilla-vigente', [
        'columnas' => ['numero_empleado', 'campus'],
    ])->filas);

    verificar('El acotado NO ve al empleado del otro campus',
        ! $suyos->contains('numero_empleado', $delOtro->numero_empleado),
        $suyos->count().' visibles');

    auth()->login($global);

    $globales = collect($ejecutor->ejecutar($global, 'plantilla-vigente', ['columnas' => ['numero_empleado']])->filas);

    verificar('Y el global sí lo ve',
        $globales->contains('numero_empleado', $delOtro->numero_empleado),
        $suyos->count().' de '.$globales->count());

    /*
     * ── «SU adscripción» es UNA sola definición ───────────────────────────
     *
     * Estaba escrita tres veces —el recorte por campus, los filtros de campus y
     * de puesto, y la subconsulta que pinta las columnas— y las tres divergían.
     * Lo cazó una revisión adversaria y aquí queda vigilado, porque los tres
     * defectos eran silenciosos: ninguno da error, dan otra fila.
     */
    echo PHP_EOL.'9. «Su adscripción»: una sola definición para los tres consumidores'.PHP_EOL;

    $campusA = DB::table('campus')->whereNull('deleted_at')->orderBy('id')->value('id');
    $campusB = DB::table('campus')->whereNull('deleted_at')->where('id', '!=', $campusA)->orderBy('id')->value('id');
    $puestoA = DB::table('puestos')->orderBy('id')->value('id');
    $puestoB = DB::table('puestos')->where('id', '!=', $puestoA)->orderBy('id')->value('id');

    verificar('Hay dos campus y dos puestos con los que construir el caso',
        $campusB !== null && $puestoB !== null);

    /*
     * SE MUDÓ: cerrada en A, abierta en B. Es el caso que el demo no tiene
     * —cero adscripciones— y sin el cual las tres reglas se cumplen solas.
     */
    $mudado = contratar('Mudado', ['adscripciones' => [
        ['campus_id' => $campusA, 'puesto_id' => $puestoA, 'desde' => '2020-01-01', 'hasta' => '2023-12-31', 'principal' => true],
        ['campus_id' => $campusB, 'puesto_id' => $puestoB, 'desde' => '2024-01-01', 'principal' => true],
    ]]);

    /* SE FUE: la baja CIERRA la adscripción el mismo día, como hace `darDeBaja`. */
    $ido = contratar('Ido', [
        'baja' => '2026-03-31',
        'adscripciones' => [
            ['campus_id' => $campusB, 'puesto_id' => $puestoB, 'desde' => '2021-01-01', 'hasta' => '2026-03-31', 'principal' => true],
        ],
    ]);

    $coordA = usuarioConRol('director_general', $campusA);
    $coordB = usuarioConRol('director_general', $campusB);

    $corre = function (Usuario $quien, string $reporte, array $filtros = []) use ($ejecutor): Collection {
        auth()->login($quien);

        return collect($ejecutor->ejecutar($quien, $reporte, [
            'columnas' => ['numero_empleado', 'empleado', 'puesto', 'campus'],
            'filtros' => $filtros,
        ])->filas);
    };

    $tiene = fn (Collection $filas, ExpedienteLaboral $e) => $filas
        ->contains(fn (array $f) => $f['numero_empleado'] === $e->numero_empleado);

    // (a) el RECORTE
    verificar('El coordinador de donde ya NO trabaja no lo ve',
        ! $tiene($corre($coordA, 'plantilla-vigente'), $mudado),
        'campus '.$campusA);

    verificar('Y el de donde trabaja HOY sí',
        $tiene($corre($coordB, 'plantilla-vigente'), $mudado),
        'campus '.$campusB);

    // (b) los FILTROS: la fila no puede contradecirse a sí misma
    auth()->login($global);
    $porCampusViejo = $corre($global, 'plantilla-vigente', ['campus_id' => [$campusA]]);

    verificar('Filtrar por el campus VIEJO no lo trae',
        ! $tiene($porCampusViejo, $mudado),
        $porCampusViejo->count().' filas');

    $porPuestoViejo = $corre($global, 'plantilla-vigente', ['puesto_id' => [$puestoA]]);

    verificar('Filtrar por el puesto que ya NO ocupa no lo trae',
        ! $tiene($porPuestoViejo, $mudado),
        $porPuestoViejo->count().' filas');

    $porCampusNuevo = $corre($global, 'plantilla-vigente', ['campus_id' => [$campusB]]);
    $fila = $porCampusNuevo->firstWhere('numero_empleado', $mudado->numero_empleado);

    verificar('Y la fila que el filtro devuelve dice lo mismo que el filtro',
        $fila !== null && $fila['campus'] === DB::table('campus')->where('id', $campusB)->value('nombre'),
        $fila === null ? 'no salió' : 'campus: '.$fila['campus']);

    // (c) la SUBCONSULTA: una baja tiene puesto y campus
    $bajas = $corre($global, 'bajas-de-personal');
    $filaIdo = $bajas->firstWhere('numero_empleado', $ido->numero_empleado);

    verificar('Una BAJA sale con su puesto y su campus, no en blanco',
        $filaIdo !== null && $filaIdo['puesto'] !== null && $filaIdo['campus'] !== null,
        $filaIdo === null ? 'no salió' : 'puesto: '.($filaIdo['puesto'] ?? '—').', campus: '.($filaIdo['campus'] ?? '—'));

    verificar('Y el coordinador de donde trabajaba puede verla',
        $tiene($corre($coordB, 'bajas-de-personal'), $ido),
        'campus '.$campusB);

    verificar('Mientras el del otro campus no',
        ! $tiene($corre($coordA, 'bajas-de-personal'), $ido));

    auth()->login($global);

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
