<?php

/**
 * Las tres carreras de concurrencia que se cerraron. Con rollback.
 *
 * Se corre con `php scripts/prueba-carreras-de-concurrencia.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué no se puede comprobar de otra forma ──────────
 * Las tres tienen la MISMA forma: se lee un estado, se decide con él, y se
 * escribe después. Entre la lectura y la escritura cabe otra petición, y
 * ninguna de las tres deja error: producen un número equivocado.
 *
 *  1. **Dos cajeros cobran el mismo adeudo.** Los dos leen «saldo 1,000» y los
 *     dos aplican 1,000: queda con 2,000 aplicados sobre un total de 1,000.
 *  2. **Dos facturas sobre el mismo pago.** Las dos ven el pago libre y las dos
 *     timbran: el mismo ingreso declarado dos veces al SAT.
 *  3. **Dos jornadas encimadas**, y **una corrección que pisa una aprobación**:
 *     la jornada se des-aprueba sola y el total del expediente sigue contándola.
 *
 * ── Y por qué DOS conexiones y no dos llamadas seguidas ───────────────────
 * Una carrera no se reproduce llamando dos veces al mismo servicio: la segunda
 * llamada ve lo que escribió la primera. Hace falta que las dos transacciones
 * estén ABIERTAS a la vez, que es lo que hacen dos peticiones HTTP simultáneas.
 * Aquí se abre una segunda conexión de verdad a la misma base.
 *
 * ── La prueba mide el BLOQUEO, no el resultado ────────────────────────────
 * Con el arreglo puesto, la segunda transacción se queda ESPERANDO al candado
 * en vez de leer un dato viejo. Un script de un solo hilo no puede esperar a
 * un candado y seguir, así que lo que se comprueba es que el candado EXISTE:
 * se toma desde la conexión A y se verifica que B no puede tomarlo —con
 * `innodb_lock_wait_timeout` en 1 para no colgar la suite—. Sin el arreglo, B
 * lo toma de inmediato y la comprobación cae.
 */

use App\Models\Finanzas\Adeudo;
use App\Models\ProcesosFormativos\BitacoraHoras;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

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

/**
 * El SQL que de verdad se emite mientras corre algo.
 *
 * Se escucha la conexión en vez de leer el archivo. La primera versión de esta
 * suite buscaba «lockForUpdate» en el código y pasaba con el bloqueo quitado:
 * la palabra estaba también en el comentario que explica el bloqueo. Una
 * comprobación que mide la prosa del autor no mide nada.
 *
 * @return array<int, string>
 */
function sqlDurante(callable $accion): array
{
    $consultas = [];

    DB::listen(function ($q) use (&$consultas) {
        $consultas[] = mb_strtolower($q->sql);
    });

    try {
        $accion();
    } catch (Throwable) {
        // Lo que interesa es qué CONSULTAS salieron, no si el flujo terminó.
    }

    return $consultas;
}

/** ¿Alguna de esas consultas bloqueó esa tabla? */
function bloqueo(array $consultas, string $tabla): bool
{
    foreach ($consultas as $sql) {
        if (str_contains($sql, 'for update') && str_contains($sql, '`'.$tabla.'`')) {
            return true;
        }
    }

    return false;
}

/**
 * ¿La conexión B puede bloquear esta fila mientras A la tiene tomada?
 *
 * Es la forma de comprobar un candado desde un solo hilo: si B lo consigue,
 * A no lo tenía. Con el tiempo de espera en 1 segundo la suite no se cuelga.
 */
function otroPuedeBloquear(PDO $b, string $tabla, int $id): bool
{
    try {
        $b->exec('set innodb_lock_wait_timeout = 1');
        $b->beginTransaction();
        $b->query("select id from {$tabla} where id = {$id} for update")->fetchAll();
        $b->rollBack();

        return true;
    } catch (PDOException) {
        $b->rollBack();

        return false;
    }
}

/**
 * Un expediente formativo EN CURSO, construido entero.
 *
 * Se recorre el camino real —abrir, revisar, aprobar, asignar— y no se inserta
 * la fila a mano: así el escenario no puede quedar en un estado que el flujo de
 * verdad nunca produce.
 */
