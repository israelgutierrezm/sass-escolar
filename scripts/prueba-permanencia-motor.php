<?php

/**
 * Proveedores de señales y motor de evaluación (fase 2). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-motor.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **LA PROHIBICIÓN DURA**: el motor corre entero y no cambia una sola fila
 *     de `matricula_oferta`, `inscripcion`, `historial`, `asistencia_clase`,
 *     `adeudos` ni de las situaciones. Reporta. La tentación es real —la
 *     situación `condicionado` existe y nadie la usa— y una sanción decidida por
 *     un cron de madrugada sobre un dato mal capturado es lo peor que este
 *     módulo podría hacer.
 *  2. **Los TRES resultados**: dispara, no dispara y `sin_datos`. La tercera es
 *     la que impide que media escuela salga en rojo el día que un docente se
 *     enferma.
 *  3. **La deduplicación y el enfriamiento**, que es lo que separa una cola que
 *     se mira de una que se ignora.
 *  4. **Resuelta ≠ obsoleta**: la primera dice que mejoró, la segunda que se
 *     dejó de vigilar.
 *  5. **La categoría reservada no filtra el detalle**, y el proveedor financiero
 *     no devuelve importes ni en la evidencia.
 *  6. **La guarda ruidosa**: ninguna métrica declarada se queda sin proveedor.
 */

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\ExclusionReglaAlerta;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\Tenant;
use App\Permanencia\CatalogoMetricas;
use App\Permanencia\RegistroProveedores;
use App\Services\Permanencia\MotorDeEvaluacion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$db = DB::connection('tenant');

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;

    $verificaciones++;
    $ok || $fallidas++;

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function usuarioConRol(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Motor',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_mot_'.random_int(100000, 999999),
        'email' => 'prueba_mot_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);

    $cuenta->persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true, 'campus_id' => null]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Una foto de las tablas que el motor NO debe tocar. */
function huellaDeLoIntocable(): array
{
    $tablas = ['matricula_oferta', 'inscripcion', 'historial', 'asistencia_clase',
        'adeudos', 'situaciones_alumno', 'bitacora_situacion_financiera'];

    $huella = [];

    foreach ($tablas as $t) {
        $huella[$t] = [
            'filas' => DB::table($t)->count(),
            // El `updated_at` más reciente: una fila MODIFICADA no cambia el
            // conteo, así que contar no basta. Es la trampa de siempre.
            'ultimo' => DB::table($t)->max('updated_at'),
        ];
    }

    return $huella;
}

const PREFIJO = 'ZZMOT-';

$db->beginTransaction();

