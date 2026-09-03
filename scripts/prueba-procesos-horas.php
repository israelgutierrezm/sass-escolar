<?php

/**
 * Horas, informes y evaluaciones (fase 5). Con rollback.
 *
 * Se corre con `php scripts/prueba-procesos-horas.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Sólo lo APROBADO cuenta.** Contar lo capturado dejaría al alumno
 *     acercándose a su meta escribiendo jornadas que nadie miró.
 *  2. **`horas_aprobadas` se RECALCULA**, nunca se incrementa: un contador que
 *     se suma se desincroniza con la primera corrección.
 *  3. **Sin traslape**, comparando las DOS condiciones: una de 9 a 13 y otra
 *     de 10 a 11 no comparten hora de arranque y chocan igual.
 *  4. **Los topes diario y semanal** salen de la regla CONGELADA.
 *  5. **Doble aprobación imposible**: el `update` va condicionado a que siga
 *     capturada, porque el guard en memoria lo pasan dos peticiones a la vez.
 *  6. **Los minutos los calcula MySQL**, así que no pueden decir algo distinto
 *     de las horas de su propia fila.
 *  7. **Los informes se programan al asignar**, con sus fechas, y se
 *     reprograman salvo los ya entregados.
 *  8. **El puntaje de una evaluación lo pone el servidor**, y un nivel que no
 *     es de su criterio se rehúsa.
 *  9. **La ubicación se DESCARTA si la escuela no la pide**, aunque venga en
 *     la petición: el interruptor tiene que proteger de verdad.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ProcesosFormativos\ExpedienteFormativoController;
use App\Http\Controllers\ProcesosFormativos\SeguimientoFormativoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Rubrica;
use App\Models\ProcesosFormativos\BitacoraHoras;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\EvaluacionProceso;
use App\Models\ProcesosFormativos\InformeProceso;
use App\Models\ProcesosFormativos\ModalidadProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\PlazaProceso;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoInformeProceso;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use App\Services\ProcesosFormativos\AsignadorDePlaza;
use App\Services\ProcesosFormativos\InformesYEvaluaciones;
use App\Services\ProcesosFormativos\RegistradorDeHoras;
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

function usuarioConRol(string $rol, ?int $personaId = null): Usuario
{
    $personaId ??= Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Horas',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ])->id;

    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::query()->where('persona_id', $personaId)->first();

    $cuenta
        ? $cuenta->forceFill(['rol_activo_id' => $rolId])->save()
        : $cuenta = Usuario::create([
            'persona_id' => $personaId,
            'usuario' => 'prueba_hrs_'.random_int(100000, 999999),
            'email' => 'prueba_hrs_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $rolId,
        ]);

    $cuenta->persona->asignacionesRol()->firstOrCreate(
        ['rol_id' => $rolId],
        ['activo' => true, 'campus_id' => null],
    );

    return $cuenta->fresh(['persona', 'rolActivo']);
}

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

const PREFIJO = 'ZZHRS-';

$db->beginTransaction();

try {
    $horas = app(RegistradorDeHoras::class);
    $papeleo = app(InformesYEvaluaciones::class);
    $transiciones = app(TransicionDeExpediente::class);
    $asignador = app(AsignadorDePlaza::class);
    $seguimiento = app(SeguimientoFormativoController::class);
    $expedientes = app(ExpedienteFormativoController::class);
    $ajustes = app(Ajustes::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    /*
     * Se parte de CERO reglas y expedientes: lo que se prueba es ARITMÉTICA de
     * horas, y eso sólo se puede afirmar sabiendo qué hay. Séptima vez que este
     * proyecto se cobra lo mismo.
     */
    DB::table('expedientes_proceso')->delete();
    DB::table('reglas_proceso')->delete();

    $tipo = TipoProcesoFormativo::query()->where('clave', 'servicio_social')->firstOrFail();

    $matricula = MatriculaOferta::query()
        ->whereHas('oferta.plan')
        ->with('oferta')
        ->first();

    verificar('Hay una matrícula con la que construir el escenario', $matricula !== null);

    $regla = ReglaProceso::create([
        'nombre' => PREFIJO.'Regla',
        'tipo_proceso_id' => $tipo->id,
        'plan_id' => $matricula->oferta->plan_id,
    ]);

    $version = $regla->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'horas_requeridas' => 480,
        'tolerancia_horas' => 0,
        'max_horas_dia' => 8,
        'max_horas_semana' => 30,
        'informes_parciales' => 2,
        'periodicidad_informe_dias' => 30,
        'exige_informe_final' => true,
        'exige_evaluacion_supervisor' => true,
    ]);

    // Un expediente ya asignado y en curso: es donde se registran horas.
    $inicio = now()->startOfWeek(Carbon\CarbonInterface::MONDAY);

    $expediente = $transiciones->abrir([
        'matricula_oferta_id' => $matricula->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 480,
    ], $global);

    $recibe = SituacionOrganizacion::query()->where('acepta_asignaciones', true)->firstOrFail();
    $organizacion = OrganizacionReceptora::create([
        'razon_social' => PREFIJO.'Receptora',
        'situacion_id' => $recibe->id,
    ]);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
        $expediente = $transiciones->mover($expediente, $paso, $global);
    }

    $expediente = $asignador->asignar($expediente, [
        'organizacion_id' => $organizacion->id,
        'fecha_inicio' => $inicio->toDateString(),
        'fecha_fin_programada' => $inicio->copy()->addMonths(6)->toDateString(),
    ], $global);

    echo PHP_EOL.'1. Los informes se PROGRAMAN al asignar'.PHP_EOL;

    $expediente->load('informes.tipo');

    verificar('Se crearon los dos parciales y el final',
        $expediente->informes->count() === 3, (string) $expediente->informes->count());

    verificar('Cada uno con su fecha límite calculada',
        $expediente->informes->every(fn ($i) => $i->fecha_limite !== null));

    verificar('El primer parcial vence a los 30 días del inicio',
        $expediente->informes->firstWhere('numero', 1)?->fecha_limite?->toDateString()
            === $inicio->copy()->addDays(30)->toDateString());

    /*
     * Y el SEGUNDO a los 60, no a los 30 otra vez. Sin este caso, repartir por
     * `dias * numero` y repartir por `dias` a secas dan lo mismo en el primero,
     * y la regla que separa dos entregas de una quedaba sin comprobar.
     */
    verificar('Y el segundo a los 60: la periodicidad se MULTIPLICA por su número',
        $expediente->informes->firstWhere('numero', 2)?->fecha_limite?->toDateString()
            === $inicio->copy()->addDays(60)->toDateString(),
        (string) $expediente->informes->firstWhere('numero', 2)?->fecha_limite?->toDateString());

    verificar('Y el FINAL al terminar el periodo',
        $expediente->informes->first(fn ($i) => $i->esFinal())?->fecha_limite?->toDateString()
            === $expediente->fecha_fin_programada->toDateString());

    verificar('Todos nacen PENDIENTES, sin archivo',
        $expediente->informes->every(fn ($i) => $i->estado === InformeProceso::PENDIENTE && $i->archivo_ruta === null));

    echo PHP_EOL.'2. Las horas: sólo se registran con el proceso en CURSO'.PHP_EOL;

    $jornada = [
        'fecha' => $inicio->toDateString(),
        'hora_inicio' => '09:00',
        'hora_fin' => '13:00',
        'actividad' => 'Apoyo en el archivo del área jurídica.',
    ];

    verificar('Asignado pero sin iniciar, NO admite horas',
        rehusaCon(422, fn () => $horas->capturar($expediente, $jornada, $global), 'está en «Asignado»'));

    $expediente = $transiciones->mover($expediente, EstadoExpediente::EnCurso, $global);

    $primera = $horas->capturar($expediente, $jornada, $global);

    verificar('En curso sí', $primera->exists);

    verificar('Nace CAPTURADA, no aprobada', $primera->estado === BitacoraHoras::CAPTURADA);

    echo PHP_EOL.'3. Los MINUTOS los calcula la base'.PHP_EOL;

    verificar('Cuatro horas son 240 minutos', (int) $primera->minutos_totales === 240);

    $conDescanso = $horas->capturar($expediente, [
        'fecha' => $inicio->copy()->addDay()->toDateString(),
        'hora_inicio' => '09:00',
        'hora_fin' => '14:00',
        'minutos_descanso' => 30,
        'actividad' => 'Atención en ventanilla, con media hora de comida.',
    ], $global);

    verificar('Y el descanso se resta: cinco horas menos treinta minutos son 270',
        (int) $conDescanso->minutos_totales === 270);

    verificar('Las horas se leen con dos decimales', $conDescanso->horas() === 4.5);

    echo PHP_EOL.'4. Sólo lo APROBADO cuenta'.PHP_EOL;

    verificar('Con dos capturadas, el total sigue en cero',
        $horas->horasAprobadas($expediente) === 0.0);

    verificar('Y el expediente también', (int) $expediente->refresh()->horas_aprobadas === 0);

    $horas->aprobar($primera, $global);

    verificar('Aprobada una, cuentan sus cuatro horas',
        $horas->horasAprobadas($expediente) === 4.0);

    verificar('Y el expediente se RECALCULA solo',
        (int) $expediente->refresh()->horas_aprobadas === 4);

    verificar('Faltan las demás', $horas->horasQueFaltan($expediente) === 476.0);

    echo PHP_EOL.'5. Rechazar conserva la jornada CON su motivo'.PHP_EOL;

    $horas->rechazar($conDescanso, 'Ese día la organización estaba cerrada.', $global);

    verificar('Queda rechazada y con el motivo escrito',
        $conDescanso->refresh()->estado === BitacoraHoras::RECHAZADA
        && $conDescanso->motivo_rechazo === 'Ese día la organización estaba cerrada.');

    verificar('Y NO se borró: sigue ahí',
        BitacoraHoras::query()->whereKey($conDescanso->id)->exists());

    verificar('Rechazar sin motivo se rehúsa',
        rehusaCon(422, fn () => $horas->rechazar($primera, '   ', $global), 'hace falta el motivo'));

    echo PHP_EOL.'6. Doble revisión imposible'.PHP_EOL;

    verificar('Una ya aprobada no se vuelve a revisar',
        rehusaCon(422, fn () => $horas->aprobar($primera, $global), 'ya la revisó alguien'));

    verificar('Ni una rechazada',
        rehusaCon(422, fn () => $horas->aprobar($conDescanso, $global)));

    echo PHP_EOL.'7. Sin TRASLAPE, con las dos condiciones'.PHP_EOL;

    verificar('La misma franja choca',
        rehusaCon(422, fn () => $horas->capturar($expediente, $jornada, $global), 'se encima'));

    /*
     * El caso que separa una condición de dos: una jornada CONTENIDA dentro de
     * otra. No comparte hora de arranque, así que con una sola comparación
     * entraría — y sería doble conteo del mismo tiempo.
     */
    verificar('Y una contenida dentro de otra, también',
        rehusaCon(422, fn () => $horas->capturar($expediente, array_merge($jornada, [
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
        ]), $global), 'se encima'));

    verificar('Pero una pegada, sin encimarse, entra',
        $horas->capturar($expediente, array_merge($jornada, [
            'hora_inicio' => '13:00',
            'hora_fin' => '15:00',
            'actividad' => 'Sigue la tarde, sin encimarse con la mañana.',
        ]), $global)->exists);

    /*
     * Y la RECHAZADA no estorba: su franja queda libre. Si no, corregir una
     * jornada mal capturada exigiría borrarla —y con ella su motivo—.
     */
    verificar('Una jornada RECHAZADA no ocupa su franja',
        $horas->capturar($expediente, [
            'fecha' => $inicio->copy()->addDay()->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '12:00',
            'actividad' => 'Sobre la franja de la que se rechazó.',
        ], $global)->exists);

    echo PHP_EOL.'8. Las jornadas imposibles'.PHP_EOL;

    verificar('La salida antes de la entrada',
        rehusaCon(422, fn () => $horas->capturar($expediente, array_merge($jornada, [
            'fecha' => $inicio->copy()->addDays(3)->toDateString(),
            'hora_inicio' => '13:00',
            'hora_fin' => '09:00',
        ]), $global), 'posterior a la de entrada'));

    verificar('Y un descanso que se come la jornada',
        rehusaCon(422, fn () => $horas->capturar($expediente, array_merge($jornada, [
            'fecha' => $inicio->copy()->addDays(3)->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '10:00',
            'minutos_descanso' => 60,
        ]), $global), 'no quedan minutos'));

    echo PHP_EOL.'9. Dentro de las fechas de la ASIGNACIÓN'.PHP_EOL;

    verificar('Antes de empezar, no',
        rehusaCon(422, fn () => $horas->capturar($expediente, array_merge($jornada, [
            'fecha' => $inicio->copy()->subDays(5)->toDateString(),
        ]), $global), 'el proceso empieza el'));

    verificar('Y después de la fecha de fin, tampoco',
        rehusaCon(422, fn () => $horas->capturar($expediente, array_merge($jornada, [
            'fecha' => $expediente->fecha_fin_programada->copy()->addDay()->toDateString(),
        ]), $global), 'debía terminar el'));

    echo PHP_EOL.'10. Los TOPES de la regla congelada'.PHP_EOL;

    // Ese día ya lleva 4 h (mañana) + 2 h (tarde) = 6, y el tope es 8.
    verificar('Una que rebasa el tope diario se rehúsa, con los dos números',
        rehusaCon(422, fn () => $horas->capturar($expediente, array_merge($jornada, [
            'hora_inicio' => '15:00',
            'hora_fin' => '20:00',
        ]), $global), 'permite como mucho 8'));

    verificar('Pero una que cabe, entra',
        $horas->capturar($expediente, array_merge($jornada, [
            'hora_inicio' => '15:00',
            'hora_fin' => '17:00',
            'actividad' => 'Las dos horas que caben en el tope del día.',
        ]), $global)->exists);

    /*
     * Y el tope cuenta también lo CAPTURADO, no sólo lo aprobado. El caso se
     * construye en un día donde NINGUNA jornada está aprobada: si el tope sólo
     * mirara lo aprobado, ese día admitiría el doble y el alumno rebasaría el
     * límite escribiendo jornadas que nadie ha revisado.
     */
    $diaSinAprobar = $inicio->copy()->addDays(15)->toDateString();

    $horas->capturar($expediente, [
        'fecha' => $diaSinAprobar,
        'hora_inicio' => '08:00',
        'hora_fin' => '15:00',
        'actividad' => 'Siete horas capturadas y sin revisar.',
    ], $global);

    verificar('El tope diario cuenta lo capturado, aunque nadie lo haya aprobado',
        rehusaCon(422, fn () => $horas->capturar($expediente, [
            'fecha' => $diaSinAprobar,
            'hora_inicio' => '15:00',
            'hora_fin' => '18:00',
            'actividad' => 'Las tres que rebasarían el tope de ese día.',
        ], $global), 'permite como mucho 8'));

    /*
     * El tope SEMANAL. Ese lunes lleva 8 h; con jornadas de 8 h el martes,
     * miércoles y jueves llegaría a 32 y el tope es 30.
     */
    foreach ([1, 2] as $dia) {
        $horas->capturar($expediente, [
            'fecha' => $inicio->copy()->addDays($dia + 2)->toDateString(),
            'hora_inicio' => '08:00',
            'hora_fin' => '16:00',
            'actividad' => 'Jornada completa del día '.$dia.'.',
        ], $global);
    }

    verificar('Y el tope semanal se respeta',
        rehusaCon(422, fn () => $horas->capturar($expediente, [
            'fecha' => $inicio->copy()->addDays(5)->toDateString(),
            'hora_inicio' => '08:00',
            'hora_fin' => '16:00',
            'actividad' => 'La que rebasaría la semana.',
        ], $global), 'esa semana'));

    /*
     * La semana va de LUNES a domingo: la jornada del DOMINGO tiene que caer en
     * la misma semana que el lunes anterior. Con el domingo como primer día
     * —que es lo que `startOfWeek()` devuelve en esta aplicación— contaría en la
     * siguiente y el tope se podría rebasar partiendo el fin de semana.
     */
    verificar('El DOMINGO cuenta en la semana que empezó el lunes',
        rehusaCon(422, fn () => $horas->capturar($expediente, [
            'fecha' => $inicio->copy()->addDays(6)->toDateString(),
            'hora_inicio' => '08:00',
            'hora_fin' => '16:00',
            'actividad' => 'La del domingo de esa misma semana.',
        ], $global), 'esa semana'));

    echo PHP_EOL.'11. Corregir una jornada'.PHP_EOL;

    $corregible = BitacoraHoras::query()
        ->where('expediente_id', $expediente->id)
        ->where('estado', BitacoraHoras::RECHAZADA)
        ->firstOrFail();

    $corregida = $horas->corregir($corregible, [
        'fecha' => $inicio->copy()->addDays(8)->toDateString(),
        'hora_inicio' => '09:00',
        'hora_fin' => '12:00',
        'actividad' => 'Corregida: era otro día.',
    ], $global);

    verificar('Vuelve a la cola de revisión',
        $corregida->estado === BitacoraHoras::CAPTURADA);

    verificar('Y su motivo de rechazo se BORRA: ya no es cierto',
        $corregida->motivo_rechazo === null);

    /*
     * Y corregir SIN mover la franja tiene que poder hacerse: el traslape se
     * excluye a sí mismo. Sin este caso —la primera versión movía la jornada a
     * otro día—, quitar esa exclusión no cambiaba nada, y en producción
     * significaría que una jornada choca consigo misma y no se puede corregir
     * ni una errata de la actividad.
     */
    verificar('Corregir sin mover la franja no choca consigo misma',
        $horas->corregir($corregida, [
            'fecha' => $corregida->fecha->toDateString(),
            'hora_inicio' => substr((string) $corregida->hora_inicio, 0, 5),
            'hora_fin' => substr((string) $corregida->hora_fin, 0, 5),
            'actividad' => 'Sólo se corrige el texto, no el horario.',
        ], $global)->actividad === 'Sólo se corrige el texto, no el horario.');

    verificar('Una APROBADA no se corrige: se rechaza y se captura de nuevo',
        rehusaCon(422, fn () => $horas->corregir($primera, $jornada, $global), 'ya está aprobada'));

    echo PHP_EOL.'12. El recálculo no se desincroniza'.PHP_EOL;

    $porRevisar = BitacoraHoras::query()
        ->where('expediente_id', $expediente->id)
        ->porRevisar()
        ->get();

    foreach ($porRevisar as $fila) {
        $horas->aprobar($fila, $global);
    }

    $sumaReal = round((int) BitacoraHoras::query()
        ->where('expediente_id', $expediente->id)
        ->aprobadas()
        ->sum('minutos_totales') / 60, 2);

    verificar('El total del servicio cuadra con la suma cruda',
        $horas->horasAprobadas($expediente) === $sumaReal, (string) $sumaReal);

    /*
     * Y al RECHAZAR una ya aprobada, el total BAJA. Es el caso que separa un
     * recálculo de un contador que se incrementa: aquél lo detecta, éste se
     * queda diciendo lo de antes.
     */
    $unaAprobada = BitacoraHoras::query()
        ->where('expediente_id', $expediente->id)
        ->aprobadas()
        ->firstOrFail();

    $antes = $horas->horasAprobadas($expediente);

    // Se devuelve a «capturada» a mano para poder rechazarla: el estado es la
    // defensa contra la doble revisión, y aquí se quiere probar el recálculo.
    $unaAprobada->forceFill(['estado' => BitacoraHoras::CAPTURADA])->save();
    $horas->rechazar($unaAprobada, 'Se revisó otra vez y no procede.', $global);

    verificar('Al rechazar una aprobada, el total BAJA',
        $horas->horasAprobadas($expediente) < $antes,
        $antes.' → '.$horas->horasAprobadas($expediente));

    verificar('Y el expediente se actualiza con ella',
        (int) $expediente->refresh()->horas_aprobadas === (int) floor($horas->minutosAprobados($expediente) / 60));

    echo PHP_EOL.'13. Aprobar exige PERMISO'.PHP_EOL;

    $mirón = usuarioConRol('administrativo');

    $suelta = $horas->capturar($expediente, [
        'fecha' => $inicio->copy()->addDays(9)->toDateString(),
        'hora_inicio' => '09:00',
        'hora_fin' => '11:00',
        'actividad' => 'Para probar el permiso.',
    ], $global);

    verificar('Quien no lo tiene, no aprueba',
        rehusaCon(403, fn () => $horas->aprobar($suelta, $mirón), 'no puede aprobar'));

    verificar('Ni rechaza',
        rehusaCon(403, fn () => $horas->rechazar($suelta, 'porque sí', $mirón)));

    /*
     * Y el ALCANCE, que es otra cosa que el permiso: quien LO TIENE pero está
     * acotado a otro campus tampoco. Sin este caso, quitar la comprobación de
     * alcance no cambiaba ningún resultado — el único usuario sin permiso ya se
     * detenía antes.
     */
    $otroCampus = App\Models\Academico\Campus::query()
        ->whereKeyNot($matricula->oferta->campus_id)
        ->firstOrFail();

    $acotado = usuarioConRol('director_general');
    $acotado->persona->asignacionesRol()->update(['campus_id' => $otroCampus->id]);
    $acotado = $acotado->fresh(['persona.asignacionesRol', 'rolActivo']);

    verificar('Con el permiso pero acotado a OTRO campus, tampoco',
        rehusaCon(403, fn () => $horas->aprobar($suelta, $acotado), 'campus que tu rol no alcanza'));

    echo PHP_EOL.'14. Los informes: entregar y revisar'.PHP_EOL;

    $parcial = $expediente->informes()->where('numero', 1)->firstOrFail();

    $papeleo->entregar($parcial, 'ruta/informe.pdf', 'primer-informe.pdf');

    verificar('Queda ENTREGADO con su fecha',
        $parcial->refresh()->estado === InformeProceso::ENTREGADO && $parcial->entregado_en !== null);

    verificar('Devolverlo SIN decir por qué se rehúsa',
        rehusaCon(422, fn () => $papeleo->revisar($parcial, false, '   ', $global), 'qué hay que corregir'));

    $papeleo->revisar($parcial, false, 'Le falta la firma del supervisor.', $global);

    verificar('Devuelto, con la retroalimentación a la vista',
        $parcial->refresh()->estado === InformeProceso::RECHAZADO
        && $parcial->retroalimentacion === 'Le falta la firma del supervisor.');

    $papeleo->entregar($parcial, 'ruta/informe-v2.pdf', 'primer-informe-v2.pdf');

    verificar('Re-entregarlo lo devuelve a «entregado», sin revisar',
        $parcial->refresh()->estado === InformeProceso::ENTREGADO);

    $papeleo->revisar($parcial, true, null, $global);

    verificar('Aceptado ya no admite otra entrega',
        rehusaCon(422, fn () => $papeleo->entregar($parcial, 'otra/ruta.pdf', 'x.pdf'), 'ya está aceptado'));

    verificar('Uno sin entregar no se puede revisar',
        rehusaCon(422, fn () => $papeleo->revisar(
            $expediente->informes()->where('numero', 2)->firstOrFail(), true, null, $global,
        ), 'todavía no se ha entregado'));

    verificar('Y revisar exige permiso',
        rehusaCon(403, fn () => $papeleo->revisar($parcial, true, null, $mirón), 'no puede revisar informes'));

    echo PHP_EOL.'15. Un informe entregado NO se reprograma'.PHP_EOL;

    $limiteAntes = $parcial->refresh()->fecha_limite?->toDateString();
    $pendienteAntes = $expediente->informes()->where('numero', 2)->firstOrFail()->fecha_limite?->toDateString();

    // Se mueve el periodo y se vuelve a programar, como haría una reasignación.
    $expediente->forceFill(['fecha_inicio' => $inicio->copy()->addDays(10)->toDateString()])->save();
    $papeleo->programar($expediente->refresh());

    verificar('El entregado conserva su fecha: si no, cambiaría si llegó tarde',
        $parcial->refresh()->fecha_limite?->toDateString() === $limiteAntes);

    verificar('Y el pendiente sí se mueve',
        $expediente->informes()->where('numero', 2)->firstOrFail()->fecha_limite?->toDateString() !== $pendienteAntes);

    $expediente->forceFill(['fecha_inicio' => $inicio->toDateString()])->save();

    echo PHP_EOL.'16. Las evaluaciones'.PHP_EOL;

    $rubrica = Rubrica::query()
        ->where('ambito', Rubrica::PLATAFORMA)
        ->whereHas('criterios.niveles')
        ->with('criterios.niveles')
        ->first();

    verificar('Hay una rúbrica de la escuela con la que evaluar', $rubrica !== null);

    $criterio = $rubrica->criterios->first();
    $nivel = $criterio->niveles->sortByDesc('puntos')->first();

    $evaluacion = $papeleo->evaluar(
        $expediente,
        EvaluacionProceso::SUPERVISOR,
        $rubrica,
        [$criterio->id => $nivel->id],
        'Excelente disposición.',
        $global,
    );

    verificar('El PUNTAJE lo calcula el servidor, no la petición',
        (float) $evaluacion->puntaje === (float) $nivel->puntos,
        $evaluacion->puntaje.' contra '.$nivel->puntos);

    verificar('Y se CONGELA el texto del criterio y del nivel',
        ($evaluacion->respuestas[0]['criterio'] ?? null) === $criterio->titulo
        && ($evaluacion->respuestas[0]['nivel'] ?? null) === $nivel->titulo);

    /*
     * El caso que hace falta: un nivel de OTRO criterio. Sin comprobarlo,
     * mandar el id del nivel más alto de otro daría puntos que esa rúbrica no
     * concede en ese renglón — y el desplegable no es una defensa.
     */
    $otroCriterio = $rubrica->criterios->firstWhere('id', '!=', $criterio->id);

    verificar('Hay un segundo criterio con el que probar el cruce', $otroCriterio !== null);

    verificar('Un nivel que no es de su criterio se rehúsa',
        rehusaCon(422, fn () => $papeleo->evaluar(
            $expediente,
            EvaluacionProceso::COORDINADOR,
            $rubrica,
            [$criterio->id => $otroCriterio->niveles->first()->id],
            null,
            $global,
        ), 'no es de su criterio'));

    verificar('Volver a evaluar el MISMO origen la edita, no acumula',
        (function () use ($papeleo, $expediente, $rubrica, $criterio, $global) {
            $antes = $expediente->evaluaciones()->count();

            $papeleo->evaluar($expediente, EvaluacionProceso::SUPERVISOR, $rubrica,
                [$criterio->id => $criterio->niveles->sortBy('puntos')->first()->id], 'Corregida.', $global);

            return $expediente->evaluaciones()->count() === $antes;
        })());

    verificar('Un origen inventado se rehúsa',
        rehusaCon(422, fn () => $papeleo->evaluar($expediente, 'jefe', null, [], null, $global),
            'origen de evaluación no existe'));

    echo PHP_EOL.'17. Lo que le falta de papeleo, con su razón'.PHP_EOL;

    $faltan = $papeleo->impedimentosDePapeleo($expediente->refresh());

    verificar('Se nombran los informes que faltan',
        collect($faltan)->contains(fn ($m) => str_contains($m, 'Falta entregar')),
        implode(' | ', $faltan));

    /*
     * El ACEPTADO no aparece de NINGUNA forma —ni como «falta entregar» ni como
     * «entregado y sin aceptar»—. Buscar sólo su número dejaba pasar la
     * mutación: con el guard quitado, el aceptado entraba por la otra rama y su
     * mensaje no llevaba «n.º 1».
     */
    /*
     * Un informe DEVUELTO se nombra distinto de uno sin entregar y de uno
     * entregado: conserva su `entregado_en` de la entrega anterior, así que con
     * dos ramas decía «está entregado y sin aceptar» y quien lo leía creía que
     * sólo faltaba revisarlo — con la pelota del lado contrario.
     */
    $devuelto = $expediente->informes()->where('numero', 2)->firstOrFail();
    $papeleo->entregar($devuelto, 'ruta/segundo.pdf', 'segundo.pdf');
    $papeleo->revisar($devuelto, false, 'Falta el anexo.', $global);

    verificar('Un informe DEVUELTO dice que hay que rehacerlo, no que falta revisarlo',
        collect($papeleo->impedimentosDePapeleo($expediente->refresh()))
            ->contains(fn ($m) => str_contains($m, 'Te devolvieron') && str_contains($m, 'rehacerlo')),
        implode(' | ', $papeleo->impedimentosDePapeleo($expediente)));

    // Y se devuelve al estado en que lo encontró la sección.
    $devuelto->forceFill(['estado' => InformeProceso::PENDIENTE, 'entregado_en' => null, 'retroalimentacion' => null])->save();
    $expediente->refresh()->load('informes.tipo');
    $faltan = $papeleo->impedimentosDePapeleo($expediente);

    verificar('El ya ACEPTADO no aparece de ninguna forma',
        count($faltan) === 2
        && ! collect($faltan)->contains(fn ($m) => str_contains($m, 'sin aceptar')),
        implode(' | ', $faltan));

    /*
     * Y la evaluación del ESTUDIANTE, que la regla exige y nadie capturó. El
     * caso se construye encendiendo la bandera: sin ella, quitar esa rama del
     * servicio no cambiaría ningún resultado.
     */
    $version->update(['exige_evaluacion_estudiante' => true]);
    $expediente->refresh()->load('reglaVersion', 'evaluaciones');

    verificar('Y la evaluación exigida que nadie capturó',
        collect($papeleo->impedimentosDePapeleo($expediente))
            ->contains(fn ($m) => str_contains($m, 'autoevaluación')),
        implode(' | ', $papeleo->impedimentosDePapeleo($expediente)));

    verificar('Pero la del supervisor NO, porque ya está',
        ! collect($papeleo->impedimentosDePapeleo($expediente))
            ->contains(fn ($m) => str_contains($m, 'supervisor')));

    echo PHP_EOL.'18. La UBICACIÓN se descarta si la escuela no la pide'.PHP_EOL;

    verificar('El ajuste nace apagado',
        $ajustes->bool(CatalogoAjustes::PROCESOS_PEDIR_UBICACION) === false);

    $conCoordenadas = [
        'fecha' => $inicio->copy()->addDays(11)->toDateString(),
        'hora_inicio' => '09:00',
        'hora_fin' => '11:00',
        'actividad' => 'Con coordenadas en la petición, aunque nadie las pida.',
        'latitud' => 19.4326,
        'longitud' => -99.1332,
    ];

    $seguimiento->capturarHoras(peticionCon($conCoordenadas, $global), $expediente);

    $sinUbicacion = BitacoraHoras::query()
        ->where('expediente_id', $expediente->id)
        ->orderByDesc('id')
        ->first();

    verificar('Apagado, la ubicación NO se guarda aunque venga en la petición',
        $sinUbicacion->latitud === null && $sinUbicacion->longitud === null);

    // Y encendido sí, que es la otra mitad: sin ella, «se descarta» se
    // cumpliría también si nunca se guardara nada.
    DB::table('configuraciones')->updateOrInsert(
        ['clave' => CatalogoAjustes::PROCESOS_PEDIR_UBICACION],
        ['valor' => '1', 'descripcion' => 'prueba', 'created_at' => now(), 'updated_at' => now()],
    );
    app()->forgetInstance(Ajustes::class);

    $seguimiento2 = app(SeguimientoFormativoController::class);

    $seguimiento2->capturarHoras(peticionCon(array_merge($conCoordenadas, [
        'fecha' => $inicio->copy()->addDays(12)->toDateString(),
    ]), $global), $expediente);

    $conUbicacion = BitacoraHoras::query()
        ->where('expediente_id', $expediente->id)
        ->orderByDesc('id')
        ->first();

    verificar('Encendido, sí se guarda',
        $conUbicacion->latitud !== null && $conUbicacion->longitud !== null,
        ($conUbicacion->latitud ?? 'null').', '.($conUbicacion->longitud ?? 'null'));

    echo PHP_EOL.'19. La PAREJA (expediente, jornada) se comprueba'.PHP_EOL;

    $otroExpediente = $transiciones->abrir([
        'matricula_oferta_id' => MatriculaOferta::query()->whereKeyNot($matricula->id)->firstOrFail()->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 480,
    ], $global);

    verificar('Una jornada de otro expediente da 404',
        rehusaCon(404, fn () => $seguimiento->revisarHoras(
            peticionCon(['aprobada' => true], $global), $otroExpediente, $primera,
        ), 'no es de este expediente'));

    verificar('Y un informe de otro, también',
        rehusaCon(404, fn () => $seguimiento->revisarInforme(
            peticionCon(['aceptado' => true], $global), $otroExpediente, $parcial,
        ), 'no es de este expediente'));

    echo PHP_EOL.'20. El detalle enseña las horas sumadas'.PHP_EOL;

    $peticion = Illuminate\Http\Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $global);

    $props = json_decode(
        $expedientes->show($peticion, $expediente->refresh())->toResponse($peticion)->getContent(),
        true,
    )['props'];

    // Comparado como número: el JSON devuelve 26 (int) donde el servicio da
    // 26.0 (float), y un `===` los daría por distintos siendo el mismo total.
    verificar('El total de la pantalla es el del servicio',
        (float) $props['expediente']['horas']['aprobadas'] === $horas->horasAprobadas($expediente),
        $props['expediente']['horas']['aprobadas'].' contra '.$horas->horasAprobadas($expediente));

    /*
     * Y CUÁNTAS le piden: es lo que dibuja la barra de avance. Sin este dato la
     * pantalla enseñaba la lista de jornadas y ninguna cifra —se vio mirándola—,
     * y «lleva 4.5 horas» sin decir de cuántas no informa de nada.
     */
    verificar('La pantalla dice cuántas horas se le piden',
        (float) $props['expediente']['horas']['requeridas'] === (float) $version->horasMinimas(),
        ($props['expediente']['horas']['requeridas'] ?? 'null').' contra '.$version->horasMinimas());

    verificar('Y los topes de su regla',
        (int) $props['expediente']['horas']['max_dia'] === 8
        && (int) $props['expediente']['horas']['max_semana'] === 30);

    verificar('Y salen todas las jornadas',
        count($props['expediente']['horas']['jornadas'])
            === BitacoraHoras::query()->where('expediente_id', $expediente->id)->count());

    /*
     * Y lo que falta PARA LIBERARSE, que desde la fase 6 sale del liberador:
     * incluye el papeleo y además las horas y los documentos del final. La
     * pantalla y el acto de emitir preguntan al mismo sitio, que es lo que
     * impide que una prometa lo que el otro rehúsa.
     */
    verificar('Con lo que falta para liberarse, del mismo servicio que decide',
        $props['expediente']['papeleo_pendiente']
            === app(App\Services\ProcesosFormativos\LiberadorDeExpediente::class)->impedimentos($expediente));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
