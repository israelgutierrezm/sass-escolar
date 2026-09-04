<?php

/**
 * Casos, intervenciones y la bitácora de consulta (fase 5). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-casos.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **UNO abierto por matrícula.** Con dos, las intervenciones se reparten y
 *     acaban dos personas llamando al mismo alumno. Lo sostiene un único sobre
 *     columna generada, no un `SELECT` previo.
 *  2. **El folio es ATÓMICO y por año.** Nunca `MAX(folio)+1`.
 *  3. **La máquina de estados tiene UNA puerta**, con su bitácora y su bloqueo
 *     de fila. El destino que no cuelga del origen se rehúsa enumerando a dónde
 *     sí se puede.
 *  4. **Lo que no se alcanza NO VIAJA.** Es la pieza de privacidad del módulo:
 *     una nota reservada no puede salir en la respuesta de quien no la puede
 *     leer, y esconderla con un `v-if` la dejaría ahí.
 *  5. **Se DICE cuántas quedaron ocultas.** Callarlas haría creer que el caso
 *     está vacío.
 *  6. **La consulta deja rastro**, con cuántas se enseñaron y cuántas no.
 *  7. **Reabrir CREA otro caso** y conserva el cerrado: reescribirlo borraría la
 *     medición de recurrencia.
 *  8. **No dispara NADA.** Ni bajas, ni bloqueos, ni cambios de situación. Es la
 *     prohibición dura del pedido.
 */

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoEquipo;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\Intervencion;
use App\Models\Permanencia\MotivoCierreCaso;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\Permanencia\TipoIntervencion;
use App\Models\Tenant;
use App\Services\Permanencia\AbridorDeCaso;
use App\Services\Permanencia\AlcanceDeCasos;
use App\Services\Permanencia\RegistroDeIntervenciones;
use App\Services\Permanencia\TransicionDeCaso;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
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

/**
 * El código HTTP de una excepción, preguntándoselo al manejador de Laravel.
 *
 * `AvisoParaElUsuario` desciende de `HttpException`, pero una `QueryException`
 * también es `RuntimeException`: con un `catch` pelado, la explosión de un
 * índice se daría por buena. Es la trampa que este proyecto ya se cobró tres
 * veces.
 */
function codigoDe(Throwable $e): int
{
    return app(Illuminate\Contracts\Debug\ExceptionHandler::class)
        ->render(Illuminate\Http\Request::create('/'), $e)
        ->getStatusCode();
}