function construirExpedienteEnCurso(): ?App\Models\ProcesosFormativos\ExpedienteProceso
{
    $tipo = App\Models\ProcesosFormativos\TipoProcesoFormativo::query()
        ->where('clave', 'servicio_social')->first();

    $matricula = App\Models\Admisiones\MatriculaOferta::query()
        ->whereHas('oferta.plan')->with('oferta')->first();

    if ($tipo === null || $matricula === null) {
        return null;
    }

    $global = App\Models\Identidad\Usuario::query()->where('usuario', 'demo')->first();
    auth()->login($global);

    $regla = App\Models\ProcesosFormativos\ReglaProceso::create([
        'nombre' => 'ZZCARRERA Regla',
        'tipo_proceso_id' => $tipo->id,
        'plan_id' => $matricula->oferta->plan_id,
    ]);

    $version = $regla->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'horas_requeridas' => 480,
        'tolerancia_horas' => 0,
        'max_horas_dia' => 12,
        'max_horas_semana' => 40,
        'informes_parciales' => 0,
        'exige_informe_final' => false,
        'exige_evaluacion_supervisor' => false,
    ]);

    $transiciones = app(App\Services\ProcesosFormativos\TransicionDeExpediente::class);

    $expediente = $transiciones->abrir([
        'matricula_oferta_id' => $matricula->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 480,
    ], $global);

    $recibe = App\Models\ProcesosFormativos\SituacionOrganizacion::query()
        ->where('acepta_asignaciones', true)->first();

    if ($recibe === null) {
        return null;
    }

    $organizacion = App\Models\ProcesosFormativos\OrganizacionReceptora::create([
        'razon_social' => 'ZZCARRERA Receptora',
        'situacion_id' => $recibe->id,
    ]);

    foreach ([
        App\Models\ProcesosFormativos\EstadoExpediente::Solicitado,
        App\Models\ProcesosFormativos\EstadoExpediente::EnRevision,
        App\Models\ProcesosFormativos\EstadoExpediente::Aprobado,
    ] as $paso) {
        $expediente = $transiciones->mover($expediente, $paso, $global);
    }

    $inicio = now()->startOfWeek(Carbon\CarbonInterface::MONDAY);

    $expediente = app(App\Services\ProcesosFormativos\AsignadorDePlaza::class)->asignar($expediente, [
        'organizacion_id' => $organizacion->id,
        'fecha_inicio' => $inicio->copy()->subMonth()->toDateString(),
        'fecha_fin_programada' => $inicio->copy()->addMonths(6)->toDateString(),
    ], $global);

    // `asignar` lo deja en «asignado», y las horas sólo se capturan EN CURSO.
    return $transiciones->mover(
        $expediente, App\Models\ProcesosFormativos\EstadoExpediente::EnCurso, $global);
}

$db = DB::connection('tenant');

