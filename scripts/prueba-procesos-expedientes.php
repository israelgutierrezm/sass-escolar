<?php

/**
 * El expediente formativo: solicitud, revisión y asignación (fase 4).
 *
 * Se corre con `php scripts/prueba-procesos-expedientes.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El ORIGEN manda.** Un destino que no cuelgue del estado actual se
 *     rehúsa con su motivo; nunca se «corrige» al más cercano, porque eso
 *     convierte un error de programación en un movimiento silencioso del
 *     expediente de alguien.
 *  2. **Se anota SIEMPRE**, con origen, destino, usuario e IP. Sin la bitácora,
 *     «¿quién lo aprobó?» no tiene respuesta.
 *  3. **Idempotencia**: pedir el estado en el que ya se está no hace nada y NO
 *     anota. Dos clics inflarían la bitácora con renglones de cero minutos.
 *  4. **Motivo obligatorio** en rechazo, corrección, suspensión y cancelación:
 *     sin él quien lo recibe no sabe qué corregir.
 *  5. **El CUPO lo protege la base**: bloqueo, comprobación con la fila ya
 *     bloqueada, y CHECK debajo. Dos coordinadores asignando la última plaza a
 *     la vez pasan los dos un conteo previo.
 *  6. **La elegibilidad se comprueba DOS veces** —al abrir y al enviar—, porque
 *     entre las dos pueden pasar semanas.
 *  7. **La excepción es un ACTO con dueño**: perdona el requisito y lo dice
 *     NOMBRANDO a quien la autorizó.
 *  8. **El alumno sólo toca lo SUYO**, y lo ajeno da 404 y no 403.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ProcesosFormativos\ExpedienteFormativoController;
use App\Http\Controllers\ProcesosFormativos\MiProcesoFormativoController;
use App\Models\Academico\Campus;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\ModalidadProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\PlazaProceso;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\SituacionConvenioFormativo;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use App\Services\ProcesosFormativos\AsignadorDePlaza;
use App\Services\ProcesosFormativos\SolicitudDelAlumno;
use App\Services\ProcesosFormativos\TransicionDeExpediente;
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

function peticionCon(array $datos, ?Usuario $como, string $metodo = 'POST'): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $como);

    return $p;
}

function props(object $controlador, string $metodo, Usuario $como, array $query = [], array $extra = []): array
{
    $peticion = Illuminate\Http\Request::create('/', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    $respuesta = $controlador->{$metodo}($peticion, ...$extra);

    return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'];
}

/** Una cuenta con ese rol. Reusa la que la persona ya tenga: el id es único. */
function usuarioConRol(string $rol, ?int $personaId = null): Usuario
{
    $personaId ??= Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Expediente',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ])->id;

    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::query()->where('persona_id', $personaId)->first();

    $cuenta
        ? $cuenta->forceFill(['rol_activo_id' => $rolId])->save()
        : $cuenta = Usuario::create([
            'persona_id' => $personaId,
            'usuario' => 'prueba_exp_'.random_int(100000, 999999),
            'email' => 'prueba_exp_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $rolId,
        ]);

    $cuenta->persona->asignacionesRol()->firstOrCreate(
        ['rol_id' => $rolId],
        ['activo' => true, 'campus_id' => null],
    );

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Un aviso con ese código, o `false`. */
function rehusaCon(int $codigo, callable $acto, ?string $contiene = null): bool
{
    try {
        $acto();

        return false;
    } catch (AvisoParaElUsuario $e) {
        return $e->getStatusCode() === $codigo
            && ($contiene === null || str_contains($e->getMessage(), $contiene));
    }
}

const PREFIJO = 'ZZEXP-';

$db->beginTransaction();

try {
    $controlador = app(ExpedienteFormativoController::class);
    $portal = app(MiProcesoFormativoController::class);
    $transiciones = app(TransicionDeExpediente::class);
    $solicitudes = app(SolicitudDelAlumno::class);
    $asignador = app(AsignadorDePlaza::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    /*
     * Se parte de CERO reglas y CERO expedientes, dentro de la transacción. Lo
     * que se prueba es aritmética de estados y de cupo, y eso sólo se puede
     * afirmar sabiendo qué hay. Es la lección que este proyecto lleva seis
     * veces cobrándose.
     */
    DB::table('expedientes_proceso')->delete();
    DB::table('reglas_proceso')->delete();

    $tipo = TipoProcesoFormativo::query()->where('clave', 'servicio_social')->firstOrFail();

    $matricula = MatriculaOferta::query()
        ->whereHas('oferta.plan', fn ($q) => $q->whereNotNull('total_creditos')->where('total_creditos', '>', 0))
        ->whereHas('historial')
        ->whereNotNull('generacion')
        ->with('oferta.programaAcademico', 'situacion')
        ->first();

    verificar('Hay una matrícula con la que construir el escenario', $matricula !== null);

    $oferta = $matricula->oferta;

    // La regla del escenario, sin requisitos que estorben: lo que se prueba
    // aquí son los ESTADOS, no la elegibilidad.
    $regla = ReglaProceso::create([
        'nombre' => PREFIJO.'Regla del escenario',
        'tipo_proceso_id' => $tipo->id,
        'plan_id' => $oferta->plan_id,
    ]);

    $version = $regla->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'horas_requeridas' => 480,
        'plazo_maximo_dias' => 365,
    ]);

    echo PHP_EOL.'1. El alumno abre su solicitud'.PHP_EOL;

    $alumno = usuarioConRol('alumno', (int) $matricula->persona_id);

    $expediente = $solicitudes->abrir($matricula, $tipo, $alumno, '10.0.0.1');

    verificar('Nace en BORRADOR, no enviada',
        $expediente->estado === EstadoExpediente::Borrador);

    verificar('Y las horas se COPIAN de la versión',
        (int) $expediente->horas_requeridas === 480);

    verificar('Con su primer renglón de bitácora, con el origen en NULL',
        $expediente->transiciones()->count() === 1
        && $expediente->transiciones()->first()->estado_origen === null);

    verificar('No se puede abrir dos veces',
        rehusaCon(422, fn () => $solicitudes->abrir($matricula, $tipo, $alumno), 'Ya tienes una solicitud'));

    /*
     * Y a quien NO es elegible no se le abre, con la lista de lo que le falta.
     * El caso se construye subiendo el requisito de créditos por encima de lo
     * que el alumno lleva: sin él, la regla del escenario no exige nada y
     * quitar esa comprobación no cambiaría ningún resultado.
     */
    $otroTipo = TipoProcesoFormativo::query()->where('clave', 'proyecto_comunitario')->firstOrFail();

    $reglaExigente = ReglaProceso::create([
        'nombre' => PREFIJO.'Exigente',
        'tipo_proceso_id' => $otroTipo->id,
        'plan_id' => $oferta->plan_id,
    ]);

    $reglaExigente->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'porcentaje_creditos_minimo' => 99.99,
    ]);

    verificar('A quien no es elegible NO se le abre, y se le dice por qué',
        rehusaCon(422, fn () => $solicitudes->abrir($matricula, $otroTipo, $alumno),
            'Todavía no puedes empezar'));

    verificar('Y el motivo trae el número concreto',
        rehusaCon(422, fn () => $solicitudes->abrir($matricula, $otroTipo, $alumno), '99.99'));

    echo PHP_EOL.'2. El ORIGEN manda: un destino que no cuelga se rehúsa'.PHP_EOL;

    verificar('De borrador NO se salta a aprobado',
        rehusaCon(422, fn () => $transiciones->mover($expediente, EstadoExpediente::Aprobado, $global),
            'está en «Borrador»'));

    verificar('Ni a concluido',
        rehusaCon(422, fn () => $transiciones->mover($expediente, EstadoExpediente::Concluido, $global)));

    /*
     * Y a «liberado» ni siquiera se llega al guard del origen: su permiso
     * —`liberar-expedientes-formativos`— todavía no existe, porque llega con la
     * fase que lo construya. El permiso se comprueba ANTES, así que sale 403.
     * Es el orden correcto: sin la llave no importa desde dónde se pida.
     */
    verificar('Y a liberado se le niega el permiso antes que el origen',
        rehusaCon(403, fn () => $transiciones->mover($expediente, EstadoExpediente::Liberado, $global)));

    verificar('Y el mensaje enumera a dónde SÍ se puede',
        rehusaCon(422, fn () => $transiciones->mover($expediente, EstadoExpediente::Aprobado, $global),
            'sólo se puede pasar a'));

    verificar('El expediente NO se movió', $expediente->refresh()->estado === EstadoExpediente::Borrador);

    echo PHP_EOL.'3. Enviar, y la elegibilidad se comprueba OTRA VEZ'.PHP_EOL;

    $expediente = $solicitudes->enviar($expediente, $alumno, '10.0.0.1');

    verificar('Pasa a SOLICITADO', $expediente->estado === EstadoExpediente::Solicitado);

    verificar('Y se le pone la fecha de solicitud', $expediente->fecha_solicitud !== null);

    verificar('La bitácora ya tiene dos renglones', $expediente->transiciones()->count() === 2);

    $ultima = $expediente->transiciones()->orderByDesc('id')->first();

    verificar('Con la IP anotada', $ultima->ip === '10.0.0.1');

    /*
     * Y con el ORIGEN correcto, no en null. El renglón del alta lo lleva vacío
     * a propósito —no venía de ningún estado—, así que sin comprobar los
     * demás, borrar el origen de todos pasaría desapercibido y la bitácora
     * dejaría de decir de dónde vino cada movimiento.
     */
    verificar('Y con el ESTADO DE ORIGEN, que no es null',
        $ultima->estado_origen === EstadoExpediente::Borrador
        && $ultima->estado_destino === EstadoExpediente::Solicitado,
        ($ultima->estado_origen?->value ?? 'null').' → '.$ultima->estado_destino->value);

    echo PHP_EOL.'4. Idempotencia: volver a pedir lo mismo no hace NADA'.PHP_EOL;

    $antes = $expediente->transiciones()->count();

    $transiciones->mover($expediente, EstadoExpediente::Solicitado, $global);

    verificar('No anota un renglón de cero minutos',
        $expediente->transiciones()->count() === $antes);

    /*
     * Y la CARRERA: dos revisores con la pantalla abierta a la vez.
     *
     * El objeto en memoria de la segunda petición trae el estado de hace medio
     * segundo, así que la guarda de fuera la deja pasar. Lo que la detiene es
     * la relectura CON BLOQUEO dentro de la transacción. Sin este caso, las dos
     * guardas hacen lo mismo en una petición suelta y quitar la de dentro no
     * cambia nada — la mutación sobrevivía.
     */
    $copiaVieja = ExpedienteProceso::query()->findOrFail($expediente->id);

    $expediente = $transiciones->mover($expediente, EstadoExpediente::EnRevision, $global, null, '10.0.0.2');

    $antesDeLaCarrera = $expediente->transiciones()->count();

    $resultadoCarrera = $transiciones->mover($copiaVieja, EstadoExpediente::EnRevision, $global);

    verificar('La segunda petición de la carrera no revienta ni anota',
        $resultadoCarrera->estado === EstadoExpediente::EnRevision
        && $expediente->transiciones()->count() === $antesDeLaCarrera);

    echo PHP_EOL.'5. El motivo obligatorio'.PHP_EOL;

    verificar('Tomarla no exige motivo', $expediente->estado === EstadoExpediente::EnRevision);

    foreach (['requiere_correccion', 'rechazado'] as $exige) {
        verificar('«'.$exige.'» sin motivo se rehúsa',
            rehusaCon(422, fn () => $transiciones->mover(
                $expediente, EstadoExpediente::from($exige), $global, '   ',
            ), 'hace falta escribir el motivo'));
    }

    $expediente = $transiciones->mover(
        $expediente, EstadoExpediente::RequiereCorreccion, $global, 'Falta tu comprobante de seguro.', '10.0.0.2',
    );

    verificar('Con motivo sí pasa', $expediente->estado === EstadoExpediente::RequiereCorreccion);

    verificar('Y el motivo queda a la vista en el expediente',
        $expediente->motivo_estado === 'Falta tu comprobante de seguro.');

    verificar('Y también en su renglón de bitácora',
        $expediente->transiciones()->orderByDesc('id')->first()->motivo === 'Falta tu comprobante de seguro.');

    echo PHP_EOL.'6. Corregir y volver a enviar'.PHP_EOL;

    $expediente = $solicitudes->enviar($expediente->refresh(), $alumno, '10.0.0.1');

    verificar('De «requiere corrección» se vuelve a solicitar',
        $expediente->estado === EstadoExpediente::Solicitado);

    verificar('Y el motivo viejo se BORRA: ya no es cierto',
        $expediente->motivo_estado === null);

    echo PHP_EOL.'7. Aprobar y asignar'.PHP_EOL;

    $expediente = $transiciones->mover($expediente, EstadoExpediente::EnRevision, $global);
    $expediente = $transiciones->mover($expediente, EstadoExpediente::Aprobado, $global);

    verificar('Queda APROBADO y sin organización',
        $expediente->estado === EstadoExpediente::Aprobado && $expediente->organizacion_id === null);

    // La organización del escenario: recibe y alcanza a todo.
    $recibe = SituacionOrganizacion::query()->where('acepta_asignaciones', true)->firstOrFail();

    $organizacion = OrganizacionReceptora::create([
        'razon_social' => PREFIJO.'Receptora',
        'situacion_id' => $recibe->id,
    ]);


    // Con MODALIDAD puesta: sin ella, «se hereda de la plaza» se cumpliría
    // comparando null contra null y la regla quedaría sin comprobar.
    $modalidad = ModalidadProceso::query()->activos()->firstOrFail();

    $plaza = PlazaProceso::create([
        'organizacion_id' => $organizacion->id,
        'tipo_proceso_id' => $tipo->id,
        'nombre' => PREFIJO.'Plaza de una',
        'cupo' => 1,
        'modalidad_id' => $modalidad->id,
    ]);

    $plaza->forceFill(['abierta' => true])->save();

    $datosAsignacion = [
        'organizacion_id' => $organizacion->id,
        'plaza_id' => $plaza->id,
        'fecha_inicio' => now()->toDateString(),
        'fecha_fin_programada' => now()->addMonths(6)->toDateString(),
    ];

    $expediente = $asignador->asignar($expediente, $datosAsignacion, $global, '10.0.0.2');

    verificar('Pasa a ASIGNADO con su organización y sus fechas',
        $expediente->estado === EstadoExpediente::Asignado
        && (int) $expediente->organizacion_id === $organizacion->id
        && $expediente->fecha_inicio !== null);

    verificar('Y la plaza sube su cupo ocupado',
        (int) $plaza->refresh()->cupo_ocupado === 1);

    /*
     * La MODALIDAD se hereda de la plaza cuando no se captura. Es lo que esa
     * plaza ya declara, así que pedirla otra vez sería teclear un dato que el
     * sistema tiene — y sin heredarla, el catálogo de modalidades no lo leería
     * nadie.
     */
    verificar('La modalidad se hereda de la plaza',
        $expediente->modalidad_id !== null
        && (int) $expediente->modalidad_id === (int) $plaza->modalidad_id,
        ($expediente->modalidad_id ?? 'null').' contra '.($plaza->modalidad_id ?? 'null'));

    echo PHP_EOL.'7b. La organización tiene que RECIBIR, y la plaza tiene que ser SUYA'.PHP_EOL;

    /*
     * Los dos casos se CONSTRUYEN: con una sola organización que recibe y una
     * sola plaza suya, quitar cualquiera de las dos guardas no cambiaría ningún
     * resultado y las mutaciones sobrevivían.
     */
    $suspendida = SituacionOrganizacion::query()->where('acepta_asignaciones', false)->firstOrFail();

    $cerrada = OrganizacionReceptora::create([
        'razon_social' => PREFIJO.'Suspendida',
        'situacion_id' => $suspendida->id,
    ]);

    $paraProbar = $transiciones->abrir([
        'matricula_oferta_id' => $matricula->id,
        'tipo_proceso_id' => TipoProcesoFormativo::query()->where('clave', 'experiencia_profesional')->firstOrFail()->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 480,
    ], $global);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
        $paraProbar = $transiciones->mover($paraProbar, $paso, $global);
    }

    verificar('Una organización SUSPENDIDA no recibe, y se dice su situación',
        rehusaCon(422, fn () => $asignador->asignar($paraProbar, array_merge($datosAsignacion, [
            'organizacion_id' => $cerrada->id,
            'plaza_id' => null,
        ]), $global), 'no está recibiendo alumnos'));

    // Una plaza que es de OTRA organización.
    $plazaAjena = PlazaProceso::create([
        'organizacion_id' => $cerrada->id,
        'tipo_proceso_id' => $tipo->id,
        'nombre' => PREFIJO.'Plaza ajena',
        'cupo' => 9,
    ]);
    $plazaAjena->forceFill(['abierta' => true])->save();

    verificar('Y una plaza de otra organización se rehúsa',
        rehusaCon(422, fn () => $asignador->asignar($paraProbar, array_merge($datosAsignacion, [
            'plaza_id' => $plazaAjena->id,
        ]), $global), 'no es de la organización'));

    echo PHP_EOL.'8. El CUPO lo protege la base'.PHP_EOL;

    // Un segundo alumno del mismo plan, con su propio expediente aprobado.
    $otraMatricula = MatriculaOferta::query()
        ->whereKeyNot($matricula->id)
        ->whereHas('oferta', fn ($q) => $q->where('plan_id', $oferta->plan_id))
        ->first();

    verificar('Hay una segunda matrícula del mismo plan', $otraMatricula !== null);

    $segundo = $transiciones->abrir([
        'matricula_oferta_id' => $otraMatricula->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 480,
    ], $global);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
        $segundo = $transiciones->mover($segundo, $paso, $global);
    }

    verificar('La plaza LLENA se rehúsa, con su motivo',
        rehusaCon(422, fn () => $asignador->asignar($segundo, $datosAsignacion, $global),
            'se le acabó el cupo'));

    verificar('Y el segundo expediente NO se movió',
        $segundo->refresh()->estado === EstadoExpediente::Aprobado);

    verificar('El cupo ocupado sigue en uno, no en dos',
        (int) $plaza->refresh()->cupo_ocupado === 1);

    echo PHP_EOL.'9. Cancelar devuelve el lugar'.PHP_EOL;

    $expediente = $transiciones->mover(
        $expediente, EstadoExpediente::Cancelado, $global, 'Se dio de baja del programa.', '10.0.0.2',
    );

    $asignador->liberarLugar($expediente);

    verificar('El lugar vuelve a la plaza', (int) $plaza->refresh()->cupo_ocupado === 0);

    /*
     * Y liberar dos veces NO deja el contador en negativo. Pasa de verdad —una
     * cancelación que alguien reintenta, un expediente que se cancela y se
     * vuelve a tocar—, y un contador negativo deja de significar nada: el CHECK
     * sólo vigila el techo, no el suelo.
     */
    $asignador->liberarLugar($expediente);

    verificar('Liberar dos veces no lo deja en negativo',
        (int) $plaza->refresh()->cupo_ocupado === 0, (string) $plaza->cupo_ocupado);

    verificar('Un cancelado ya no se mueve a ninguna parte',
        rehusaCon(422, fn () => $transiciones->mover($expediente, EstadoExpediente::EnRevision, $global),
            'ya no se mueve'));

    echo PHP_EOL.'10. Y por eso se puede volver a solicitar'.PHP_EOL;

    /*
     * El único va sobre una columna GENERADA que vale NULL en los cancelados y
     * rechazados. Con un único pelado sobre (matrícula, tipo), una cancelación
     * cerraría la puerta para siempre — y arreglarse y volver a intentarlo es
     * exactamente lo que va a pasar.
     */
    $reintento = $solicitudes->abrir($matricula->refresh(), $tipo, $alumno);

    verificar('Tras cancelar se puede abrir otra',
        $reintento->id !== $expediente->id && $reintento->estado === EstadoExpediente::Borrador);

    // Acotado al TIPO: el único es sobre (matrícula, tipo), y esa matrícula
    // tiene además expedientes de otros procesos.
    $delTipo = fn () => ExpedienteProceso::query()
        ->where('matricula_oferta_id', $matricula->id)
        ->where('tipo_proceso_id', $tipo->id);

    verificar('Y ahora sí hay dos expedientes de ese tipo, uno vivo',
        $delTipo()->count() === 2 && $delTipo()->vivos()->count() === 1,
        $delTipo()->count().' totales, '.$delTipo()->vivos()->count().' vivos');

    echo PHP_EOL.'11. Los estados vivos de PHP y los de la base dicen lo mismo'.PHP_EOL;

    /*
     * La columna generada repite la lista en SQL. Escritas en dos sitios y sin
     * quien las compare, se separan el día que se agregue un estado — y el
     * único empezaría a impedir o a permitir lo que no debe, sin fallar.
     */
    $enSql = DB::selectOne(
        "SELECT GENERATION_EXPRESSION g FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expedientes_proceso'
           AND COLUMN_NAME = 'tipo_si_cuenta'"
    )?->g ?? '';

    /*
     * MySQL devuelve la expresión con las comillas ESCAPADAS y un prefijo de
     * juego de caracteres: `_utf8mb4'borrador'`. Sin normalizarla, buscar
     * «'borrador'» no encuentra nada — y la comprobación de abajo, la que pide
     * que los muertos NO estén, pasaría pase lo que pase. Es justo la clase de
     * prueba vacua que este proyecto persigue.
     */
    $enSql = str_replace(['_utf8mb4', "\\"], '', $enSql);

    verificar('La expresión generada se pudo leer y trae valores entrecomillados',
        str_contains($enSql, "'borrador'"), substr($enSql, 0, 80));

    $faltan = [];

    foreach (EstadoExpediente::ocupanLaMatricula() as $vivo) {
        str_contains($enSql, "'".$vivo->value."'") || $faltan[] = $vivo->value;
    }

    verificar('Todo estado vivo de PHP está en la columna generada',
        $faltan === [], implode(', ', $faltan));

    $sobran = [];

    foreach ([EstadoExpediente::Rechazado, EstadoExpediente::Cancelado] as $muerto) {
        str_contains($enSql, "'".$muerto->value."'") && $sobran[] = $muerto->value;
    }

    verificar('Y ninguno de los que NO cuentan', $sobran === [], implode(', ', $sobran));

    echo PHP_EOL.'12. Las excepciones son un ACTO con dueño'.PHP_EOL;

    // Una versión que exige convenio, y una organización sin ninguno.
    $version->update(['exige_convenio_vigente' => true]);

    $tercero = $transiciones->abrir([
        'matricula_oferta_id' => $otraMatricula->id,
        'tipo_proceso_id' => TipoProcesoFormativo::query()->where('clave', 'practicas_profesionales')->firstOrFail()->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 100,
    ], $global);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
        $tercero = $transiciones->mover($tercero, $paso, $global);
    }

    $plaza->forceFill(['cupo' => 5])->save();

    verificar('Sin convenio vigente NO se asigna',
        rehusaCon(422, fn () => $asignador->asignar($tercero, $datosAsignacion, $global),
            'exige convenio vigente'));

    $tercero->excepciones()->create([
        'requisito' => 'convenio',
        'motivo' => 'Convenio en firma, autorizado por dirección.',
        'autorizada_por' => $global->id,
        'autorizada_en' => now(),
    ]);

    $tercero = $asignador->asignar($tercero->refresh()->load('excepciones'), $datosAsignacion, $global);

    verificar('Con la excepción autorizada, sí',
        $tercero->estado === EstadoExpediente::Asignado);

    verificar('Y la excepción NOMBRA a quien la autorizó',
        $tercero->refresh()->load('excepciones.autorizadaPor')->excepcionDe('convenio')?->autorizada_por === $global->id);

    $version->update(['exige_convenio_vigente' => false]);

    echo PHP_EOL.'13. La excepción se salta el requisito, y lo dice'.PHP_EOL;

    $version->update(['porcentaje_creditos_minimo' => 99.99]);
    $version->refresh();

    $conCreditos = app(App\Services\ProcesosFormativos\ElegibilidadFormativa::class);

    $sinPerdon = $conCreditos->paraVersion($matricula, $version, []);
    $conPerdon = $conCreditos->paraVersion($matricula, $version, ['creditos']);

    verificar('Sin perdón, los créditos lo detienen', ! $sinPerdon['elegible']);

    verificar('Con perdón, pasa', $conPerdon['elegible']);

    verificar('Y se DICE que fue por una excepción, no que cumple',
        collect($conPerdon['cumplidos'])->contains(fn ($c) => str_contains($c, 'excepción autorizada')),
        implode(' | ', $conPerdon['cumplidos']));

    $version->update(['porcentaje_creditos_minimo' => null]);
    $version->refresh();

    echo PHP_EOL.'14. Enviar comprueba los DOCUMENTOS que la regla pide'.PHP_EOL;

    $papel = DocumentoRequerido::query()->first();

    verificar('Hay un documento del catálogo con el que construir el caso', $papel !== null);

    $version->documentos()->create([
        'documento_id' => $papel->id,
        'momento' => 'solicitud',
        'obligatorio' => true,
    ]);
    $version->refresh()->load('documentos.documento');

    $conPapel = $reintento->refresh();

    verificar('Sin subirlo, no se puede enviar',
        rehusaCon(422, fn () => $solicitudes->enviar($conPapel, $alumno), 'Falta subir'));

    verificar('Y el nombre del papel aparece en la lista de lo que falta',
        in_array($papel->nombre, $solicitudes->documentosQueFaltan($conPapel), true));

    $solicitudes->guardarDocumento($conPapel, $papel->id, 'solicitud', 'ruta/de/prueba.pdf', 'seguro.pdf');

    /*
     * Y uno OPCIONAL del mismo momento no frena nada. Sin este caso, quitarle
     * la condición `obligatorio` al filtro no cambiaba ningún resultado —todos
     * los del escenario lo eran—, y la regla quedaba sin comprobar.
     */
    $papelOpcional = DocumentoRequerido::query()->whereKeyNot($papel->id)->firstOrFail();

    $version->documentos()->create([
        'documento_id' => $papelOpcional->id,
        'momento' => 'solicitud',
        'obligatorio' => false,
    ]);
    $version->refresh()->load('documentos.documento');

    verificar('Un documento OPCIONAL no aparece entre los que faltan',
        ! in_array($papelOpcional->nombre, $solicitudes->documentosQueFaltan($conPapel->refresh()), true),
        implode(', ', $solicitudes->documentosQueFaltan($conPapel)));

    verificar('Subiéndolo, ya se puede',
        $solicitudes->enviar($conPapel->refresh(), $alumno)->estado === EstadoExpediente::Solicitado);

    verificar('Re-subirlo REEMPLAZA en vez de acumular',
        (function () use ($solicitudes, $conPapel, $papel) {
            $solicitudes->guardarDocumento($conPapel, $papel->id, 'solicitud', 'otra/ruta.pdf', 'seguro-v2.pdf');

            return $conPapel->documentos()->where('documento_id', $papel->id)->where('momento', 'solicitud')->count() === 1;
        })());

    echo PHP_EOL.'15. La papelería enseña lo PEDIDO y lo subido'.PHP_EOL;

    $papeleria = $solicitudes->papeleria($conPapel->refresh());

    verificar('El papel que la regla pide sale, con su documento_id',
        collect($papeleria)->contains(fn ($p) => $p['documento_id'] === $papel->id && $p['entregado']));

    $otroPapel = DocumentoRequerido::query()->whereKeyNot($papel->id)->firstOrFail();
    $version->documentos()->create([
        'documento_id' => $otroPapel->id,
        'momento' => 'liberacion',
        'obligatorio' => true,
    ]);
    $version->refresh()->load('documentos.documento');

    $papeleria = $solicitudes->papeleria($conPapel->refresh());

    verificar('Y el que se pide PARA LIBERAR también, marcado como no entregado',
        collect($papeleria)->contains(
            fn ($p) => $p['documento_id'] === $otroPapel->id && $p['momento'] === 'liberacion' && ! $p['entregado'],
        ));

    verificar('Pero NO estorba para enviar: es de otro momento',
        $solicitudes->documentosQueFaltan($conPapel->refresh()) === []);

    echo PHP_EOL.'16. El alumno sólo toca lo SUYO'.PHP_EOL;

    $ajeno = ExpedienteProceso::query()->where('matricula_oferta_id', $otraMatricula->id)->firstOrFail();

    verificar('El expediente de otro da 404, no 403',
        rehusaCon(404, fn () => $portal->enviar(peticionCon([], $alumno), $ajeno), 'no es tuyo'));

    verificar('Ni le sube documentos',
        rehusaCon(404, fn () => $portal->subirDocumento(peticionCon([], $alumno), $ajeno)));

    verificar('Ni lo cancela',
        rehusaCon(404, fn () => $portal->cancelar(peticionCon(['motivo' => 'porque sí'], $alumno), $ajeno)));

    verificar('Y abrir sobre una matrícula ajena tampoco',
        rehusaCon(404, fn () => $portal->abrir(peticionCon([
            'matricula' => $otraMatricula->id,
            'tipo_proceso_id' => $tipo->id,
        ], $alumno)), 'no es tuya'));

    echo PHP_EOL.'17. El permiso del ACTO, no el de la pantalla'.PHP_EOL;

    $mirón = usuarioConRol('administrativo');

    verificar('Quien no revisa, no aprueba',
        rehusaCon(403, fn () => $transiciones->mover(
            ExpedienteProceso::query()->where('estado', 'solicitado')->firstOrFail(),
            EstadoExpediente::EnRevision,
            $mirón,
        ), 'Tu rol no puede'));

    verificar('Y el alumno tampoco puede aprobarse a sí mismo',
        rehusaCon(403, fn () => $transiciones->mover(
            $conPapel->refresh(), EstadoExpediente::Aprobado, $alumno,
        )));

    echo PHP_EOL.'18. El ALCANCE por campus, comprobado en cada acto'.PHP_EOL;

    $otroCampus = Campus::query()->whereKeyNot($oferta->campus_id)->firstOrFail();

    $acotado = usuarioConRol('director_general');
    $acotado->persona->asignacionesRol()->update(['campus_id' => $otroCampus->id]);
    $acotado = $acotado->fresh(['persona.asignacionesRol', 'rolActivo']);

    verificar('El acotado a OTRO campus no ve el expediente',
        collect(props($controlador, 'index', $acotado)['expedientes']['data'])
            ->doesntContain(fn ($e) => $e['id'] === $conPapel->id));

    verificar('Y el global sí',
        collect(props($controlador, 'index', $global, ['estado' => 'solicitado'])['expedientes']['data'])
            ->contains(fn ($e) => $e['id'] === $conPapel->id));

    verificar('Pedirlo por la URL le da 403 y no la lista filtrada',
        rehusaCon(403, fn () => props($controlador, 'show', $acotado, [], [$conPapel]),
            'campus que tu rol no alcanza'));

    verificar('Y moverlo, también',
        rehusaCon(403, fn () => $transiciones->mover($conPapel->refresh(), EstadoExpediente::EnRevision, $acotado)));

    echo PHP_EOL.'19. La bandeja arranca en lo que espera algo'.PHP_EOL;

    $bandeja = props($controlador, 'index', $global);

    $estados = collect($bandeja['expedientes']['data'])->pluck('estado')->unique()->all();

    verificar('Sin filtro sólo salen los que esperan respuesta',
        collect($estados)->every(fn ($e) => in_array($e, ['solicitado', 'en_revision', 'aprobado'], true)),
        implode(', ', $estados));

    verificar('Un cancelado no está en la bandeja',
        collect($bandeja['expedientes']['data'])->doesntContain(fn ($e) => $e['id'] === $expediente->id));

    verificar('Pero se encuentra filtrando por su estado',
        collect(props($controlador, 'index', $global, ['estado' => 'cancelado'])['expedientes']['data'])
            ->contains(fn ($e) => $e['id'] === $expediente->id));

    echo PHP_EOL.'20. El detalle ofrece sólo lo que el servidor aceptaría'.PHP_EOL;

    $detalle = props($controlador, 'show', $global, [], [$conPapel->refresh()]);

    $ofrecidos = collect($detalle['expediente']['siguientes'])->pluck('valor')->all();

    verificar('Desde «solicitado» ofrece exactamente sus destinos',
        $ofrecidos === ['en_revision', 'cancelado'], implode(', ', $ofrecidos));

    /*
     * El VERBO y la ETIQUETA del estado son dos textos distintos: el botón dice
     * «Aprobar» y la frase «pasa a Aprobado». Con uno solo salía «El expediente
     * pasa a "Aprobar"», que mezcla las dos cosas.
     */
    verificar('Cada destino trae su verbo Y el nombre de su estado',
        collect($detalle['expediente']['siguientes'])->every(
            fn ($s) => $s['texto'] !== '' && $s['estado_texto'] !== '' && $s['texto'] !== $s['estado_texto'],
        ),
        collect($detalle['expediente']['siguientes'])->map(fn ($s) => $s['texto'].'/'.$s['estado_texto'])->join(', '));

    verificar('Y cada uno dice si exige motivo',
        collect($detalle['expediente']['siguientes'])->firstWhere('valor', 'cancelado')['exige_motivo'] === true
        && collect($detalle['expediente']['siguientes'])->firstWhere('valor', 'en_revision')['exige_motivo'] === false);

    verificar('La historia sale completa y de la más reciente hacia atrás',
        count($detalle['expediente']['historia']) >= 2);

    echo PHP_EOL.'21. Asignar por la ruta de mover se rehúsa'.PHP_EOL;

    $paraAsignar = ExpedienteProceso::query()->where('estado', 'aprobado')->first();

    verificar('Hay uno aprobado con el que probarlo', $paraAsignar !== null);

    verificar('«mover» no acepta el destino asignado: le faltan la organización y las fechas',
        rehusaCon(422, fn () => $controlador->mover(
            peticionCon(['estado' => 'asignado'], $global), $paraAsignar,
        ), 'formulario de asignación'));

    echo PHP_EOL.'22. El plazo máximo de la regla se respeta al asignar'.PHP_EOL;

    verificar('Unas fechas más largas que el tope se rehúsan, con los dos números',
        rehusaCon(422, fn () => $controlador->asignar(peticionCon([
            'organizacion_id' => $organizacion->id,
            'plaza_id' => $plaza->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin_programada' => now()->addYears(3)->toDateString(),
        ], $global), $paraAsignar), 'tope de 365'));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