function usuarioCon(array $permisos, ?array $campus = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Casos',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $rol = Rol::create([
        'name' => 'zzcaso_'.random_int(100000, 999999),
        'nombre' => 'Prueba de casos',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->firstOrFail()->id,
    ]);

    $rol->syncPermissions($permisos);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_cas_'.random_int(100000, 999999),
        'email' => 'prueba_cas_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    foreach ($campus ?? [null] as $c) {
        $cuenta->persona->asignacionesRol()->create([
            'rol_id' => $rol->id, 'activo' => true, 'campus_id' => $c,
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Una foto de las tablas que un caso NO debe tocar. */
function huellaDeLoIntocable(): array
{
    $huella = [];

    foreach (['matricula_oferta', 'inscripcion', 'historial', 'asistencia_clase',
        'adeudos', 'bitacora_situacion_financiera'] as $t) {
        $huella[$t] = [
            'filas' => DB::table($t)->count(),
            'ultimo' => (string) DB::table($t)->max('updated_at'),
        ];
    }

    return $huella;
}

const PREFIJO = 'ZZCAS-';

$db->beginTransaction();

try {
    $abridor = app(AbridorDeCaso::class);
    $transiciones = app(TransicionDeCaso::class);
    $registro = app(RegistroDeIntervenciones::class);
    $alcance = app(AlcanceDeCasos::class);

    $TODOS = ['ver-alertas', 'validar-alertas', 'abrir-casos', 'asignar-casos',
        'registrar-intervenciones', 'ver-notas-reservadas', 'escalar-casos', 'cerrar-casos'];

    $quien = usuarioCon($TODOS);
    auth()->login($quien);

    $antes = huellaDeLoIntocable();

    echo '1. La máquina de estados'.PHP_EOL;

    verificar('Son OCHO estados, no los doce del pedido',
        count(EstadoCaso::cases()) === 8, (string) count(EstadoCaso::cases()));

    verificar('«Cerrado» es terminal y no tiene a dónde ir',
        EstadoCaso::Cerrado->esTerminal() && EstadoCaso::Cerrado->siguientes() === []);

    verificar('Escalar y cerrar EXIGEN motivo; asignar no',
        EstadoCaso::Abierto->exigeMotivo(EstadoCaso::Cerrado)
        && EstadoCaso::Asignado->exigeMotivo(EstadoCaso::Escalado)
        && ! EstadoCaso::Abierto->exigeMotivo(EstadoCaso::Asignado));

    /*
     * Desde «contacto pendiente» se puede cerrar. Sin esa arista, los casos de
     * quien no se logra localizar se quedarían abiertos para siempre y la cola
     * dejaría de significar algo.
     */
    verificar('Desde «contacto pendiente» se puede cerrar',
        EstadoCaso::ContactoPendiente->puedePasarA(EstadoCaso::Cerrado));

    verificar('Un caso abierto NO puede saltar directo a resuelto',
        ! EstadoCaso::Abierto->puedePasarA(EstadoCaso::Resuelto));

    /*
     * La lista de estados que OCUPAN la matrícula está escrita DOS veces: aquí
     * en PHP y en el `CASE` de la columna generada, que evalúa MySQL. Sin quien
     * las cruce se separan el día que se agregue un estado, y el único empezaría
     * a permitir o impedir lo que no debe, sin fallar.
     */
    $sqlDeLaColumna = (string) DB::selectOne(
        "SELECT GENERATION_EXPRESSION g FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'casos_permanencia'
           AND COLUMN_NAME = 'matricula_si_abierto'"
    )->g;

    $ocupanEnPhp = EstadoCaso::queOcupan();
    $noOcupanEnPhp = array_values(array_diff(EstadoCaso::claves(), $ocupanEnPhp));

    verificar('Los estados que OCUPAN coinciden entre PHP y el SQL del único',
        count($noOcupanEnPhp) === 1
        && str_contains($sqlDeLaColumna, $noOcupanEnPhp[0]),
        json_encode($noOcupanEnPhp).' vs '.$sqlDeLaColumna);

    echo PHP_EOL.'2. El escenario'.PHP_EOL;

    /*
     * SIN caso abierto, y no la primera que aparezca.
     *
     * Sobre una matrícula que ya tiene uno, `abrir()` devuelve el existente —que
     * es lo correcto— y media suite mide entonces el caso de otro: folio, plazo
     * y riesgo de apertura salen los de aquél. Pasaba corriéndola sola y se cayó
     * en cuanto la escuela tuvo un caso sembrado. **Séptima vez que este
     * proyecto se cobra lo mismo**: una suite es dueña de su escenario.
     */
    $conCaso = DB::table('casos_permanencia')->whereNull('deleted_at')
        ->where('estado', '!=', EstadoCaso::Cerrado->value)->pluck('matricula_oferta_id');

    $matricula = MatriculaOferta::query()->whereHas('oferta', fn ($o) => $o->whereNotNull('campus_id'))
        ->whereNotIn('id', $conCaso)
        ->with('oferta')->firstOrFail();

    /*
     * En OTRO campus, y no en cualquiera: con las dos en el mismo plantel, «el
     * acotado no lo alcanza» pasaría por casualidad y el recorte no se estaría
     * comprobando. Es el hueco de escenario de siempre.
     */
    $otra = MatriculaOferta::query()->whereKeyNot($matricula->id)
        ->whereNotIn('id', $conCaso)
        ->whereHas('oferta', fn ($o) => $o->whereNotNull('campus_id')
            ->where('campus_id', '!=', $matricula->oferta->campus_id))
        ->with('oferta')->firstOrFail();

    verificar('Hay DOS matrículas en campus DISTINTOS para separar el alcance',
        $matricula->oferta->campus_id !== $otra->oferta->campus_id,
        $matricula->oferta->campus_id.' vs '.$otra->oferta->campus_id);

    $categoria = CategoriaSenal::query()->where('clave', 'asistencia')->firstOrFail();

    /*
     * DOS reglas, y hacen falta: `alertas.alerta_abierta_unica` impide dos
     * señales abiertas de la misma regla sobre la misma matrícula, así que sin
     * la segunda no se podría construir el caso que de verdad importa —a alguien
     * que ya tiene caso abierto le sale OTRA señal—.
     */
    $crearRegla = function (string $nombre) use ($categoria) {
        $r = ReglaAlerta::create([
            'nombre' => PREFIJO.$nombre,
            'categoria_id' => $categoria->id,
            'proveedor' => 'asistencia',
            'activa' => true,
        ]);

        $r->versiones()->create([
            'version' => 1,
            'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
            'metrica' => 'asistencia.porcentaje',
            'comparador' => '<', 'umbral' => 80,
            'ventana_tipo' => 'ciclo', 'cobertura_minima' => 1,
            'severidad' => 'alto', 'peso' => 3,
            'frecuencia' => 'diaria', 'cooldown_dias' => 14,
        ]);

        return $r->fresh('versiones');
    };

    $regla = $crearRegla('Faltas');
    $reglaDos = $crearRegla('Asistencia baja');
    $reglaTres = $crearRegla('Sin entregas');

    $crearAlerta = function (MatriculaOferta $m, string $triage, ?ReglaAlerta $cual = null)
        use ($regla, $categoria) {
        $cual ??= $regla;

        return Alerta::create([
            'matricula_oferta_id' => $m->id,
            'regla_id' => $cual->id,
            'regla_version_id' => $cual->versiones->first()->id,
            'categoria_id' => $categoria->id,
            'severidad' => 'alto',
            'estado_senal' => Alerta::ACTIVA,
            'estado_triage' => $triage,
            'valor_observado' => 55,
            'umbral' => 80,
            'cobertura' => 1,
            'evidencia' => ['medicion' => 55, 'umbral' => 80],
            'primera_vez_en' => now(),
            'ultima_evaluacion_en' => now(),
        ]);
    };

    $senal = $crearAlerta($matricula, Alerta::VALIDADA);
    $sinRevisar = $crearAlerta($otra, Alerta::NUEVA);

    /*
     * El riesgo tiene que EXISTIR antes de abrir el caso. Sin esta línea, la
     * matrícula no tenía ninguna fila y «se congela el riesgo de apertura» se
     * cumplía comparando null contra null: la comprobación pasaba con el
     * congelamiento quitado.
     */
    app(App\Services\Permanencia\CalculadoraDeRiesgo::class)->recalcular($matricula);

    $riesgoAlAbrir = App\Models\Permanencia\RiesgoMatricula::query()
        ->vigenteDe($matricula->id)->first();

    verificar('El escenario tiene riesgo calculado con el que comprobar el congelamiento',
        $riesgoAlAbrir !== null && $riesgoAlAbrir->puntaje > 0,
        (string) ($riesgoAlAbrir?->puntaje ?? 'sin riesgo'));

    echo PHP_EOL.'3. Abrir: sólo desde una señal VALIDADA'.PHP_EOL;

    try {
        $abridor->abrir($sinRevisar, $quien);
        verificar('Una señal sin revisar NO abre caso', false);
    } catch (Throwable $e) {
        verificar('Una señal sin revisar NO abre caso',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'validar'), $e->getMessage());
    }

    $sinRevisar->update(['estado_triage' => Alerta::DESCARTADA]);

    try {
        $abridor->abrir($sinRevisar, $quien);
        verificar('Una señal DESCARTADA tampoco', false);
    } catch (Throwable $e) {
        verificar('Una señal DESCARTADA tampoco, y con otro mensaje',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'descartó'), $e->getMessage());
    }

    $sinPermiso = usuarioCon(['ver-alertas']);

    try {
        $abridor->abrir($senal, $sinPermiso);
        verificar('Sin `abrir-casos` no se abre', false);
    } catch (Throwable $e) {
        verificar('Sin `abrir-casos` no se abre', codigoDe($e) === 403, $e->getMessage());
    }

    $caso = $abridor->abrir($senal, $quien, null, 48, '10.0.0.1');

    verificar('El caso nace ABIERTO', $caso->estado === EstadoCaso::Abierto, $caso->estado->value);

    verificar('Con folio CASO-año-consecutivo',
        (bool) preg_match('/^CASO-'.now()->year.'-\d{5}$/', $caso->folio), $caso->folio);

    /*
     * El campus se COPIA. Leerlo por relación haría que mover a alguien de
     * plantel cambiara de sitio un caso cerrado hace meses.
     */
    verificar('El campus se COPIÓ de la oferta, no se lee por relación',
        $caso->campus_id === $matricula->oferta->campus_id,
        $caso->campus_id.' vs '.$matricula->oferta->campus_id);

    verificar('El compromiso de primer contacto quedó fijado',
        $caso->sla_vence_en !== null
        && (int) round(now()->diffInHours($caso->sla_vence_en)) === 48,
        (string) $caso->sla_vence_en);

    verificar('La prioridad se DERIVÓ de la severidad de la señal',
        $caso->prioridad === 'alta', $caso->prioridad);

    /*
     * El riesgo AL ABRIR se CONGELA. Leerlo en vivo haría que un caso resuelto
     * se viera como si nunca hubiera hecho falta —el riesgo baja justamente
     * porque el caso funcionó—, y con eso se pierde la única forma de medir si
     * sirvió.
     */
    verificar('Y el riesgo del momento quedó CONGELADO en el caso',
        $caso->puntaje_apertura === $riesgoAlAbrir->puntaje
        && $caso->nivel_riesgo_apertura_id === $riesgoAlAbrir->nivelQueManda()?->id,
        $caso->puntaje_apertura.' vs '.$riesgoAlAbrir->puntaje);

    verificar('La señal quedó atada al caso',
        $caso->alertas()->whereKey($senal->id)->exists());

    /*
     * El renglón de apertura, con el origen en NULL. Sin él, «cuánto tarda un
     * caso en asignarse» no tendría desde cuándo contar.
     */
    $apertura = $caso->transiciones()->first();

    verificar('La apertura dejó su renglón, con el origen en NULL',
        $apertura !== null && $apertura->estado_origen === null
        && $apertura->estado_destino === EstadoCaso::Abierto,
        $apertura?->estado_destino?->value ?? 'sin renglón');

    verificar('Y guarda quién lo abrió y desde dónde',
        $apertura?->quien === $quien->id && $apertura?->ip === '10.0.0.1');

    echo PHP_EOL.'4. UNO abierto por matrícula'.PHP_EOL;

    $segunda = $crearAlerta($matricula, Alerta::VALIDADA, $reglaDos);
    $mismo = $abridor->abrir($segunda, $quien);

    verificar('Abrir sobre quien YA tiene caso devuelve el mismo, no crea otro',
        $mismo->id === $caso->id, $mismo->folio.' vs '.$caso->folio);

    verificar('Y le SUMA la señal nueva',
        $caso->alertas()->whereKey($segunda->id)->exists());

    verificar('Sigue habiendo UN solo caso abierto de esa matrícula',
        CasoPermanencia::query()->abiertos()->where('matricula_oferta_id', $matricula->id)->count() === 1);

    /*
     * Lo que de verdad lo impide es el ÚNICO de la base: dos coordinadores
     * mirando la misma señal pasan el `SELECT` los dos. Se comprueba saltándose
     * el servicio, que es la única forma de reproducir la carrera.
     */
    $exploto = false;

    try {
        DB::table('casos_permanencia')->insert([
            'folio' => PREFIJO.'DUPLICADO',
            'matricula_oferta_id' => $matricula->id,
            'estado' => EstadoCaso::Abierto->value,
            'prioridad' => 'media',
            'abierto_en' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $e) {
        $exploto = str_contains($e->getMessage(), '1062');
    }

    verificar('La BASE impide el segundo caso abierto, no sólo el SELECT previo', $exploto);

    /*
     * Y una alerta pertenece a UN caso: con dos, «de qué señales salió este
     * seguimiento» tendría dos respuestas.
     */
    $exploto = false;

    try {
        DB::table('caso_alerta')->insert([
            'caso_id' => $caso->id, 'alerta_id' => $senal->id,
            'sumada_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (Throwable $e) {
        $exploto = str_contains($e->getMessage(), '1062');
    }

    verificar('Una señal no se puede atar dos veces', $exploto);

    verificar('Y sumar la misma señal por el servicio es idempotente, sin reventar',
        (function () use ($abridor, $caso, $senal) {
            $abridor->sumarAlerta($caso, $senal);

            return $caso->alertas()->whereKey($senal->id)->count() === 1;
        })());

    echo PHP_EOL.'5. El folio, atómico y por año'.PHP_EOL;

    $otraSenal = $crearAlerta($otra, Alerta::VALIDADA, $reglaDos);
    $segundoCaso = $abridor->abrir($otraSenal, $quien);

    $n1 = (int) substr($caso->folio, -5);
    $n2 = (int) substr($segundoCaso->folio, -5);

    verificar('El segundo folio es el siguiente consecutivo',
        $n2 === $n1 + 1, $caso->folio.' → '.$segundoCaso->folio);

    verificar('La tabla del contador NO tiene id autoincremental',
        DB::selectOne("SHOW COLUMNS FROM contadores_caso WHERE Field = 'id'") === null,
        'un INSERT sobre una tabla con id pisa LAST_INSERT_ID');

    echo PHP_EOL.'6. Mover: una sola puerta'.PHP_EOL;

    try {
        $transiciones->mover($caso, EstadoCaso::Resuelto, $quien);
        verificar('Un destino que no cuelga del origen se rehúsa', false);
    } catch (Throwable $e) {
        verificar('Un destino que no cuelga del origen se rehúsa',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'Desde aquí se puede ir a'),
            $e->getMessage());
    }

    $soloLectura = usuarioCon(['ver-alertas']);

    try {
        $transiciones->mover($caso, EstadoCaso::Asignado, $soloLectura);
        verificar('Sin `asignar-casos` no se asigna', false);
    } catch (Throwable $e) {
        verificar('Sin `asignar-casos` no se asigna', codigoDe($e) === 403, $e->getMessage());
    }

    $caso = $transiciones->mover($caso, EstadoCaso::Asignado, $quien, null, null,
        ['responsable_id' => $quien->id]);

    verificar('Asignado, con su responsable', $caso->estado === EstadoCaso::Asignado
        && $caso->responsable_id === $quien->id);

    verificar('Y su renglón en la bitácora',
        $caso->transiciones()->where('estado_destino', 'asignado')->exists());

    verificar('Volver a mover al MISMO estado no hace nada y no revienta',
        $transiciones->mover($caso, EstadoCaso::Asignado, $quien)->estado === EstadoCaso::Asignado
        && $caso->transiciones()->where('estado_destino', 'asignado')->count() === 1);

    /*
     * La CARRERA: dos personas con la pantalla abierta. La guarda de fuera mira
     * el objeto en memoria y las dos la pasan; la de dentro, con la fila releída
     * y bloqueada, es la única que lo detiene. Sólo se puede comprobar con una
     * copia leída ANTES de que nadie moviera.
     */
    $copiaVieja = CasoPermanencia::query()->findOrFail($caso->id);
    $transiciones->mover($caso, EstadoCaso::EnIntervencion, $quien);
    $resultadoDeLaCarrera = $transiciones->mover($copiaVieja, EstadoCaso::EnIntervencion, $quien);

    verificar('La CARRERA no duplica el movimiento: gana quien llegó primero',
        $resultadoDeLaCarrera->estado === EstadoCaso::EnIntervencion
        && $caso->transiciones()->where('estado_destino', 'en_intervencion')->count() === 1,
        (string) $caso->transiciones()->where('estado_destino', 'en_intervencion')->count());

    try {
        $transiciones->mover($caso->fresh(), EstadoCaso::Escalado, $quien, '   ');
        verificar('Escalar sin motivo se rehúsa', false);
    } catch (Throwable $e) {
        verificar('Escalar sin motivo se rehúsa',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'a ciegas'), $e->getMessage());
    }

    echo PHP_EOL.'7. Las intervenciones y lo que su TIPO exige'.PHP_EOL;

    $caso = $caso->fresh();

    $conAcuerdos = TipoIntervencion::query()->where('exige_acuerdos', true)->first()
        ?? TipoIntervencion::query()->activos()->firstOrFail();

    $conAcuerdos->forceFill(['exige_acuerdos' => true, 'exige_proxima_fecha' => false])->save();

    try {
        $registro->registrar($caso, [
            'tipo_intervencion_id' => $conAcuerdos->id,
            'fecha' => now()->toDateString(),
        ], $quien);
        verificar('Un tipo que exige acuerdos no deja guardarlos vacíos', false);
    } catch (Throwable $e) {
        verificar('Un tipo que exige acuerdos no deja guardarlos vacíos',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'a qué se llegó'), $e->getMessage());
    }

    verificar('El primer contacto todavía no está anotado',
        $caso->fresh()->primer_contacto_en === null);

    $contacto = $registro->registrar($caso, [
        'tipo_intervencion_id' => $conAcuerdos->id,
        'fecha' => now()->toDateString(),
        'acuerdos' => 'Se acordó que entrega el trabajo el viernes.',
    ], $quien);

    verificar('Con acuerdos, se registra', $contacto->exists);

    /*
     * Una intervención REALIZADA marca el primer contacto. Es el indicador que
     * mide si esto sirve.
     */
    verificar('Y anota el PRIMER CONTACTO', $caso->fresh()->primer_contacto_en !== null);

    /*
     * El reloj se mueve HACIA ATRÁS a propósito. Con las dos intervenciones
     * dentro del mismo segundo, reescribir la marca da el MISMO valor y la
     * comprobación pasa aunque la guarda no exista — es la coincidencia de reloj
     * que ya se cobró la corrección de una liberación formativa.
     */
    $caso->forceFill(['primer_contacto_en' => now()->subHours(6)])->save();
    $marca = $caso->fresh()->primer_contacto_en;

    $registro->registrar($caso->fresh(), [
        'tipo_intervencion_id' => $conAcuerdos->id,
        'fecha' => now()->toDateString(),
        'acuerdos' => 'Segunda llamada.',
    ], $quien);

    verificar('La segunda no lo mueve: el primero es el primero',
        (string) $caso->fresh()->primer_contacto_en === (string) $marca,
        $caso->fresh()->primer_contacto_en.' vs '.$marca);

    /*
     * Una PROGRAMADA no cuenta como contacto: agendar una cita no es haber
     * hablado con nadie, y contarla arruinaría el indicador.
     */
    $senal3 = $crearAlerta($otra, Alerta::VALIDADA, $reglaTres);
    $tercero = CasoPermanencia::query()->abiertos()
        ->where('matricula_oferta_id', $otra->id)->firstOrFail();

    $registro->registrar($tercero, [
        'tipo_intervencion_id' => $conAcuerdos->id,
        'fecha' => now()->toDateString(),
        'acuerdos' => 'Se le cita el lunes.',
        'estado' => Intervencion::PROGRAMADA,
    ], $quien);

    verificar('Una intervención PROGRAMADA no cuenta como primer contacto',
        $tercero->fresh()->primer_contacto_en === null);

    $sinReserva = TipoIntervencion::query()->activos()->get()
        ->firstWhere(fn ($t) => ! $t->permite_reservada)
        ?? TipoIntervencion::query()->activos()->firstOrFail();

    $sinReserva->forceFill(['permite_reservada' => false, 'exige_acuerdos' => false])->save();

    try {
        $registro->registrar($caso->fresh(), [
            'tipo_intervencion_id' => $sinReserva->id,
            'fecha' => now()->toDateString(),
            'visibilidad' => Intervencion::RESERVADA,
        ], $quien);
        verificar('Un tipo que no admite reserva no se puede marcar reservado', false);
    } catch (Throwable $e) {
        verificar('Un tipo que no admite reserva no se puede marcar reservado',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'reservada'), $e->getMessage());
    }

    $sinIntervenir = usuarioCon(['ver-alertas', 'asignar-casos']);

    try {
        $registro->registrar($caso->fresh(), [
            'tipo_intervencion_id' => $sinReserva->id,
            'fecha' => now()->toDateString(),
        ], $sinIntervenir);
        verificar('Sin `registrar-intervenciones` no se registra', false);
    } catch (Throwable $e) {
        verificar('Sin `registrar-intervenciones` no se registra',
            codigoDe($e) === 403, $e->getMessage());
    }

    echo PHP_EOL.'8. Lo que NO se alcanza no viaja'.PHP_EOL;

    $reservable = TipoIntervencion::query()->activos()->get()
        ->firstWhere(fn ($t) => $t->id !== $sinReserva->id)
        ?? $conAcuerdos;

    $reservable->forceFill(['permite_reservada' => true, 'exige_acuerdos' => false,
        'exige_proxima_fecha' => false, 'exige_evidencia' => false])->save();

    $nota = $registro->registrar($caso->fresh(), [
        'tipo_intervencion_id' => $reservable->id,
        'fecha' => now()->toDateString(),
        'objetivo' => 'Situación familiar que el alumno pidió no circular.',
        'visibilidad' => Intervencion::RESERVADA,
    ], $quien);

    $deEquipo = $registro->registrar($caso->fresh(), [
        'tipo_intervencion_id' => $reservable->id,
        'fecha' => now()->toDateString(),
        'objetivo' => 'Todavía sin resolver; no circular fuera del equipo.',
        'visibilidad' => Intervencion::VISIBLE_EQUIPO,
    ], $quien);

    $conReservadas = usuarioCon(['ver-alertas', 'registrar-intervenciones', 'ver-notas-reservadas']);
    $sinReservadas = usuarioCon(['ver-alertas', 'registrar-intervenciones']);

    $leeTodo = $registro->paraLeer($caso->fresh(), $conReservadas);
    $leePoco = $registro->paraLeer($caso->fresh(), $sinReservadas);

    verificar('Con `ver-notas-reservadas` la nota reservada VIAJA',
        $leeTodo['visibles']->contains('id', $nota->id));

    verificar('Sin el permiso NO viaja: no está en la respuesta',
        ! $leePoco['visibles']->contains('id', $nota->id));

    /*
     * Se DICE cuántas quedaron ocultas. Callarlas haría creer que el caso está
     * vacío, y quien lo atiende tiene derecho a saber que hay algo que no ve.
     */
    verificar('Y se dice CUÁNTAS quedaron ocultas',
        $leePoco['ocultas'] === 2 && $leeTodo['ocultas'] === 0,
        $leePoco['ocultas'].' vs '.$leeTodo['ocultas']);

    /*
     * La de EQUIPO: quien no está en él tampoco la ve. Y quien alcanza lo más
     * restringido alcanza lo menos —sin esa rama, el rol con el permiso más alto
     * vería lo reservado y NO lo del equipo, que es al revés de lo que espera
     * cualquiera—.
     */
    verificar('La nota de EQUIPO no la ve quien no está en él',
        ! $leePoco['visibles']->contains('id', $deEquipo->id));

    verificar('Y sí la ve quien alcanza lo reservado',
        $leeTodo['visibles']->contains('id', $deEquipo->id));

    CasoEquipo::create([
        'caso_id' => $caso->id,
        'persona_id' => $sinReservadas->persona_id,
        'papel' => 'Tutoría',
        'desde' => now()->toDateString(),
    ]);

    $leeConEquipo = $registro->paraLeer($caso->fresh(), $sinReservadas);

    verificar('Estando en el EQUIPO sí ve la nota de equipo',
        $leeConEquipo['visibles']->contains('id', $deEquipo->id));

    verificar('Pero la reservada sigue sin viajar: el equipo no da ese permiso',
        ! $leeConEquipo['visibles']->contains('id', $nota->id)
        && $leeConEquipo['ocultas'] === 1, (string) $leeConEquipo['ocultas']);

    /*
     * El equipo dice quién PARTICIPA, no quién puede entrar. Confundirlo
     * convertiría una lista de trabajo en un mecanismo de autorización paralelo.
     */
    CasoEquipo::query()->where('caso_id', $caso->id)
        ->where('persona_id', $sinReservadas->persona_id)
        ->update(['hasta' => now()->subDay()->toDateString()]);

    verificar('Quien SALIÓ del equipo deja de ver sus notas',
        ! $registro->paraLeer($caso->fresh(), $sinReservadas)['visibles']->contains('id', $deEquipo->id));

    /*
     * El responsable entra al equipo aunque no esté en la tabla: es quien lleva
     * el caso, y dejarlo fuera haría que no viera sus propias notas de equipo.
     */
    verificar('El responsable cuenta como equipo aunque no esté en la tabla',
        in_array($quien->persona_id, $registro->personasDelEquipo($caso->fresh()), true));

    echo PHP_EOL.'9. La bitácora de consulta'.PHP_EOL;

    $antesDeConsultar = $caso->accesos()->count();

    $registro->registrarConsulta($caso->fresh(), $sinReservadas, '10.0.0.9',
        $leePoco['visibles']->count(), $leePoco['ocultas']);

    $ultimo = $caso->accesos()->latest('creado_en')->first();

    verificar('Abrir la ficha deja rastro',
        $caso->accesos()->count() === $antesDeConsultar + 1);

    verificar('Con quién, cuántas vio y cuántas quedaron ocultas',
        $ultimo?->persona_id === $sinReservadas->persona_id
        && $ultimo?->reservadas_ocultas === 2 && $ultimo?->ip === '10.0.0.9',
        json_encode([$ultimo?->intervenciones_vistas, $ultimo?->reservadas_ocultas]));

    /*
     * Se registra la CONSULTA, nunca el contenido: una auditoría que copie lo
     * vigilado multiplica el problema que intenta resolver.
     */
    $columnas = collect(DB::select('SHOW COLUMNS FROM accesos_caso'))->pluck('Field')->all();

    verificar('La bitácora NO copia el contenido de lo consultado',
        empty(array_intersect($columnas, ['objetivo', 'acuerdos', 'resultado', 'nota', 'contenido'])),
        implode(', ', $columnas));

    echo PHP_EOL.'10. El alcance por campus'.PHP_EOL;

    $ajeno = usuarioCon($TODOS, [$otra->oferta->campus_id]);

    verificar('El acotado a otro plantel NO alcanza este caso',
        ! $alcance->alcanza($caso->fresh(), $ajeno));

    try {
        $alcance->exigirQueAlcance($caso->fresh(), $ajeno);
        verificar('Y pedirlo responde 404, nunca 403', false);
    } catch (Throwable $e) {
        verificar('Y pedirlo responde 404, nunca 403 —un 403 confirmaría que existe—',
            codigoDe($e) === 404, (string) codigoDe($e));
    }

    try {
        $transiciones->mover($caso->fresh(), EstadoCaso::EnSeguimiento, $ajeno);
        verificar('El SERVICIO también lo comprueba: el id viaja por la URL', false);
    } catch (Throwable $e) {
        verificar('El SERVICIO también lo comprueba: el id viaja por la URL',
            codigoDe($e) === 404, (string) codigoDe($e));
    }

    try {
        $registro->registrar($caso->fresh(), [
            'tipo_intervencion_id' => $sinReserva->id, 'fecha' => now()->toDateString(),
        ], $ajeno);
        verificar('Tampoco se interviene en un caso de otro plantel', false);
    } catch (Throwable $e) {
        verificar('Tampoco se interviene en un caso de otro plantel',
            codigoDe($e) === 404, (string) codigoDe($e));
    }

    $acotada = $alcance->acotar(CasoPermanencia::query(), $ajeno)->pluck('id');

    verificar('El listado del acotado no trae el caso ajeno',
        ! $acotada->contains($caso->id) && $acotada->contains($segundoCaso->id),
        $acotada->implode(', '));

    /*
     * Un caso SIN campus se le enseña a todos: pasa cuando la oferta no lo tenía
     * al abrirse, y esconderlo de todo el mundo lo convertiría en un caso que
     * nadie atiende.
     */
    $segundoCaso->forceFill(['campus_id' => null])->save();

    verificar('Un caso sin campus lo alcanza cualquiera',
        $alcance->alcanza($segundoCaso->fresh(), $ajeno));

    $segundoCaso->forceFill(['campus_id' => $otra->oferta->campus_id])->save();

    echo PHP_EOL.'11. Cerrar y reabrir'.PHP_EOL;

    $motivoExito = MotivoCierreCaso::query()->activos()->get()
        ->firstWhere(fn ($m) => $m->cuenta_como_exito === true)
        ?? MotivoCierreCaso::query()->activos()->firstOrFail();

    $caso = $caso->fresh();

    $sinCerrar = usuarioCon(['ver-alertas', 'registrar-intervenciones']);

    try {
        $transiciones->mover($caso, EstadoCaso::Cerrado, $sinCerrar, 'Se atendió.');
        verificar('Sin `cerrar-casos` no se cierra', false);
    } catch (Throwable $e) {
        verificar('Sin `cerrar-casos` no se cierra', codigoDe($e) === 403, $e->getMessage());
    }

    try {
        $transiciones->mover($caso, EstadoCaso::Cerrado, $quien, '');
        verificar('Cerrar sin motivo se rehúsa', false);
    } catch (Throwable $e) {
        verificar('Cerrar sin motivo se rehúsa',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'auditar'), $e->getMessage());
    }

    $caso = $transiciones->mover($caso, EstadoCaso::Cerrado, $quien,
        'Mejoró la asistencia tras el reacomodo de horario.', null,
        ['motivo_cierre_id' => $motivoExito->id, 'resultado' => 'Asistencia por encima del 85 %.',
            'cerrado_en' => now()]);

    verificar('Cerrado, con su motivo del catálogo y su resultado',
        $caso->estado === EstadoCaso::Cerrado && $caso->motivo_cierre_id === $motivoExito->id
        && $caso->cerrado_en !== null);

    /*
     * De la BANDERA del motivo sale si el acompañamiento sirvió. Con texto libre
     * habría que leer trescientas frases para saberlo.
     */
    verificar('Y de su bandera sale si sirvió',
        $caso->motivoCierre->cuenta_como_exito === true);

    verificar('Un caso cerrado ya no admite intervenciones',
        (function () use ($registro, $caso, $sinReserva, $quien) {
            try {
                $registro->registrar($caso->fresh(), [
                    'tipo_intervencion_id' => $sinReserva->id, 'fecha' => now()->toDateString(),
                ], $quien);

                return false;
            } catch (Throwable $e) {
                return codigoDe($e) === 422 && str_contains($e->getMessage(), 'reabrirlo');
            }
        })());

    verificar('Y la matrícula queda LIBRE para un caso nuevo',
        CasoPermanencia::query()->abiertos()->where('matricula_oferta_id', $matricula->id)->count() === 0);

    try {
        $abridor->reabrir($caso->fresh(), '   ', $quien);
        verificar('Reabrir exige motivo, y lo comprueba el SERVICIO', false);
    } catch (Throwable $e) {
        verificar('Reabrir exige motivo, y lo comprueba el SERVICIO',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'la situación volvió'),
            $e->getMessage());
    }

    $sinReabrir = usuarioCon(['ver-alertas', 'registrar-intervenciones']);

    try {
        $abridor->reabrir($caso->fresh(), 'Volvió a faltar.', $sinReabrir);
        verificar('Sin `cerrar-casos` no se reabre', false);
    } catch (Throwable $e) {
        verificar('Sin `cerrar-casos` no se reabre', codigoDe($e) === 403, $e->getMessage());
    }

    $reabierto = $abridor->reabrir($caso->fresh(),
        'Volvió a faltar tres semanas después del cierre.', $quien);

    verificar('Reabrir crea un caso NUEVO, con otro folio',
        $reabierto->id !== $caso->id && $reabierto->folio !== $caso->folio,
        $caso->folio.' → '.$reabierto->folio);

    verificar('Que apunta al cerrado', $reabierto->caso_origen_id === $caso->id);

    /*
     * El cerrado se conserva ENTERO. Reescribirlo borraría la medición de
     * recurrencia, que es de lo poco que dice si el acompañamiento funcionó.
     */
    $cerrado = $caso->fresh();

    verificar('Y el cerrado se conserva entero, con su motivo y su resultado',
        $cerrado->estado === EstadoCaso::Cerrado
        && $cerrado->motivo_cierre_id === $motivoExito->id
        && $cerrado->resultado !== null);

    verificar('El motivo de la reapertura queda en la bitácora del nuevo',
        str_contains((string) $reabierto->transiciones()->first()?->motivo, 'Volvió a faltar'));

    try {
        $abridor->reabrir($reabierto->fresh(), 'Todavía no está cerrado.', $quien);
        verificar('Sólo se reabre lo CERRADO', false);
    } catch (Throwable $e) {
        verificar('Sólo se reabre lo CERRADO',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'sigue abierto'), $e->getMessage());
    }

    echo PHP_EOL.'12. El SLA'.PHP_EOL;

    $reabierto->forceFill([
        'abierto_en' => now()->subHours(9),
        'sla_vence_en' => now()->subHours(3),
        'primer_contacto_en' => null,
    ])->save();

    verificar('Un caso sin contacto y con el plazo pasado está vencido',
        $reabierto->fresh()->slaVencido());

    verificar('Y sale en el scope',
        CasoPermanencia::query()->slaVencido()->whereKey($reabierto->id)->exists());

    $reabierto->forceFill(['primer_contacto_en' => now()->subHours(4)])->save();

    /*
     * Atendido a tiempo NO está vencido aunque siga abierto. Contarlo llenaría
     * la cola de casos que ya se atendieron.
     */
    verificar('Con el contacto hecho deja de contar como vencido, aunque siga abierto',
        ! $reabierto->fresh()->slaVencido()
        && ! CasoPermanencia::query()->slaVencido()->whereKey($reabierto->id)->exists());

    verificar('Y las horas hasta el primer contacto se miden POSITIVAS',
        $reabierto->fresh()->horasHastaElPrimerContacto() === 5,
        (string) $reabierto->fresh()->horasHastaElPrimerContacto());

    verificar('Sin contacto, la medida es NULL y no cero',
        (new CasoPermanencia(['abierto_en' => now()]))->horasHastaElPrimerContacto() === null);

    echo PHP_EOL.'13. La pantalla: lo que el controlador devuelve'.PHP_EOL;

    /*
     * Se invoca al CONTROLADOR y se leen sus props, no se reimplementa la
     * consulta: lo que revienta lo arma el controlador —una relación mal
     * nombrada, una columna que no existe— y comprobar el servicio no lo ve. Es
     * la lección de `prueba-listados` y de la suite de caja.
     */
    $controlador = app(App\Http\Controllers\Permanencia\CasoController::class);

    $peticionDe = function (Usuario $u, array $query = []) {
        $p = Request::create('/permanencia/casos', 'GET', $query);
        $p->setUserResolver(fn () => $u);
        auth()->setUser($u);

        return $p;
    };

    $indice = $controlador->index($peticionDe($quien))->toResponse(
        $peticionDe($quien))->getOriginalContent()['page']['props'] ?? null;

    verificar('El listado responde con sus casos y su resumen',
        isset($indice['casos']['data'], $indice['resumen']['abiertos']),
        implode(', ', array_keys($indice ?? [])));

    $ficha = $controlador->show($peticionDe($sinReservadas), $reabierto->id)->toResponse(
        $peticionDe($sinReservadas))->getOriginalContent()['page']['props'];

    verificar('La ficha responde con caso, intervenciones, tareas, equipo y consultas',
        isset($ficha['caso']['folio'], $ficha['intervenciones'], $ficha['tareas'],
            $ficha['equipo'], $ficha['consultas'], $ficha['destinos']));

    verificar('Y con el conteo de reservadas ocultas',
        array_key_exists('reservadas_ocultas', $ficha));

    /*
     * Abrir la ficha DEJA CONSTANCIA. Es lo único que la suite puede comprobar
     * del camino de verdad: el servicio se puede llamar sin registrar nada.
     */
    verificar('Abrir la ficha por el controlador escribió en la bitácora',
        $reabierto->accesos()->where('persona_id', $sinReservadas->persona_id)->exists());

    try {
        $controlador->show($peticionDe($ajeno), $caso->id);
        verificar('La ficha de otro plantel responde 404', false);
    } catch (Throwable $e) {
        verificar('La ficha de otro plantel responde 404', codigoDe($e) === 404,
            codigoDe($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');
    }

    $propsAcotado = $controlador->index($peticionDe($ajeno))->toResponse(
        $peticionDe($ajeno))->getOriginalContent()['page']['props'];

    /*
     * El RESUMEN va acotado igual que la lista, y se compara con la LISTA que el
     * mismo controlador devolvió — no con el global. Contra el global, un `<=`
     * se cumple también cuando son iguales, o sea con el recorte quitado: la
     * comprobación pasaba pase lo que pase.
     */
    verificar('El resumen del acotado cuadra con SU propia lista, no con la global',
        $propsAcotado['resumen']['abiertos'] === count($propsAcotado['casos']['data'])
        && $propsAcotado['resumen']['abiertos'] < $indice['resumen']['abiertos'],
        $propsAcotado['resumen']['abiertos'].' vs lista '.count($propsAcotado['casos']['data'])
        .' vs global '.$indice['resumen']['abiertos']);

    /*
     * El motivo del catálogo al cerrar lo exige el CONTROLADOR, no el servicio:
     * `mover()` no sabe de motivos de cierre —recibe columnas ya resueltas—, así
     * que sólo se puede comprobar por este camino.
     */
    $paraCerrar = CasoPermanencia::query()->abiertos()
        ->where('matricula_oferta_id', $otra->id)->firstOrFail();

    $post = function (array $datos) use ($quien) {
        $p = Request::create('/', 'POST', $datos);
        $p->setUserResolver(fn () => $quien);
        auth()->setUser($quien);

        return $p;
    };

    try {
        $controlador->mover($post(['estado' => 'cerrado', 'motivo' => 'Ya se atendió.']),
            $paraCerrar->id);
        verificar('Cerrar por el controlador SIN motivo del catálogo se rehúsa', false);
    } catch (Throwable $e) {
        verificar('Cerrar por el controlador SIN motivo del catálogo se rehúsa',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'del catálogo'),
            $e->getMessage());
    }

    $controlador->mover($post([
        'estado' => 'cerrado',
        'motivo' => 'Se atendió y la situación mejoró.',
        'motivo_cierre_id' => $motivoExito->id,
        'resultado' => 'Sin más faltas en cuatro semanas.',
    ]), $paraCerrar->id);

    verificar('Con el motivo, el controlador cierra y escribe la fecha',
        $paraCerrar->fresh()->estado === EstadoCaso::Cerrado
        && $paraCerrar->fresh()->cerrado_en !== null
        && $paraCerrar->fresh()->motivo_cierre_id === $motivoExito->id);

    echo PHP_EOL.'14. Lo que este módulo NO hace'.PHP_EOL;

    $despues = huellaDeLoIntocable();

    verificar('Nada de esto tocó matrículas, historial, asistencia ni adeudos',
        $antes === $despues, json_encode(array_keys(array_diff_assoc(
            array_map('json_encode', $antes), array_map('json_encode', $despues)))));

    /*
     * Ninguna ruta EDITA ni BORRA una intervención ni una transición. Un
     * expediente de acompañamiento que se puede reescribir no sirve de
     * evidencia, y la bitácora es la mitad de lo que protege al alumno.
     */
    $rutas = collect(app('router')->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'tenant.permanencia.casos.'))
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
        ->values();

    verificar('No hay ninguna ruta que BORRE una intervención o la historia',
        $rutas->every(fn (string $r) => ! (str_contains($r, 'DELETE')
            && (str_contains($r, 'intervenciones') || str_contains($r, 'historia')))),
        $rutas->implode(' · '));

    /*
     * Y el vocabulario. El pedido lo prohíbe explícitamente: nada que describa a
     * la persona en vez de a la situación. Se barre la FRASE, no una de sus
     * formas —la lista negra en singular dejaba pasar el plural—.
     */
    $prohibidas = ['problematic', 'desertor', 'probable abandono', 'probabilidad de abandono',
        'moroso', 'en riesgo de'];

    $textos = collect(glob(__DIR__.'/../resources/js/Pages/Permanencia/Casos/*.vue'))
        ->merge(glob(__DIR__.'/../app/Services/Permanencia/*.php'))
        ->merge(glob(__DIR__.'/../app/Models/Permanencia/*.php'))
        ->map(fn ($f) => mb_strtolower((string) file_get_contents($f)));

    verificar('El barrido de lenguaje NO pasó por vacío',
        $textos->count() >= 10, (string) $textos->count());

    foreach ($prohibidas as $mala) {
        verificar('No se usa «'.$mala.'» en ninguna cadena del módulo',
            $textos->every(fn (string $t) => ! str_contains($t, $mala)));
    }

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