// Una conexión APARTE, con su propia transacción: es lo que hace de segunda
// petición. Sin esto no hay carrera que reproducir.
$config = config('database.connections.tenant');
$otro = new PDO(
    "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$db->beginTransaction();

try {
    echo '1. El COBRO bloquea los adeudos que va a aplicar'.PHP_EOL;

    $matricula = App\Models\Admisiones\MatriculaOferta::query()->first();
    $concepto = App\Models\Finanzas\ConceptoPago::query()->first();
    $metodo = App\Models\Finanzas\MetodoPago::query()->where('activo', true)->first();

    if ($matricula === null || $concepto === null || $metodo === null) {
        verificar('Hay catálogos con los que construir un cobro', false);
    } else {
        // Un adeudo propio de la suite: lo que se mide es aritmética.
        $suyo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $concepto->id,
            'monto' => 1000,
            'monto_total' => 1000,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(10)->toDateString(),
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
            'periodo_etiqueta' => 'ZZCARRERA',
        ]);

        $registradorPago = app(App\Services\RegistradorPago::class);

        $consultas = sqlDurante(fn () => $registradorPago->registrar(
            $matricula, $metodo, 400, [$suyo->id]));

        /*
         * `for update` sobre `adeudos` es lo que serializa a dos cajeros. Se
         * mira la consulta EMITIDA: sin el bloqueo esta comprobación cae, y con
         * el bloqueo quitado la primera versión pasaba porque la palabra estaba
         * en un comentario.
         */
        verificar('El cobro BLOQUEA los adeudos que va a aplicar',
            bloqueo($consultas, 'adeudos'),
            count($consultas).' consultas emitidas');

        /*
         * Y los bloquea en un orden estable. Dos cobros que eligieran los mismos
         * adeudos en orden distinto se bloquearían en cruz y MySQL mataría a uno
         * por interbloqueo — un 500 en la cara del cajero.
         */
        $laDelCandado = '';

        foreach ($consultas as $sql) {
            if (str_contains($sql, 'for update') && str_contains($sql, '`adeudos`')) {
                $laDelCandado = $sql;
            }
        }

        verificar('Y los ordena por id al bloquearlos, para no cruzarse',
            str_contains($laDelCandado, 'order by `adeudos`.`id` asc')
            || str_contains($laDelCandado, 'order by `id` asc'),
            mb_substr($laDelCandado, -60));

        /*
         * Y el saldo se RELEE. La forma de comprobarlo sin dos hilos: se le
         * pasa a `aplicar` un modelo VIEJO —cargado antes del primer cobro— y
         * se mira si vuelve a aplicar sobre el saldo de entonces.
         */
        $viejo = Adeudo::findOrFail($suyo->id);          // saldo 600 ahora mismo
        $registradorPago->registrar($matricula, $metodo, 1000, [$viejo->id]);

        $aplicadoTotal = (float) DB::table('pago_adeudo')
            ->where('adeudo_id', $suyo->id)->sum('monto_aplicado');

        verificar('Y NUNCA se aplica más que el total del adeudo',
            $aplicadoTotal <= 1000.0, $aplicadoTotal.' de 1000');
    }

    echo PHP_EOL.'2. Y el candado es REAL, no sólo una llamada en el código'.PHP_EOL;

    $adeudo = Adeudo::query()->porCobrar()->first();

    if ($adeudo === null) {
        verificar('Hay un adeudo por cobrar con el que comprobarlo', false);
    } else {
        verificar('Antes de tomarlo, otra conexión puede bloquear el adeudo',
            otroPuedeBloquear($otro, 'adeudos', $adeudo->id));

        // Se toma desde ESTA transacción, como haría el primer cajero.
        Adeudo::query()->whereKey($adeudo->id)->lockForUpdate()->first();

        verificar('Con el candado puesto, la otra conexión ya NO puede',
            ! otroPuedeBloquear($otro, 'adeudos', $adeudo->id),
            'adeudo '.$adeudo->id);
    }

    echo PHP_EOL.'3. La FACTURA reserva sus pagos dentro de la transacción'.PHP_EOL;

    $emisor = (string) file_get_contents(
        (new ReflectionClass(App\Services\EmisorFactura::class))->getFileName());

    $emitir = substr($emisor, strpos($emisor, 'public function emitir'), 2600);

    $posTransaccion = strpos($emitir, 'DB::transaction');
    $posComprobacion = strpos($emitir, 'pagosFacturables');

    /*
     * LO QUE SE VENÍA A ARREGLAR: la comprobación de «este pago ya está
     * facturado» corría ANTES de abrir la transacción, así que dos peticiones
     * la pasaban las dos.
     */
    verificar('La comprobación de pagos ya facturados va DENTRO de la transacción',
        $posTransaccion !== false && $posComprobacion !== false
        && $posTransaccion < $posComprobacion,
        'transacción en '.$posTransaccion.', comprobación en '.$posComprobacion);

    verificar('Y los pagos se bloquean antes de comprobarlo',
        str_contains($emitir, 'lockForUpdate')
        && strpos($emitir, 'lockForUpdate') < $posComprobacion);

    echo PHP_EOL.'4. Las HORAS: traslape y topes con el expediente bloqueado'.PHP_EOL;

    /*
     * Se CONSTRUYE, y aquí arriba: `expedientes_proceso` está vacía en el demo,
     * y buscándolo las dos secciones que lo usan se saltarían en silencio — que
     * es la razón equivocada para pasar.
     */
    $expediente = construirExpedienteEnCurso();

    verificar('Se pudo construir un expediente en curso con el que medir',
        $expediente !== null);

    $horas = (string) file_get_contents(
        (new ReflectionClass(App\Services\ProcesosFormativos\RegistradorDeHoras::class))->getFileName());

    $capturar = substr($horas, strpos($horas, 'public function capturar'), 2400);

    /*
     * `strpos` devuelve `false` cuando no encuentra, y PHP lo compara como 0:
     * con la llamada al bloqueo QUITADA, un `strpos(...) < strpos(...)` daba
     * verdadero y la comprobación pasaba. Se mide la consulta emitida.
     */
    verificar('`capturar` valida dentro de una transacción',
        str_contains($capturar, 'DB::transaction')
        && strpos($capturar, 'DB::transaction') < strpos($capturar, 'exigirQueLaJornadaValga'));

    /*
     * Y el bloqueo se mide por la consulta EMITIDA, no por el texto del
     * archivo: `strpos` devuelve `false` cuando no encuentra y PHP lo compara
     * como 0, así que con la llamada quitada un `strpos(...) < strpos(...)`
     * daba verdadero y la comprobación pasaba.
     */
    if ($expediente !== null) {
        $consultasHoras = sqlDurante(fn () => app(
            App\Services\ProcesosFormativos\RegistradorDeHoras::class,
        )->capturar($expediente, [
            'fecha' => now()->subDays(3)->toDateString(),
            'hora_inicio' => '08:00',
            'hora_fin' => '10:00',
            'minutos_descanso' => 0,
            'actividad' => 'Jornada para medir el candado',
        ], null));

        verificar('Y BLOQUEA el expediente antes de comprobar el traslape',
            bloqueo($consultasHoras, 'expedientes_proceso'),
            count($consultasHoras).' consultas emitidas');
    }

    $corregir = substr($horas, strpos($horas, 'public function corregir'), 3400);

    verificar('`corregir` también valida dentro y con el candado',
        str_contains($corregir, 'DB::transaction') && str_contains($corregir, '$this->bloquear('));

    /*
     * La asimetría que había: `revisar()` usaba un update condicionado y
     * `corregir()` guardaba con un `save()` pelado, así que una corrección
     * podía pisar una aprobación que llegó en medio.
     */
    verificar('Y su update va CONDICIONADO a que no esté aprobada',
        str_contains($corregir, "where('estado', '!=', BitacoraHoras::APROBADA)"));

    verificar('Con su aviso cuando la carrera la gana la aprobación',
        str_contains($corregir, '$afectadas === 0'));

    echo PHP_EOL.'5. La corrección NO pisa una aprobación, reproducido'.PHP_EOL;

    /*
     * Se reproduce la carrera de verdad: se lee la jornada ANTES de que la
     * aprueben —como hace la petición que llegó primero a la pantalla—, se
     * aprueba por otro lado, y después se intenta corregir con el objeto viejo.
     */
    if ($expediente === null) {
        verificar('Hay expediente con el que reproducir la carrera', false);
    } else {
        $jornada = BitacoraHoras::create([
            'expediente_id' => $expediente->id,
            'fecha' => now()->subDay()->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '13:00',
            'minutos_descanso' => 0,
            'actividad' => 'Jornada para reproducir la carrera',
            'estado' => BitacoraHoras::CAPTURADA,
        ]);

        // La copia que tiene en memoria quien está corrigiendo.
        $comoLaViaElAlumno = BitacoraHoras::findOrFail($jornada->id);

        // Mientras tanto, el coordinador la aprueba.
        $jornada->forceFill(['estado' => BitacoraHoras::APROBADA])->save();

        $registradorHoras = app(App\Services\ProcesosFormativos\RegistradorDeHoras::class);

        $rechazo = null;

        try {
            $registradorHoras->corregir($comoLaViaElAlumno, [
                'fecha' => now()->subDay()->toDateString(),
                'hora_inicio' => '09:00',
                'hora_fin' => '20:00',
                'minutos_descanso' => 0,
                'actividad' => 'Corrección que llegó tarde',
            ], null);
        } catch (Throwable $e) {
            $rechazo = $e;
        }

        /*
         * Y se mira CUÁL excepción. Con «que reviente» basta cualquier fallo
         * —el expediente en un estado que no admite horas, por ejemplo— y la
         * comprobación pasaría sin ejercitar el guard de la carrera. Es el
         * `catch` pelado que este proyecto ya se cobró tres veces.
         */
        verificar('La corrección que llega tarde se REHÚSA, y por la razón correcta',
            $rechazo instanceof App\Exceptions\AvisoParaElUsuario
            && str_contains($rechazo->getMessage(), 'acaba de aprobarse'),
            $rechazo === null ? 'pasó' : mb_substr($rechazo->getMessage(), 0, 60));

        $final = $jornada->fresh();

        verificar('Y la jornada sigue APROBADA, no des-aprobada por detrás',
            $final?->estado === BitacoraHoras::APROBADA, (string) $final?->estado);

        verificar('Con sus horas originales, no las que nadie revisó',
            (string) $final?->hora_fin === '13:00:00' || str_starts_with((string) $final?->hora_fin, '13:00'),
            (string) $final?->hora_fin);
    }

    echo PHP_EOL.'6. Lo que la base NO puede sostener, y se dice'.PHP_EOL;

    /*
     * Las tres reglas son entre FILAS —«la suma de lo aplicado no pasa del
     * total», «este pago no está en otra factura VIVA», «esta jornada no se
     * encima con otra»— y MySQL no tiene restricciones de exclusión. Un único
     * no puede expresarlas, así que el bloqueo ES la defensa y no un refuerzo.
     * Se deja comprobado para que nadie lo lea como un descuido.
     */
    $unicos = fn (string $tabla) => collect($db->select('show index from '.$tabla))
        ->filter(fn ($i) => (int) $i->Non_unique === 0)
        ->pluck('Column_name')->unique()->values()->all();

    verificar('`pago_adeudo` sólo tiene su llave primaria: no puede topar la suma',
        $unicos('pago_adeudo') === ['pago_id', 'adeudo_id'],
        implode(', ', $unicos('pago_adeudo')));

    verificar('`factura_conceptos.pago_id` NO es único, y no debe serlo',
        ! in_array('pago_id', $unicos('factura_conceptos'), true),
        'un pago sí aparece en la cancelada y en la vigente');

    verificar('`bitacora_horas` no tiene único: el traslape no se puede declarar',
        $unicos('bitacora_horas') === ['id'],
        implode(', ', $unicos('bitacora_horas')));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