try {
    $global = usuarioConRol('director_general');
    auth()->login($global);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $motor = app(MotorDeEvaluacion::class);
    $registro = app(RegistroProveedores::class);

    echo '1. LA GUARDA RUIDOSA: ninguna métrica sin quien la calcule'.PHP_EOL;

    verificar('Hay seis proveedores registrados', count($registro->todos()) === 6,
        implode(', ', array_keys($registro->todos())));

    /*
     * Comparar contra `[]` era VACUO: una implementación que devolviera siempre
     * el arreglo vacío pasaba igual, y ésa es justo la forma en que esta guarda
     * dejaría de servir. Se cruzan las DOS listas.
     */
    $calculadas = collect($registro->todos())->flatMap(fn ($pr) => $pr->metricas())->sort()->values();
    $declaradas = collect(CatalogoMetricas::claves())->sort()->values();

    verificar('Cada métrica declarada la calcula un proveedor, y al revés',
        $calculadas->all() === $declaradas->all(),
        'calculadas '.$calculadas->count().' · declaradas '.$declaradas->count()
        .' · sobran: '.$calculadas->diff($declaradas)->implode(', ')
        .' · faltan: '.$declaradas->diff($calculadas)->implode(', '));

    verificar('Y `metricasSinProveedor` lo confirma',
        $registro->metricasSinProveedor() === [],
        implode(', ', $registro->metricasSinProveedor()));

    /*
     * Y la guarda DETECTA de verdad: se arma un registro al que le falta un
     * proveedor. Sin este caso, una implementación que devolviera siempre el
     * arreglo vacío pasaba —que es exactamente cómo esta guarda dejaría de
     * servir sin que nadie lo notara—.
     */
    $incompleto = new RegistroProveedores;
    $incompleto->registrar($registro->de('asistencia'));

    verificar('Con un registro incompleto, la guarda NOMBRA lo que falta',
        $incompleto->metricasSinProveedor() !== []
        && in_array('academico.promedio', $incompleto->metricasSinProveedor(), true),
        implode(', ', array_slice($incompleto->metricasSinProveedor(), 0, 3)));

    /*
     * Y registrar un proveedor que dice calcular una métrica ajena REVIENTA al
     * arrancar: es lo que impide que una métrica quede apuntando al proveedor
     * equivocado y no se calcule nunca.
     */
    $mentiroso = new class implements App\Permanencia\Proveedores\ProveedorDeSenales
    {
        public function clave(): string
        {
            return 'inventado';
        }

        public function titulo(): string
        {
            return 'Inventado';
        }

        public function calidad(): string
        {
            return 'Ninguna: existe sólo para la prueba.';
        }

        public function modulo(): ?string
        {
            return null;
        }

        public function metricas(): array
        {
            return ['asistencia.porcentaje'];
        }

        public function ultimaActualizacion(): ?string
        {
            return null;
        }

        public function medir($matricula, string $metrica, $version): array
        {
            return [];
        }
    };

    $rechazo = null;

    try {
        (new RegistroProveedores)->registrar($mentiroso);
    } catch (InvalidArgumentException $e) {
        $rechazo = $e->getMessage();
    }

    verificar('Un proveedor que reclama una métrica ajena se rechaza al arrancar',
        $rechazo !== null && str_contains($rechazo, 'asistencia.porcentaje'),
        (string) $rechazo);

    verificar('Y cada proveedor declara su calidad, en prosa y no vacía',
        collect($registro->todos())->every(fn ($p) => mb_strlen($p->calidad()) > 40),
        collect($registro->todos())->reject(fn ($p) => mb_strlen($p->calidad()) > 40)
            ->keys()->implode(', ') ?: 'todos');

    echo PHP_EOL.'2. `ABIERTOS` en PHP contra el SQL de la columna generada'.PHP_EOL;

    /*
     * La lista de estados vivos está escrita DOS veces —en `Alerta::ABIERTOS` y
     * en el SQL de `clave_dedup`, que lo evalúa MySQL— y sin quien las compare
     * se separan el día que se agregue un estado: el único empezaría a permitir
     * o impedir lo que no debe, sin fallar. Es la lección de
     * `expedientes_proceso.tipo_si_cuenta`.
     */
    $expresion = DB::table('information_schema.columns')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', 'alertas')
        ->where('column_name', 'clave_dedup')
        ->value('GENERATION_EXPRESSION');

    // MySQL escapa las comillas en `GENERATION_EXPRESSION`; sin normalizar, la
    // comprobación pasaría por la razón equivocada.
    $normalizada = str_replace(["\\'", '_utf8mb4'], ["'", ''], (string) $expresion);

    $enElSql = [];

    foreach (['activa', 'resuelta', 'obsoleta'] as $estado) {
        str_contains($normalizada, "'".$estado."'") && $enElSql[] = $estado;
    }

    verificar('Los estados vivos del SQL son exactamente los de PHP',
        $enElSql === Alerta::ABIERTOS,
        'SQL: '.implode(',', $enElSql).' · PHP: '.implode(',', Alerta::ABIERTOS));

    echo PHP_EOL.'3. El escenario: una alumna con faltas de verdad'.PHP_EOL;

    // Se parte de cero: lo que se prueba es aritmética de alertas, y eso sólo se
    // puede afirmar sabiendo qué hay. Novena vez que este proyecto lo paga.
    Alerta::query()->forceDelete();
    // El riesgo compuesto apunta a la corrida, así que va primero: la fase 4
    // agregó esa foránea y sin este orden el borrado revienta con un 1451.
    DB::table('riesgo_matricula')->delete();
    DB::table('corridas_evaluacion')->delete();
    ReglaAlertaVersion::query()->forceDelete();
    ReglaAlerta::query()->forceDelete();

    $inscripcion = DB::table('inscripcion')
        ->whereNull('deleted_at')
        ->whereNotNull('asignatura_grupo_id')
        ->first();

    verificar('Hay una inscripción con la que construir el escenario', $inscripcion !== null);

    $matricula = MatriculaOferta::query()->with('oferta')->findOrFail($inscripcion->matricula_oferta_id);

    // Diez sesiones: seis presentes y cuatro faltas seguidas al final.
    // Se construye porque el demo tiene 8 filas para 17 inscripciones: sin
    // sembrar, todas las reglas de asistencia saldrían `sin_datos` y la suite
    // mediría el camino que no interesa.
    foreach (range(1, 10) as $i) {
        AsistenciaClase::create([
            'inscripcion_id' => $inscripcion->id,
            'fecha' => CarbonImmutable::now()->subDays(11 - $i)->toDateString(),
            'modalidad' => 'teoria',
            'estatus' => $i <= 6 ? AsistenciaClase::PRESENTE : AsistenciaClase::FALTA,
        ]);
    }

    $categoria = CategoriaSenal::query()->where('clave', 'asistencia')->firstOrFail();
    $reservada = CategoriaSenal::query()->where('clave', 'financiera')->firstOrFail();

    $crearRegla = function (array $regla, array $version) {
        $r = ReglaAlerta::create($regla + ['activa' => true]);
        $r->versiones()->create($version + [
            'version' => 1,
            'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
            'frecuencia' => 'diaria',
        ]);

        return $r->fresh('versiones');
    };

    $faltas = $crearRegla([
        'nombre' => PREFIJO.'Faltas seguidas',
        'categoria_id' => $categoria->id,
        'proveedor' => 'asistencia',
    ], [
        'metrica' => 'asistencia.faltas_consecutivas',
        'comparador' => '>=',
        'umbral' => 3,
        'ventana_tipo' => 'ciclo',
        'cobertura_minima' => 3,
        'severidad' => 'medio',
        'peso' => 2,
        'cooldown_dias' => 14,
    ]);

    echo PHP_EOL.'4. LA PROHIBICIÓN DURA: el motor no escribe en el expediente'.PHP_EOL;

    $antes = huellaDeLoIntocable();

    $corrida = $motor->correr();

    $despues = huellaDeLoIntocable();

    verificar('El motor corrió y evaluó a alguien',
        $corrida->matriculas_evaluadas > 0, (string) $corrida->matriculas_evaluadas);

    verificar('Y NO cambió una sola fila de matrículas, historial, asistencia ni cartera',
        $antes === $despues,
        collect($antes)->filter(fn ($v, $k) => $v !== $despues[$k])->keys()->implode(', ') ?: 'ninguna');

    echo PHP_EOL.'5. La alerta se levantó, y EXPLICA por qué'.PHP_EOL;

    $alerta = Alerta::query()->where('regla_id', $faltas->id)->first();

    verificar('Se levantó una alerta', $alerta !== null,
        (string) Alerta::query()->count().' alertas en total');

    verificar('Con el valor observado y el umbral',
        (float) $alerta?->valor_observado === 4.0 && (float) $alerta?->umbral === 3.0,
        $alerta?->valor_observado.' contra '.$alerta?->umbral);

    verificar('Y la MATERIA, porque el derecho se pierde materia por materia',
        $alerta?->asignatura_grupo_id === $inscripcion->asignatura_grupo_id);

    verificar('La evidencia dice qué se contó',
        isset($alerta->evidencia['sesiones_registradas'], $alerta->evidencia['faltas'],
            $alerta->evidencia['fuente']),
        implode(', ', array_keys($alerta?->evidencia ?? [])));

    verificar('Y con qué regla y versión se generó',
        ($alerta->evidencia['condicion'] ?? null) === $faltas->versiones->first()->comoSeLee()
        && $alerta->regla_version_id === $faltas->versiones->first()->id,
        (string) ($alerta->evidencia['condicion'] ?? 'sin condición'));

    verificar('Nace NUEVA y ACTIVA: nadie la ha mirado y sigue siendo cierta',
        $alerta?->estado_triage === Alerta::NUEVA && $alerta?->estado_senal === Alerta::ACTIVA);

    echo PHP_EOL.'6. La DEDUPLICACIÓN: dos corridas, una alerta'.PHP_EOL;

    $motor->correr();
    $motor->correr();

    verificar('Sigue habiendo UNA sola alerta de esta regla',
        Alerta::query()->where('regla_id', $faltas->id)->count() === 1,
        (string) Alerta::query()->where('regla_id', $faltas->id)->count());

    $recargada = $alerta->fresh();

    verificar('Y se ACTUALIZÓ su última evaluación en vez de crear otra',
        $recargada->ultima_evaluacion_en >= $recargada->primera_vez_en);

    echo PHP_EOL.'7. SIN DATOS no es un cero'.PHP_EOL;

    $conInscripcion = DB::table('inscripcion')->whereNull('deleted_at')
        ->pluck('matricula_oferta_id')->unique();

    $sinLista = MatriculaOferta::query()
        ->whereHas('oferta')
        ->whereKeyNot($matricula->id)
        ->whereNotIn('id', $conInscripcion)
        ->first();

    if ($sinLista === null) {
        // Se CONSTRUYE: sin el caso, «sin datos» y «no dispara» darían lo mismo.
        $sinLista = MatriculaOferta::query()->whereHas('oferta')->whereKeyNot($matricula->id)->firstOrFail();
        DB::table('inscripcion')->where('matricula_oferta_id', $sinLista->id)->update(['deleted_at' => now()]);
    }

    verificar('A quien no tiene lista pasada NO se le levanta alerta',
        ! Alerta::query()->where('matricula_oferta_id', $sinLista->id)
            ->where('regla_id', $faltas->id)->exists());

    verificar('Y la corrida lo cuenta como «sin datos», no como «no dispara»',
        $corrida->sin_datos > 0, (string) $corrida->sin_datos);

    /*
     * Y la COBERTURA MÍNIMA: con una sola sesión registrada no se opina, aunque
     * esa sesión sea una falta. Es el caso que impide que un alumno con una
     * falta salga con «0 % de asistencia».
     */
    $pocaLista = DB::table('inscripcion')->whereNull('deleted_at')
        ->where('id', '!=', $inscripcion->id)->whereNotNull('asignatura_grupo_id')->first();

    if ($pocaLista !== null) {
        /*
         * TRES faltas seguidas —que SÍ cruzan el umbral— sobre una cobertura de
         * 3... y la regla pide 3, así que se ponen DOS: el valor cruzaría si el
         * umbral fuera menor, pero lo que se prueba es la cobertura. Con una
         * sola sesión, el valor tampoco cruzaba y la comprobación pasaba por la
         * razón equivocada: quitarle la cobertura al motor no cambiaba nada.
         *
         * Se construye el caso que las separa: una regla con umbral 2 (que las
         * dos faltas SÍ cruzan) y cobertura mínima 5 (que 2 sesiones NO
         * alcanzan).
         */
        foreach ([1, 2] as $dia) {
            AsistenciaClase::create([
                'inscripcion_id' => $pocaLista->id,
                'fecha' => CarbonImmutable::now()->subDays($dia)->toDateString(),
                'modalidad' => 'teoria',
                'estatus' => AsistenciaClase::FALTA,
            ]);
        }

        $exigente = $crearRegla([
            'nombre' => PREFIJO.'Cobertura exigente',
            'categoria_id' => $categoria->id,
            'proveedor' => 'asistencia',
        ], [
            'metrica' => 'asistencia.faltas_consecutivas',
            'comparador' => '>=',
            'umbral' => 2,
            'ventana_tipo' => 'ciclo',
            'cobertura_minima' => 5,
            'severidad' => 'bajo',
            'peso' => 1,
            'cooldown_dias' => 0,
        ]);

        $motor->correr();

        verificar('El valor SÍ cruzaría (2 faltas >= 2), pero la cobertura no alcanza',
            ! Alerta::query()
                ->where('matricula_oferta_id', $pocaLista->matricula_oferta_id)
                // Por MATERIA: `pocaLista` puede ser otra materia de la misma
                // matrícula del escenario, que sí tiene sesiones de sobra.
                ->where('asignatura_grupo_id', $pocaLista->asignatura_grupo_id)
                ->where('regla_id', $exigente->id)->exists());

        // Y con la cobertura al alcance de las dos sesiones, SÍ se levanta: es
        // lo que demuestra que lo que faltaba era la cobertura y no otra cosa.
        $exigente->versiones->first()->update(['cobertura_minima' => 2]);
        $motor->correr();

        verificar('Bajando la cobertura a 2, la misma señal SÍ se levanta',
            Alerta::query()
                ->where('matricula_oferta_id', $pocaLista->matricula_oferta_id)
                ->where('asignatura_grupo_id', $pocaLista->asignatura_grupo_id)
                ->where('regla_id', $exigente->id)->exists());

        Alerta::query()->where('regla_id', $exigente->id)->forceDelete();
        $exigente->versiones()->forceDelete();
        $exigente->forceDelete();
    }

    echo PHP_EOL.'8. Cuando MEJORA, se resuelve con la evidencia de la mejora'.PHP_EOL;

    // Las cuatro faltas del final pasan a presentes: la señal deja de ser cierta.
    AsistenciaClase::query()
        ->where('inscripcion_id', $inscripcion->id)
        ->where('estatus', AsistenciaClase::FALTA)
        ->update(['estatus' => AsistenciaClase::PRESENTE]);

    $motor->correr();

    $resuelta = $alerta->fresh();

    verificar('Pasa a RESUELTA, no a obsoleta',
        $resuelta->estado_senal === Alerta::RESUELTA, (string) $resuelta->estado_senal);

    verificar('Con la fecha de cierre', $resuelta->cerrada_en !== null);

    verificar('Y con la evidencia de la MEJORA, no un cierre mudo',
        isset($resuelta->evidencia_cierre['valor_al_cerrar'])
        && (float) $resuelta->evidencia_cierre['valor_al_cerrar'] === 0.0,
        json_encode($resuelta->evidencia_cierre['valor_al_cerrar'] ?? null));

    echo PHP_EOL.'9. El ENFRIAMIENTO impide el rebote'.PHP_EOL;

    // Vuelven las faltas: la situación reaparece dentro del enfriamiento.
    AsistenciaClase::query()
        ->where('inscripcion_id', $inscripcion->id)
        ->orderByDesc('fecha')->limit(4)
        ->update(['estatus' => AsistenciaClase::FALTA]);

    $motor->correr();

    verificar('Dentro del enfriamiento NO se levanta otra',
        Alerta::query()->where('regla_id', $faltas->id)->abiertas()->count() === 0,
        (string) Alerta::query()->where('regla_id', $faltas->id)->abiertas()->count());

    // Pasado el enfriamiento, sí: la situación sigue ahí y hay que volver a
    // decirlo. Se mueve el reloj en vez de la fecha de cierre, para ejercitar la
    // comparación de verdad.
    $motor->correr(hoy: CarbonImmutable::now()->addDays(20));

    verificar('Pasado el enfriamiento, vuelve a levantarse',
        Alerta::query()->where('regla_id', $faltas->id)->abiertas()->count() === 1,
        (string) Alerta::query()->where('regla_id', $faltas->id)->abiertas()->count());

    echo PHP_EOL.'10. Apagar la regla deja las alertas OBSOLETAS, no resueltas'.PHP_EOL;

    $faltas->update(['activa' => false]);
    $motor->correr();

    $jubilada = Alerta::query()->where('regla_id', $faltas->id)
        ->orderByDesc('id')->first();

    verificar('Pasa a OBSOLETA', $jubilada->estado_senal === Alerta::OBSOLETA,
        (string) $jubilada->estado_senal);

    verificar('Y su cierre dice que NO se resolvió nada',
        str_contains(mb_strtolower(json_encode($jubilada->evidencia_cierre, JSON_UNESCAPED_UNICODE)), 'no significa'),
        json_encode($jubilada->evidencia_cierre, JSON_UNESCAPED_UNICODE));

    echo PHP_EOL.'11. Una EXCLUSIÓN vigente cierra lo abierto y no evalúa'.PHP_EOL;

    $faltas->update(['activa' => true]);
    $motor->correr(hoy: CarbonImmutable::now()->addDays(40));

    verificar('Con la regla encendida vuelve a haber alerta',
        Alerta::query()->where('regla_id', $faltas->id)->abiertas()->count() === 1);

    ExclusionReglaAlerta::create([
        'matricula_oferta_id' => $matricula->id,
        'motivo' => 'Licencia médica autorizada por la dirección.',
    ]);

    $motor->correr(hoy: CarbonImmutable::now()->addDays(41));

    verificar('La exclusión cierra lo que había abierto',
        Alerta::query()->where('regla_id', $faltas->id)
            ->where('matricula_oferta_id', $matricula->id)->abiertas()->count() === 0);

    verificar('Y no se levanta ninguna nueva mientras dure',
        Alerta::query()->where('regla_id', $faltas->id)
            ->where('matricula_oferta_id', $matricula->id)
            ->where('estado_senal', Alerta::OBSOLETA)->exists());

    ExclusionReglaAlerta::query()->forceDelete();

    echo PHP_EOL.'12. Una regla ROTA no detiene a las demás'.PHP_EOL;

    /*
     * Se construye la regla rota: una métrica que su proveedor no conoce. Es el
     * caso real —alguien retira una métrica del catálogo o cambia un proveedor—
     * y sin él «se aísla cada regla» sería una afirmación sin comprobar.
     */
    $rota = ReglaAlerta::create([
        'nombre' => PREFIJO.'Rota',
        'categoria_id' => $categoria->id,
        'proveedor' => 'asistencia',
        'activa' => true,
    ]);

    $rota->versiones()->create([
        'version' => 1,
        'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
        // El proveedor de asistencia no la conoce: revienta al medir.
        'metrica' => 'academico.promedio',
        'comparador' => '<',
        'umbral' => 6,
        'ventana_tipo' => 'ciclo',
        'cobertura_minima' => 0,
        'severidad' => 'alto',
        'peso' => 3,
        'frecuencia' => 'diaria',
        'cooldown_dias' => 0,
    ]);

    $conRota = $motor->correr(hoy: CarbonImmutable::now()->addDays(60));

    verificar('La corrida reporta el error de la regla rota',
        $conRota->huboErrores(), json_encode($conRota->errores, JSON_UNESCAPED_UNICODE));

    verificar('Y el error dice el NOMBRE de la regla, no sólo un id',
        collect($conRota->errores)->contains(fn (array $e) => ($e['regla'] ?? null) === PREFIJO.'Rota'));

    verificar('Las demás SÍ se evaluaron',
        Alerta::query()->where('regla_id', $faltas->id)->abiertas()->count() === 1,
        (string) Alerta::query()->where('regla_id', $faltas->id)->abiertas()->count());

    // Se limpia en orden: las alertas apuntan a la version.
    Alerta::query()->where('regla_id', $rota->id)->forceDelete();
    $rota->versiones()->forceDelete();
    $rota->forceDelete();

    echo PHP_EOL.'13. La categoría RESERVADA no filtra su detalle'.PHP_EOL;

    /*
     * Se construye a mano y NO con `replicate()`: aquel copia tambien
     * `clave_dedup`, que es una columna GENERADA, y MySQL rechaza el insert con
     * «The value specified for generated column is not allowed». Vale para
     * cualquier tabla de este proyecto con columna generada —hay cinco—.
     */
    $original = Alerta::query()->firstOrFail();

    $deFinanzas = Alerta::create(collect($original->getAttributes())
        ->except(['id', 'clave_dedup', 'created_at', 'updated_at'])
        ->put('categoria_id', $reservada->id)
        ->put('estado_senal', Alerta::RESUELTA)
        ->all());

    $sinPermiso = usuarioConRol('docente');

    $comoLaVe = $deFinanzas->fresh(['categoria', 'version', 'regla'])->comoLaVe($sinPermiso);

    verificar('Quien no la alcanza NO recibe el valor ni la evidencia',
        ! array_key_exists('valor_observado', $comoLaVe)
        && ! array_key_exists('evidencia', $comoLaVe),
        implode(', ', array_keys($comoLaVe)));

    verificar('Pero SÍ sabe que hay una señal de esa categoría',
        ($comoLaVe['categoria']['nombre'] ?? null) === $reservada->nombre
        && ($comoLaVe['reservada'] ?? null) === true);

    verificar('Y se le dice QUÉ permiso hace falta, no sólo que no puede',
        str_contains((string) ($comoLaVe['motivo'] ?? ''), (string) $reservada->permiso_detalle),
        (string) ($comoLaVe['motivo'] ?? ''));

    echo PHP_EOL.'14. El proveedor FINANCIERO no devuelve importes'.PHP_EOL;

    $finanzas = $registro->de('finanzas');

    $versionFalsa = new ReglaAlertaVersion([
        'metrica' => 'finanzas.dias_de_atraso',
        'comparador' => '>=',
        'umbral' => 1,
        'ventana_tipo' => 'ciclo',
    ]);
    $versionFalsa->setRelation('regla', new ReglaAlerta(['ciclo_id' => null]));

    $conCargos = MatriculaOferta::query()
        ->whereIn('id', DB::table('adeudos')->whereNull('deleted_at')
            ->whereNotNull('matricula_oferta_id')->pluck('matricula_oferta_id'))
        ->with('oferta')
        ->first() ?? $matricula;

    $mediciones = $finanzas->medir($conCargos, 'finanzas.dias_de_atraso', $versionFalsa);

    $prohibidas = ['monto', 'importe', 'saldo', 'total', 'pesos', 'monto_total'];

    $filtradas = [];

    foreach ($mediciones as $m) {
        foreach (array_keys($m->evidencia) as $clave) {
            foreach ($prohibidas as $p) {
                str_contains(mb_strtolower($clave), $p) && $filtradas[] = $clave;
            }
        }
    }

    verificar('Ninguna clave de la evidencia financiera contiene un importe',
        $filtradas === [], implode(', ', $filtradas));

    verificar('Y la evidencia dice que el monto se consulta en la cartera',
        collect($mediciones)->contains(fn ($m) => str_contains(
            mb_strtolower(json_encode($m->evidencia, JSON_UNESCAPED_UNICODE)), 'cartera')));

    echo PHP_EOL.'15. Los casos límite del pedido'.PHP_EOL;

    /*
     * «Una actividad que todavía no vence.» El proveedor del LMS sólo cuenta lo
     * ya cerrado: una abierta no es un incumplimiento. Se comprueba sobre la
     * medición, no sobre una alerta, porque el demo tiene un solo curso vivo.
     */
    $lms = $registro->de('lms');

    $versionLms = new ReglaAlertaVersion([
        'metrica' => 'lms.actividades_vencidas_sin_entrega',
        'comparador' => '>=', 'umbral' => 1, 'ventana_tipo' => 'ciclo',
    ]);
    $versionLms->setRelation('regla', new ReglaAlerta(['ciclo_id' => null]));

    $conCurso = DB::table('cursos')->whereNull('deleted_at')
        ->whereNotNull('asignatura_grupo_id')->where('publicado', true)->first();

    if ($conCurso !== null) {
        $insCurso = DB::table('inscripcion')->whereNull('deleted_at')
            ->where('asignatura_grupo_id', $conCurso->asignatura_grupo_id)->first();

        if ($insCurso !== null) {
            // Una actividad que cierra MAÑANA: no vence todavía.
            DB::table('actividades')->insert([
                'curso_id' => $conCurso->id,
                'tipo' => 'actividad',
                'titulo' => PREFIJO.'Sin vencer',
                'puntos' => 10,
                'cierra_en' => CarbonImmutable::now()->addDay(),
                'publicada' => true,
                'orden' => 999,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $mat = MatriculaOferta::query()->with('oferta')->find($insCurso->matricula_oferta_id);
            $m = collect($lms->medir($mat, 'lms.actividades_vencidas_sin_entrega', $versionLms))
                ->firstWhere('asignaturaGrupoId', $conCurso->asignatura_grupo_id);

            verificar('Una actividad que aún no vence NO cuenta como incumplida',
                $m === null || ! str_contains(
                    json_encode($m->evidencia, JSON_UNESCAPED_UNICODE), PREFIJO.'Sin vencer'),
                json_encode($m?->evidencia['cuales'] ?? [], JSON_UNESCAPED_UNICODE));
        }
    }

    /*
     * «Una calificación que todavía no está asentada.» El proveedor académico
     * lee `historial` con acta, así que la captura parcial no lo mueve.
     */
    $academico = $registro->de('academico');

    $versionAcad = new ReglaAlertaVersion([
        'metrica' => 'academico.reprobadas_ciclo',
        'comparador' => '>=', 'umbral' => 1, 'ventana_tipo' => 'ciclo',
    ]);
    $versionAcad->setRelation('regla', new ReglaAlerta(['ciclo_id' => null]));

    /*
     * La matrícula tiene que tener historial CON ACTA, o la medición sale
     * `sin_datos` antes y después y la comprobación pasa por la razón
     * equivocada —midiendo null contra null—. Se elige una que lo tenga.
     */
    $conActa = MatriculaOferta::query()
        ->whereIn('id', DB::table('historial')->whereNull('deleted_at')
            ->whereIn('estatus_id', DB::table('estatus_historial')
                ->whereNot('clave', 'en_curso')->pluck('id'))
            ->pluck('matricula_oferta_id'))
        ->with('oferta')
        ->firstOrFail();

    $insDeEsa = DB::table('inscripcion')->whereNull('deleted_at')
        ->where('matricula_oferta_id', $conActa->id)->first();

    $antesDeCapturar = collect($academico->medir($conActa, 'academico.reprobadas_ciclo', $versionAcad))
        ->first()->valor;

    verificar('La medición académica de partida SÍ trae un número, no null',
        $antesDeCapturar !== null, json_encode($antesDeCapturar));

    /*
     * Se CAPTURA una calificación reprobatoria sin asentarla. Da igual si es
     * nueva o si se corrige una que ya estaba: lo que se prueba es que la
     * captura parcial NO mueve la señal académica, que se lee del historial.
     */
    $componente = DB::table('esquema_evaluacion')->value('id');

    DB::table('calificaciones_componente')->updateOrInsert(
        ['inscripcion_id' => $insDeEsa->id ?? $inscripcion->id, 'esquema_evaluacion_id' => $componente],
        ['calificacion' => 2, 'fuente' => 'manual', 'capturado_en' => now(),
            'created_at' => now(), 'updated_at' => now()],
    );

    $despuesDeCapturar = collect($academico->medir($conActa, 'academico.reprobadas_ciclo', $versionAcad))
        ->first()->valor;

    verificar('Una calificación capturada y NO asentada no mueve la señal académica',
        $antesDeCapturar === $despuesDeCapturar,
        json_encode([$antesDeCapturar, $despuesDeCapturar]));

    /*
     * «Un pago pendiente de confirmar.» No es un pago: el cargo sigue vencido, y
     * la evidencia lo dice sin decir cuánto.
     */
    $medicionFin = collect($finanzas->medir($conCargos, 'finanzas.dias_de_atraso', $versionFalsa))->first();

    verificar('La evidencia financiera nombra su fuente y su motivo',
        isset($medicionFin->evidencia['fuente']) || isset($medicionFin->evidencia['motivo']),
        implode(', ', array_keys($medicionFin->evidencia)));

    echo PHP_EOL.'16. El modo SECO no escribe nada'.PHP_EOL;

    Alerta::query()->forceDelete();
    DB::table('riesgo_matricula')->delete();
    DB::table('corridas_evaluacion')->delete();

    $seca = $motor->correr(hoy: CarbonImmutable::now()->addDays(80), seco: true);

    verificar('En seco dice cuántas levantaría', $seca->alertas_creadas > 0,
        (string) $seca->alertas_creadas);

    verificar('Y NO deja ni una alerta', Alerta::query()->count() === 0);

    verificar('Ni registra la corrida', DB::table('corridas_evaluacion')->count() === 0);

    echo PHP_EOL.'17. La corrida queda registrada, con sus contadores'.PHP_EOL;

    $real = $motor->correr(hoy: CarbonImmutable::now()->addDays(81));

    verificar('Se guardó', DB::table('corridas_evaluacion')->count() === 1);

    verificar('Con lo que tardó y a cuántos evaluó',
        $real->milisegundos >= 0 && $real->matriculas_evaluadas > 0,
        $real->matriculas_evaluadas.' alumnos en '.$real->milisegundos.' ms');

    verificar('Y sus contadores cuadran con lo que hay en la tabla',
        $real->alertas_creadas === Alerta::query()->count(),
        $real->alertas_creadas.' contra '.Alerta::query()->count());

    echo PHP_EOL.'18. Cada PROVEEDOR, con sus casos construidos'.PHP_EOL;

    /*
     * El motor de arriba se prueba de punta a punta y eso deja sin ejercitar
     * casi todas las decisiones de los proveedores: el barrido de mutaciones lo
     * enseñó con diecisiete supervivientes, y las diecisiete eran «el escenario
     * no tiene el caso». Aquí se les pregunta directamente.
     */

    $versionDe = function (string $metrica, ?int $cicloId = null, string $ventana = 'ciclo', ?int $dias = null) {
        $v = new ReglaAlertaVersion([
            'metrica' => $metrica,
            'comparador' => '>=',
            'umbral' => 1,
            'ventana_tipo' => $ventana,
            'ventana_valor' => $dias,
        ]);
        $v->setRelation('regla', new ReglaAlerta(['ciclo_id' => $cicloId]));

        return $v;
    };

    $asistencia = $registro->de('asistencia');
    $unaMedicion = fn (array $ms, ?int $ag) => collect($ms)->firstWhere('asignaturaGrupoId', $ag);

    // ── Asistencia ────────────────────────────────────────────────────────
    $mAsis = $unaMedicion(
        $asistencia->medir($matricula, 'asistencia.porcentaje', $versionDe('asistencia.porcentaje')),
        $inscripcion->asignatura_grupo_id,
    );

    verificar('El porcentaje sale de las sesiones REGISTRADAS',
        $mAsis?->cobertura === 10, (string) $mAsis?->cobertura);

    verificar('Y con 6 presentes y 4 faltas de 10, da 60 %',
        (float) $mAsis?->valor === 60.0, (string) $mAsis?->valor);

    /*
     * La JUSTIFICADA cuenta como asistencia y CORTA la racha. Es la decisión que
     * separa esta definición de las otras dos que ya existen en el sistema, y
     * sin este caso ninguna de las dos se ejercita.
     */
    AsistenciaClase::query()
        ->where('inscripcion_id', $inscripcion->id)
        ->orderByDesc('fecha')->limit(1)
        ->update(['estatus' => AsistenciaClase::JUSTIFICADA]);

    $mJust = $unaMedicion(
        $asistencia->medir($matricula, 'asistencia.porcentaje', $versionDe('asistencia.porcentaje')),
        $inscripcion->asignatura_grupo_id,
    );

    verificar('Una justificada cuenta como asistencia: sube a 70 %',
        (float) $mJust?->valor === 70.0, (string) $mJust?->valor);

    $mRacha = $unaMedicion(
        $asistencia->medir($matricula, 'asistencia.faltas_consecutivas',
            $versionDe('asistencia.faltas_consecutivas')),
        $inscripcion->asignatura_grupo_id,
    );

    verificar('Y CORTA la racha: la más reciente ya no es falta',
        (float) $mRacha?->valor === 0.0, (string) $mRacha?->valor);

    // Se devuelve el escenario a como estaba.
    AsistenciaClase::query()
        ->where('inscripcion_id', $inscripcion->id)
        ->where('estatus', AsistenciaClase::JUSTIFICADA)
        ->update(['estatus' => AsistenciaClase::FALTA]);

    /*
     * Sin lista pasada: `sin_datos`, NO un cero. Se construye una inscripción
     * limpia, porque el resto del escenario ya tiene sesiones.
     */
    $limpia = DB::table('inscripcion')->whereNull('deleted_at')
        ->whereNotNull('asignatura_grupo_id')
        ->whereNotIn('id', DB::table('asistencia_clase')->whereNull('deleted_at')
            ->pluck('inscripcion_id'))
        ->first();

    if ($limpia !== null) {
        $mLimpia = $unaMedicion(
            $asistencia->medir(
                MatriculaOferta::query()->with('oferta')->find($limpia->matricula_oferta_id),
                'asistencia.porcentaje',
                $versionDe('asistencia.porcentaje'),
            ),
            $limpia->asignatura_grupo_id,
        );

        verificar('Sin lista pasada la medición es SIN DATOS, no un cero',
            $mLimpia !== null && ! $mLimpia->hayDato(),
            json_encode($mLimpia?->valor));

        verificar('Y dice por qué',
            str_contains((string) ($mLimpia?->evidencia['motivo'] ?? ''), 'lista'),
            (string) ($mLimpia?->evidencia['motivo'] ?? ''));
    }

    /*
     * Una materia dada de BAJA no se mide: no se le puede pasar lista, así que
     * se quedaría para siempre en la cola con un porcentaje que nadie puede
     * corregir. Se construye dando de baja la del escenario.
     */
    $baja = DB::table('situaciones_inscripcion')->where('clave', 'baja')->value('id');
    $situacionOriginal = $inscripcion->situacion_id;

    DB::table('inscripcion')->where('id', $inscripcion->id)->update(['situacion_id' => $baja]);

    $mBaja = $unaMedicion(
        $asistencia->medir($matricula->fresh('oferta'), 'asistencia.porcentaje',
            $versionDe('asistencia.porcentaje')),
        $inscripcion->asignatura_grupo_id,
    );

    verificar('Una materia dada de BAJA no se mide',
        $mBaja === null, json_encode($mBaja?->valor));

    DB::table('inscripcion')->where('id', $inscripcion->id)
        ->update(['situacion_id' => $situacionOriginal]);

    // ── Académico ─────────────────────────────────────────────────────────
    $sinNada = MatriculaOferta::query()
        ->whereNotIn('id', DB::table('historial')->whereNull('deleted_at')->pluck('matricula_oferta_id'))
        ->whereHas('oferta')->with('oferta')->first();

    /*
     * Se CONSTRUYE la matrícula sin historial: en el demo todas tienen alguno, y
     * sin este caso «sin materias asentadas da sin datos» no se ejercitaba —la
     * mutación que devolvía cero sobrevivía—.
     */
    if ($sinNada === null) {
        $modelo = MatriculaOferta::query()->whereHas('oferta')->firstOrFail();

        // Persona NUEVA: el único de la tabla es (persona, oferta), y quien ya
        // estudia ese programa no puede matricularse dos veces en él.
        $duenio = Persona::create([
            'nombre' => 'Sin', 'primer_apellido' => 'Historial',
            'segundo_apellido' => (string) random_int(1000, 9999), 'sexo_id' => 1,
        ]);

        $sinNada = MatriculaOferta::create([
            'persona_id' => $duenio->id,
            'oferta_id' => $modelo->oferta_id,
            'matricula' => PREFIJO.random_int(10000, 99999),
            'situacion_id' => $modelo->situacion_id,
            'generacion' => $modelo->generacion,
            'fecha_ingreso' => $modelo->fecha_ingreso,
            'estatus' => $modelo->estatus,
        ])->fresh('oferta');
    }

    $mProm = collect($academico->medir($sinNada, 'academico.promedio',
        $versionDe('academico.promedio')))->first();

    verificar('Sin materias asentadas el promedio es SIN DATOS, no un cero',
        ! $mProm->hayDato(), json_encode($mProm->valor));

    verificar('Y lo dice con palabras',
        str_contains((string) ($mProm->evidencia['motivo'] ?? ''), 'asentada'),
        (string) ($mProm->evidencia['motivo'] ?? ''));

    /*
     * Y el SERVICIO por su cuenta: sin sesiones el porcentaje es NULL, no cero.
     * `medir()` devuelve `sin_datos` antes de llegar a llamarlo, así que por esa
     * puerta la regla no se ejercita nunca.
     */
    $servicioAsistencia = app(App\Services\Asistencia\AsistenciaDelAlumno::class);

    verificar('El servicio: sin sesiones el porcentaje es NULL, no cero',
        $servicioAsistencia->porcentaje(
            ['sesiones' => 0, 'presentes' => 0, 'faltas' => 0, 'justificadas' => 0, 'retardos' => 0],
        ) === null);

    verificar('Y con 8 de 10 sin falta, 80 %',
        $servicioAsistencia->porcentaje(
            ['sesiones' => 10, 'presentes' => 7, 'faltas' => 2, 'justificadas' => 1, 'retardos' => 0],
        ) === 80.0);

    /*
     * Lo que sigue EN CURSO no cuenta como no aprobado. El caso se construye:
     * el demo tiene renglones «en_curso» y «aprobada», así que se mide con y sin
     * ellos.
     */
    $enCurso = DB::table('estatus_historial')->where('clave', 'en_curso')->value('id');
    $cuantosEnCurso = DB::table('historial')->whereNull('deleted_at')
        ->where('matricula_oferta_id', $conActa->id)->where('estatus_id', $enCurso)->count();

    $mRep = collect($academico->medir($conActa, 'academico.reprobadas_ciclo',
        $versionDe('academico.reprobadas_ciclo')))->first();

    $totales = DB::table('historial')->whereNull('deleted_at')
        ->where('matricula_oferta_id', $conActa->id)->count();

    verificar('La cobertura excluye lo que sigue en curso',
        $mRep->cobertura === $totales - $cuantosEnCurso,
        $mRep->cobertura.' de '.$totales.' con '.$cuantosEnCurso.' en curso');

    verificar('Y hay materias EN CURSO con las que separar las dos formas',
        $cuantosEnCurso > 0, (string) $cuantosEnCurso);

    // ── Finanzas ──────────────────────────────────────────────────────────
    $finanzas = $registro->de('finanzas');
    $vFin = $versionDe('finanzas.dias_de_atraso');

    /*
     * Un cargo VENCIDO de un plan que la escuela persigue, construido: sin él,
     * quitarle al proveedor su filtro por `afecta_estatus_deudor` no cambiaba
     * nada porque no había ningún cargo del otro tipo.
     */
    $conceptoPlan = DB::table('conceptos_plan')
        ->join('planes_cobro', 'planes_cobro.id', '=', 'conceptos_plan.plan_cobro_id')
        ->whereNull('conceptos_plan.deleted_at')
        ->select('conceptos_plan.id', 'planes_cobro.afecta_estatus_deudor')
        ->get();

    $queAfecta = $conceptoPlan->firstWhere('afecta_estatus_deudor', 1);
    $queNoAfecta = $conceptoPlan->firstWhere('afecta_estatus_deudor', 0);

    if ($queAfecta === null || $queNoAfecta === null) {
        // Se CONSTRUYE el par: es lo que separa «filtra por la bandera» de «no
        // filtra», y sin los dos la regla se queda sin comprobar.
        $planQueNo = DB::table('planes_cobro')->insertGetId([
            'nombre' => PREFIJO.'No persigue',
            'afecta_estatus_deudor' => false,
            'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $queNoAfecta = (object) ['id' => DB::table('conceptos_plan')->insertGetId([
            'plan_cobro_id' => $planQueNo,
            'concepto_id' => DB::table('conceptos_pago')->value('id'),
            'monto' => 100,
            'periodicidad' => 'unico',
            'created_at' => now(), 'updated_at' => now(),
        ])];
    }

    $sinCargos = MatriculaOferta::query()
        ->whereNotIn('id', DB::table('adeudos')->whereNull('deleted_at')
            ->whereNotNull('matricula_oferta_id')->pluck('matricula_oferta_id'))
        ->whereHas('oferta')->with('oferta')->first();

    if ($sinCargos !== null && $queNoAfecta !== null) {
        DB::table('adeudos')->insert([
            'matricula_oferta_id' => $sinCargos->id,
            'concepto_id' => DB::table('conceptos_pago')->value('id'),
            'concepto_plan_id' => $queNoAfecta->id,
            'monto' => 500, 'monto_total' => 500,
            'fecha_generacion' => CarbonImmutable::now()->subMonths(2)->toDateString(),
            'fecha_vencimiento' => CarbonImmutable::now()->subMonth()->toDateString(),
            'estatus' => 'pendiente',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mNoPersigue = collect($finanzas->medir($sinCargos, 'finanzas.dias_de_atraso', $vFin))->first();

        verificar('Un cargo vencido de un plan que NO se persigue no produce señal',
            ! $mNoPersigue->hayDato(), json_encode($mNoPersigue->valor));

        verificar('Y el motivo lo dice',
            str_contains((string) ($mNoPersigue->evidencia['motivo'] ?? ''), 'no persigue'),
            (string) ($mNoPersigue->evidencia['motivo'] ?? ''));
    }

    /*
     * Y un CONVENIO vigente saca al alumno. Se construye sobre quien sí tiene
     * cargos: sin convenio, quitarle esa comprobación al proveedor no cambiaba
     * nada.
     */
    $medicionPrevia = collect($finanzas->medir($conCargos, 'finanzas.dias_de_atraso', $vFin))->first();

    if ($medicionPrevia->hayDato()) {
        DB::table('convenios_pago')->insert([
            'matricula_oferta_id' => $conCargos->id,
            'concepto_id' => DB::table('conceptos_pago')->value('id'),
            'motivo' => PREFIJO.'Acuerdo de prueba.',
            'firmado_en' => now()->toDateString(),
            'monto_cubierto' => 1000,
            'estatus' => App\Models\Finanzas\ConvenioPago::VIGENTE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $conConvenio = collect($finanzas->medir($conCargos->fresh('oferta'),
            'finanzas.dias_de_atraso', $vFin))->first();

        verificar('Con un convenio de pago vigente NO se produce señal financiera',
            ! $conConvenio->hayDato(), json_encode($conConvenio->valor));

        verificar('Y el motivo dice que la escuela ya acordó',
            str_contains((string) ($conConvenio->evidencia['motivo'] ?? ''), 'acordó'),
            (string) ($conConvenio->evidencia['motivo'] ?? ''));

        DB::table('convenios_pago')->where('motivo', PREFIJO.'Acuerdo de prueba.')->delete();
    }

    // ── LMS ───────────────────────────────────────────────────────────────
    if ($conCurso !== null && $insCurso !== null) {
        $matCurso = MatriculaOferta::query()->with('oferta')->find($insCurso->matricula_oferta_id);

        // Se retiran las actividades vencidas para dejar el curso sin ninguna
        // cerrada: es el caso que separa «sin datos» de «cero».
        $vencidasOriginales = DB::table('actividades')->whereNull('deleted_at')
            ->where('curso_id', $conCurso->id)->where('cierra_en', '<', now())->pluck('id');

        DB::table('actividades')->whereIn('id', $vencidasOriginales)
            ->update(['cierra_en' => CarbonImmutable::now()->addMonth()]);

        $mSinVencidas = $unaMedicion(
            $lms->medir($matCurso, 'lms.actividades_vencidas_sin_entrega', $versionLms),
            $conCurso->asignatura_grupo_id,
        );

        verificar('Un curso sin nada vencido da SIN DATOS, no cero',
            $mSinVencidas !== null && ! $mSinVencidas->hayDato(),
            json_encode($mSinVencidas?->valor));

        /*
         * Y se construye una ENTREGABLE ya vencida. Las del demo son lecturas,
         * que el proveedor excluye a propósito —no se entregan, se marcan— así
         * que devolverlas al pasado no bastaba: la medición seguía sin datos y
         * la comprobación pasaba por la razón equivocada.
         */
        DB::table('actividades')->where('titulo', PREFIJO.'Sin vencer')
            ->update(['cierra_en' => CarbonImmutable::now()->subWeek()]);

        $mConVencidas = $unaMedicion(
            $lms->medir($matCurso, 'lms.actividades_vencidas_sin_entrega', $versionLms),
            $conCurso->asignatura_grupo_id,
        );

        verificar('Y con actividades vencidas SÍ trae un número',
            $mConVencidas !== null && $mConVencidas->hayDato(),
            json_encode($mConVencidas?->valor));
    }

    // Una materia SIN curso publicado: sin datos, no cero.
    $sinCurso = DB::table('inscripcion')->whereNull('deleted_at')
        ->whereNotNull('asignatura_grupo_id')
        ->whereNotIn('asignatura_grupo_id', DB::table('cursos')->whereNull('deleted_at')
            ->whereNotNull('asignatura_grupo_id')->pluck('asignatura_grupo_id'))
        ->first();

    if ($sinCurso !== null) {
        $mSinCurso = $unaMedicion(
            $lms->medir(MatriculaOferta::query()->with('oferta')->find($sinCurso->matricula_oferta_id),
                'lms.actividades_vencidas_sin_entrega', $versionLms),
            $sinCurso->asignatura_grupo_id,
        );

        verificar('Una materia sin curso publicado da SIN DATOS, no cero',
            $mSinCurso !== null && ! $mSinCurso->hayDato(), json_encode($mSinCurso?->valor));

        /*
         * Y el MOTIVO distingue las dos ausencias. Sin comprobarlo, quitarle la
         * guarda de «no tiene curso» pasaba igual: la consulta contra un curso
         * null sale vacía y cae en «no vence nada», que también es sin datos —
         * pero le dice a quien mira que el curso existe y no ha cerrado nada,
         * que es falso.
         */
        verificar('Y su motivo dice que no hay CURSO, no que no venció nada',
            str_contains((string) ($mSinCurso->evidencia['motivo'] ?? ''), 'curso publicado'),
            (string) ($mSinCurso->evidencia['motivo'] ?? ''));
    }

    echo PHP_EOL.'19. Lo que la ALERTA congela'.PHP_EOL;

    Alerta::query()->forceDelete();
    $motor->correr(hoy: CarbonImmutable::now()->addDays(120));

    $congelada = Alerta::query()->where('regla_id', $faltas->id)->firstOrFail();
    $versionOriginal = $congelada->regla_version_id;
    $categoriaOriginal = $congelada->categoria_id;

    /*
     * Se cambia la regla POR DEBAJO: otra categoría y otra versión. La alerta ya
     * levantada no puede moverse — de la categoría depende quién ve su detalle,
     * y de la versión, con qué umbral se explica.
     */
    $faltas->update(['categoria_id' => $reservada->id]);

    $faltas->versiones()->create([
        'version' => 2,
        'vigente_desde' => CarbonImmutable::now()->addDays(100)->toDateString(),
        'metrica' => 'asistencia.faltas_consecutivas',
        'comparador' => '>=', 'umbral' => 99,
        'ventana_tipo' => 'ciclo', 'cobertura_minima' => 3,
        'severidad' => 'bajo', 'peso' => 1, 'frecuencia' => 'diaria', 'cooldown_dias' => 14,
    ]);

    $recien = $congelada->fresh();

    /*
     * Contra la categoría de la REGLA en el momento de nacer, no contra la que
     * la alerta trae. Comparándola consigo misma, una alerta que copiara
     * cualquier otra categoría pasaba igual: es lo que dejó viva la mutación de
     * «la alerta no copia su categoría».
     */
    verificar('La alerta nació con la categoría que la regla tenía entonces',
        $categoriaOriginal === $categoria->id,
        $categoriaOriginal.' contra '.$categoria->id);

    verificar('Y la conserva aunque la regla cambie de categoría',
        $recien->categoria_id === $categoriaOriginal
        && $categoriaOriginal !== $reservada->id,
        $recien->categoria_id.' contra la nueva '.$reservada->id);

    verificar('Y su severidad es la de la versión con la que se levantó',
        $recien->severidad === 'medio', (string) $recien->severidad);

    verificar('Y la VERSIÓN con la que se levantó',
        $recien->regla_version_id === $versionOriginal);

    verificar('Su evidencia sigue diciendo el umbral de entonces',
        (float) ($recien->evidencia['umbral_aplicado'] ?? -1) === 3.0,
        json_encode($recien->evidencia['umbral_aplicado'] ?? null));

    echo PHP_EOL.'20. El umbral del PLAN se lee del plan'.PHP_EOL;

    $plan = $conActa->oferta?->plan;

    if ($plan !== null && $plan->calificacion_minima_aprobatoria !== null) {
        $delPlan = $crearRegla([
            'nombre' => PREFIJO.'Promedio del plan',
            'categoria_id' => $categoria->id,
            'proveedor' => 'academico',
        ], [
            'metrica' => 'academico.promedio',
            'comparador' => '<',
            // Un umbral fijo DISPARATADO: si el motor lo usara en vez del plan,
            // la alerta saldría con él y la comprobación lo vería.
            'umbral' => 999,
            'umbral_fuente' => 'plan',
            'ventana_tipo' => 'desde_inicio',
            'cobertura_minima' => 1,
            'severidad' => 'alto',
            'peso' => 3,
            'cooldown_dias' => 0,
        ]);

        $motor->correr(hoy: CarbonImmutable::now()->addDays(121));

        /*
         * CADA alerta contra el plan de SU alumno. La regla no acota a nadie, así
         * que la primera que salga puede ser de otra matrícula con otro plan:
         * comparar la primera contra este plan medía dos cosas distintas y
         * fallaba por la razón equivocada.
         */
        $delPlanAlertas = Alerta::query()->where('regla_id', $delPlan->id)
            ->with('matricula.oferta.plan')->get();

        verificar('Se levantó al menos una con umbral del plan',
            $delPlanAlertas->isNotEmpty(), (string) $delPlanAlertas->count());

        $descuadradas = $delPlanAlertas->reject(fn (Alerta $a) => (float) $a->umbral
            === (float) $a->matricula?->oferta?->plan?->calificacion_minima_aprobatoria);

        verificar('Y cada una lleva el mínimo aprobatorio de SU plan',
            $descuadradas->isEmpty(),
            $descuadradas->map(fn (Alerta $a) => $a->umbral.' vs '
                .$a->matricula?->oferta?->plan?->calificacion_minima_aprobatoria)->implode(' | '));

        verificar('Y ninguna lleva el 999 capturado',
            $delPlanAlertas->every(fn (Alerta $a) => (float) $a->umbral !== 999.0));
    }

    echo PHP_EOL.'21. Una regla SIN versión vigente no se evalúa'.PHP_EOL;

    $caducada = ReglaAlerta::create([
        'nombre' => PREFIJO.'Caducada',
        'categoria_id' => $categoria->id,
        'proveedor' => 'asistencia',
        'activa' => true,
    ]);

    $caducada->versiones()->create([
        'version' => 1,
        'vigente_desde' => CarbonImmutable::now()->subYear()->toDateString(),
        // Se le acabó la vigencia: no rige hoy.
        'vigente_hasta' => CarbonImmutable::now()->subMonths(6)->toDateString(),
        'metrica' => 'asistencia.faltas_consecutivas',
        'comparador' => '>=', 'umbral' => 1,
        'ventana_tipo' => 'ciclo', 'cobertura_minima' => 0,
        'severidad' => 'alto', 'peso' => 3, 'frecuencia' => 'diaria', 'cooldown_dias' => 0,
    ]);

    $motor->correr(hoy: CarbonImmutable::now()->addDays(122));

    verificar('Una regla encendida SIN versión vigente no levanta nada',
        ! Alerta::query()->where('regla_id', $caducada->id)->exists());

    echo PHP_EOL.'22. La DEDUPLICACIÓN sola, sin enfriamiento que la tape'.PHP_EOL;

    /*
     * Con `cooldown_dias = 0` la única defensa contra una segunda alerta es la
     * deduplicación. Sin este caso, quitarle al motor su rama de «ya está
     * abierta» no cambiaba nada: el enfriamiento la tapaba.
     */
    $sinEnfriamiento = $crearRegla([
        'nombre' => PREFIJO.'Sin enfriamiento',
        'categoria_id' => $categoria->id,
        'proveedor' => 'asistencia',
    ], [
        'metrica' => 'asistencia.faltas_consecutivas',
        'comparador' => '>=',
        'umbral' => 2,
        'ventana_tipo' => 'ciclo',
        'cobertura_minima' => 3,
        'severidad' => 'bajo',
        'peso' => 1,
        'cooldown_dias' => 0,
    ]);

    $motor->correr(hoy: CarbonImmutable::now()->addDays(123));
    $motor->correr(hoy: CarbonImmutable::now()->addDays(123));
    $motor->correr(hoy: CarbonImmutable::now()->addDays(123));

    verificar('Tres corridas sin enfriamiento dejan UNA sola alerta abierta',
        Alerta::query()->where('regla_id', $sinEnfriamiento->id)->abiertas()->count() === 1,
        (string) Alerta::query()->where('regla_id', $sinEnfriamiento->id)->abiertas()->count());

    verificar('Y el único de la base lo sostiene, no sólo el SELECT previo',
        Alerta::query()->where('regla_id', $sinEnfriamiento->id)->count() === 1,
        (string) Alerta::query()->where('regla_id', $sinEnfriamiento->id)->count());

    /*
     * Y la que sigue abierta se ACTUALIZA con el valor nuevo.
     *
     * Contar alertas no bastaba: con la rama de «ya está abierta» quitada, el
     * único de la base impide el duplicado igual y el conteo sale idéntico —lo
     * que cambia es que la alerta se queda diciendo el valor de hace días—. Es
     * lo que dejó viva esa mutación, y lo que un coordinador leería como cierto.
     */
    $abiertaAntes = Alerta::query()->where('regla_id', $sinEnfriamiento->id)->abiertas()->firstOrFail();
    $valorAntes = (float) $abiertaAntes->valor_observado;

    // Una falta más: el valor observado sube.
    AsistenciaClase::create([
        'inscripcion_id' => $inscripcion->id,
        'fecha' => CarbonImmutable::now()->addDay()->toDateString(),
        'modalidad' => 'teoria',
        'estatus' => AsistenciaClase::FALTA,
    ]);

    $motor->correr(hoy: CarbonImmutable::now()->addDays(124));

    $abiertaDespues = $abiertaAntes->fresh();

    verificar('La alerta abierta se ACTUALIZA con el valor nuevo, no se queda vieja',
        (float) $abiertaDespues->valor_observado > $valorAntes,
        $valorAntes.' → '.$abiertaDespues->valor_observado);

    verificar('Y su evidencia también',
        (int) ($abiertaDespues->evidencia['faltas_seguidas'] ?? 0)
            === (int) $abiertaDespues->valor_observado,
        json_encode($abiertaDespues->evidencia['faltas_seguidas'] ?? null));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
