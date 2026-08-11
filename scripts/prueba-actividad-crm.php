<?php

/**
 * Prueba de integración del CRM en la ficha del aspirante: la actividad
 * agendada, el reparto de asesores y el cambio de etapa. Con rollback.
 *
 * Se corre con `php scripts/prueba-actividad-crm.php` desde la raíz.
 *
 * Crea sus propias personas, sus propios asesores y sus propios prospectos:
 * NUNCA toma los del demo ni le cambia el titular a nadie.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Academico\Campus;
use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Identidad\Persona;
use App\Models\Promocion\SeguimientoAspirante;
use App\Models\Tenant;
use App\Services\AgendaDelAspirante;
use App\Services\AsignadorAsesor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$ok = 0;
$fallos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;

    if ($condicion) {
        $ok++;
        echo "  OK   {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

DB::beginTransaction();

try {
    $agenda = app(AgendaDelAspirante::class);
    $asignador = app(AsignadorAsesor::class);
    $ajustes = app(Ajustes::class);

    $campus = (int) Campus::query()->value('id');
    $otroCampus = (int) (Campus::query()->where('id', '!=', $campus)->value('id') ?? $campus);
    $situacionAsesor = (int) DB::table('situaciones_asesor')->where('clave', 'activo')->value('id');
    $inactivo = (int) DB::table('situaciones_asesor')->where('clave', 'inactivo')->value('id');

    /** Un asesor propio, del campus que se diga. */
    $asesor = function (string $nombre, int $situacion, ?int $delCampus = null) {
        $persona = Persona::create(['nombre' => $nombre, 'primer_apellido' => 'Prueba'.uniqid(), 'sexo_id' => 1]);
        $a = Asesor::create(['persona_id' => $persona->id, 'situacion_id' => $situacion]);

        if ($delCampus !== null) {
            $a->campus()->sync([$delCampus]);
        }

        return $persona->id;
    };

    /** Un prospecto propio, en el campus que se diga. */
    $prospecto = function (?int $enCampus = null) use ($campus) {
        $persona = Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => uniqid(), 'sexo_id' => 2]);

        return Aspirante::create([
            'persona_id' => $persona->id,
            'campus_id' => $enCampus ?? $campus,
            'situacion_id' => SituacionAspirante::query()->value('id'),
            'etapa_crm_id' => EtapaCrm::query()->orderBy('orden')->value('id'),
        ]);
    };

    echo '1. Una actividad agendada nace abierta y sin momento'.PHP_EOL;

    $quien = (int) Persona::query()->value('id');
    $uno = $prospecto();

    $tarea = $agenda->agendar($uno, [
        'tipo_id' => null,
        'nota' => 'Llamar para confirmar documentos',
        'programado_para' => now()->addDay()->toDateTimeString(),
    ], $quien);

    verificar('Nace AGENDADA', $tarea->estatus === SeguimientoAspirante::AGENDADO, $tarea->estatus);
    // `momento` es cuándo OCURRIÓ: una tarea agendada todavía no ocurre, y
    // llenarlo al agendar haría que el historial dijera que ya se hizo.
    verificar('Sin `momento`: todavía no ocurre', $tarea->momento === null);
    verificar('Con responsable, aunque no se diga cuál', $tarea->persona_id === $quien);
    verificar('Congela la etapa en la que estaba', $tarea->etapa_crm_id === $uno->etapa_crm_id);

    echo PHP_EOL.'2. Cerrarla exige decir CÓMO fue'.PHP_EOL;

    $rechazada = false;

    try {
        $agenda->cerrar($tarea, ['resultado_id' => null], $quien);
    } catch (RuntimeException $e) {
        $rechazada = str_contains($e->getMessage(), 'Di cómo fue');
    }

    verificar('Sin desenlace no se cierra', $rechazada);

    $desenlace = (int) DB::table('resultados_seguimiento')->where('clave', 'contesto')->value('id');
    $etapaDestino = (int) EtapaCrm::query()->orderBy('orden')->skip(2)->value('id');

    $cerrada = $agenda->cerrar($tarea, [
        'resultado_id' => $desenlace,
        'respuesta' => 'Trae el acta el lunes',
        'etapa_destino_id' => $etapaDestino,
    ], $quien);

    verificar('Cerrada queda REALIZADA', $cerrada->estatus === SeguimientoAspirante::REALIZADO);
    verificar('Y ahora sí tiene momento', $cerrada->momento !== null);
    verificar('Con quién la cerró', $cerrada->cerrado_por === $quien);
    verificar('Y la respuesta guardada', $cerrada->respuesta === 'Trae el acta el lunes');
    verificar('El cierre movió al prospecto de etapa',
        $uno->fresh()->etapa_crm_id === $etapaDestino);

    echo PHP_EOL.'3. Lo cerrado no se vuelve a tocar'.PHP_EOL;

    foreach (['cerrar', 'cancelar', 'reprogramar'] as $accion) {
        $rebotado = false;

        try {
            match ($accion) {
                'cerrar' => $agenda->cerrar($cerrada, ['resultado_id' => $desenlace], $quien),
                'cancelar' => $agenda->cancelar($cerrada, 'ya no', $quien),
                'reprogramar' => $agenda->reprogramar($cerrada, now()->addWeek()->toDateTimeString()),
            };
        } catch (RuntimeException $e) {
            $rebotado = true;
        }

        // Si se pudiera cerrar dos veces, el conteo de intentos mentiría.
        verificar("No deja {$accion} una ya cerrada", $rebotado);
    }

    echo PHP_EOL.'4. Cancelar conserva el intento'.PHP_EOL;

    $otra = $agenda->agendar($uno, [
        'tipo_id' => null,
        'nota' => 'Segunda llamada',
        'programado_para' => now()->addDays(3)->toDateTimeString(),
    ], $quien);

    $cancelada = $agenda->cancelar($otra, 'Pidió que no le llamáramos', $quien);

    verificar('Queda CANCELADA', $cancelada->estatus === SeguimientoAspirante::CANCELADO);
    verificar('Con su motivo', $cancelada->respuesta === 'Pidió que no le llamáramos');
    // Borrarla dejaría el historial diciendo que nunca se intentó.
    verificar('Y sigue existiendo: el intento es información',
        SeguimientoAspirante::query()->whereKey($otra->id)->exists());

    echo PHP_EOL.'5. Lo vencido se ve como vencido'.PHP_EOL;

    $vieja = $agenda->agendar($uno, [
        'tipo_id' => null,
        'nota' => 'Llamada que se pasó',
        'programado_para' => now()->subDays(3)->toDateTimeString(),
    ], $quien);

    verificar('Una agendada con fecha pasada está vencida', $vieja->fresh()->estaVencida());
    verificar('Y sale en los pendientes',
        SeguimientoAspirante::query()->pendientes()->whereKey($vieja->id)->exists());
    verificar('Lo cerrado NO sale en pendientes',
        ! SeguimientoAspirante::query()->pendientes()->whereKey($cerrada->id)->exists());

    echo PHP_EOL.'6. Todo apagado: nadie se asigna solo'.PHP_EOL;

    $ana = $asesor('Ana', $situacionAsesor);
    $beto = $asesor('Beto', $situacionAsesor);
    $dormido = $asesor('Dormido', $inactivo);

    $ajustes->guardar([
        CatalogoAjustes::ASESOR_QUIEN_REGISTRA => false,
        CatalogoAjustes::ASIGNACION_ASESOR => AsignadorAsesor::MANUAL,
    ]);

    verificar('En manual y sin interruptor, nadie se asigna solo',
        $asignador->asignar($prospecto(), $ana) === null);

    echo PHP_EOL.'7. Las DOS reglas son independientes'.PHP_EOL;

    /*
     * El interruptor SOLO: el asesor se queda lo suyo y lo demás no se reparte.
     *
     * Estaban fundidas en un desplegable de tres opciones y no se podía tener
     * una sin la otra; son decisiones distintas y esta sección lo fija.
     */
    $ajustes->guardar([
        CatalogoAjustes::ASESOR_QUIEN_REGISTRA => true,
        CatalogoAjustes::ASIGNACION_ASESOR => AsignadorAsesor::MANUAL,
    ]);

    verificar('Con el interruptor, se lo queda quien lo capturó',
        $asignador->asignar($prospecto(), $beto) === $beto);
    // Lo que NO trajo un asesor sigue el modo, que aquí es manual: son dos
    // decisiones y encender una no debe encender la otra.
    verificar('Y con el modo en manual, lo demás NO se reparte',
        $asignador->asignar($prospecto(), null) === null);
    /*
     * Alguien que NO es asesor se crea a propósito.
     *
     * Esto usaba «la primera persona del sistema», y en el demo esa persona SÍ
     * es asesora: la comprobación fallaba por un dato de la escuela y no por el
     * código. Una prueba no puede suponer que alguien no tiene un rol que se
     * asigna desde una pantalla.
     */
    $noEsAsesor = Persona::create(['nombre' => 'Recepción', 'primer_apellido' => uniqid(), 'sexo_id' => 2]);

    verificar('Tampoco lo que captura alguien que no es asesor',
        $asignador->asignar($prospecto(), $noEsAsesor->id) === null);

    // El modo SOLO: se reparte todo, incluso lo que trajo un asesor.
    $ajustes->guardar([
        CatalogoAjustes::ASESOR_QUIEN_REGISTRA => false,
        CatalogoAjustes::ASIGNACION_ASESOR => AsignadorAsesor::SECUENCIAL,
    ]);

    verificar('Sin el interruptor, lo que trae un asesor también entra al turno',
        $asignador->asignar($prospecto(), $beto) !== null);

    echo PHP_EOL.'8. El secuencial reparte por CARGA y no toca a los inactivos'.PHP_EOL;

    // Las dos encendidas a la vez, que es lo que pidió el cliente: el asesor
    // conserva lo suyo Y el resto se reparte solo.
    $ajustes->guardar([
        CatalogoAjustes::ASESOR_QUIEN_REGISTRA => true,
        CatalogoAjustes::ASIGNACION_ASESOR => AsignadorAsesor::SECUENCIAL,
    ]);

    verificar('Las dos a la vez: quien registra conserva lo suyo',
        $asignador->asignar($prospecto(), $ana) === $ana);

    $tocaron = [];

    for ($i = 0; $i < 6; $i++) {
        $tocaron[] = $asignador->asignar($prospecto());
    }

    verificar('Reparte entre más de uno', count(array_unique($tocaron)) > 1,
        count(array_unique($tocaron)).' asesores distintos');
    // Un asesor apagado sale del reparto sin perder lo que ya atendía.
    verificar('El inactivo NO recibe nada', ! in_array($dormido, $tocaron, true));

    /*
     * Lo que iguala el reparto es la CARGA TOTAL, no los últimos seis.
     *
     * Esta comprobación miraba cómo se repartieron esos seis y esperaba que
     * salieran parejos. No tiene por qué: los asesores llegan a la sección con
     * cargas distintas —la sección anterior ya asignó tres— y el algoritmo
     * hace justo lo contrario de repartir en redondo: le da al que menos
     * tiene, hasta emparejar. Se comprueba el resultado, que es lo prometido.
     */
    $activos = Asesor::query()->activos()->pluck('persona_id');

    $cargaFinal = DB::table('aspirante_asesor')
        ->whereIn('persona_id', $activos)
        ->where('titular', true)
        ->groupBy('persona_id')
        ->pluck(DB::raw('COUNT(*) as total'), 'persona_id')
        ->all();

    foreach ($activos as $id) {
        $cargaFinal[$id] ??= 0;
    }

    verificar('La carga entre asesores queda pareja', (max($cargaFinal) - min($cargaFinal)) <= 1,
        json_encode($cargaFinal));

    echo PHP_EOL.'9. El campus acota el turno'.PHP_EOL;

    if ($otroCampus !== $campus) {
        $solitario = $asesor('Solitario', $situacionAsesor, $otroCampus);
        $delOtro = $prospecto($otroCampus);

        $asignador->asignar($delOtro);

        /*
         * Y le toca a alguien que SÍ atiende ese campus.
         *
         * No forzosamente al que lo tiene marcado: por diseño, un asesor sin
         * campus marcado atiende todos —es lo que hace usable la pantalla en
         * una escuela de un plantel— así que también entra en esta rueda.
         * Exigir que fuera el «solitario» sería probar lo contrario de lo que
         * el sistema promete.
         */
        $leToco = $delOtro->asesores()->wherePivot('titular', true)->first()?->persona_id;

        $puedenAtenderlo = Asesor::query()->activos()->deCampus($otroCampus)->pluck('persona_id');

        verificar('Le toca a alguien que atiende ese campus',
            $leToco !== null && $puedenAtenderlo->contains($leToco), (string) $leToco);
    } else {
        echo '  (omitido: la escuela demo tiene un solo campus)'.PHP_EOL;
    }

    echo PHP_EOL.'10. Un solo titular'.PHP_EOL;

    $disputado = $prospecto();
    $asignador->atarComoTitular($disputado, $ana);
    $asignador->atarComoTitular($disputado, $beto);

    $titulares = DB::table('aspirante_asesor')
        ->where('aspirante_id', $disputado->id)->where('titular', true)->count();

    // Dos titulares serían dos comisiones por el mismo alumno: pagar dos veces
    // por un resultado.
    verificar('Reasignar deja UN solo titular', $titulares === 1, (string) $titulares);
    // `asesores()` devuelve modelos ASESOR: su llave es `persona_id`, no `id`.
    // Preguntar por `->id` devolvía null y la comprobación fallaba sin que
    // nada estuviera mal en el código; el mismo despiste estaba en la ficha.
    verificar('Y es el último asignado',
        $disputado->asesores()->wherePivot('titular', true)->first()?->persona_id === $beto);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    // Los ajustes se guardan fuera de la transacción de Eloquent en algunos
    // drivers: se olvida el cache para no dejar el modo cambiado en memoria.
    app(Ajustes::class)->olvidar();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $f) {
    echo '  - '.$f.PHP_EOL;
}
