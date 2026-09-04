<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Permanencia\AvisoPermanencia;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\Intervencion;
use App\Models\Permanencia\TareaCaso;
use Carbon\CarbonImmutable;

/**
 * Lo que le pasa a un caso sin que nadie haga nada: pasa el tiempo.
 *
 * ── Por qué es un comando y no un botón ────────────────────────────────────
 * Los cuatro casos que caza aparecen SIN un acto de nadie. Un compromiso de
 * primer contacto se vence porque llegó su hora, no porque alguien pulsara algo,
 * así que no hay ningún punto de la aplicación desde el que dispararlo. Mismo
 * argumento que `procesos:avisar` y `finanzas:conciliar-cfdi`.
 *
 * ── LA REGLA: no mueve ni un caso ──────────────────────────────────────────
 * El plan hablaba de «escalamiento automático» y **no se hizo, con su razón**:
 * `escalado` es un estado que una persona elige, y escalar exige decir por qué
 * —«quien lo reciba empieza a ciegas sin eso»—. Si un comando lo moviera,
 * «escalado» dejaría de significar «alguien pidió ayuda» y pasaría a significar
 * también «nadie contestó a tiempo»: dos hechos distintos fundidos en un estado,
 * que es justo el error que este módulo evitó separando las dos máquinas.
 *
 * Y es el criterio del proyecto: `ConciliadorCfdi` no escribe el estatus,
 * `acadion:auditar-datos` no repara solo, `AlertasFormativas` no toca el
 * expediente. Reportan. La decisión es de una persona, y aquí además tiene su
 * bitácora.
 *
 * ── Ni le avisa al ALUMNO ──────────────────────────────────────────────────
 * Un caso es trabajo interno de la escuela. Decirle a alguien «llevas ocho días
 * sin que te llamemos» no le sirve de nada y le informa de un expediente sobre
 * él que quizá ni sabía que existe. Lo que el alumno ve son sus SEÑALES, y eso
 * lo manda `AvisosDeSenales`.
 */
class VigilanciaDeCasos
{
    public function __construct(private readonly NotificadorDePermanencia $notificador) {}

    /**
     * @return array<int, array<string, mixed>> lo avisado
     */
    public function correr(?CarbonImmutable $ahora = null, bool $seco = false): array
    {
        $momento = $ahora ?? CarbonImmutable::now();

        return array_merge(
            $this->slaVencido($momento, $seco),
            $this->sinAsignar($momento, $seco),
            $this->tareasVencidas($momento, $seco),
            $this->loAgendadoParaHoy($momento, $seco),
        );
    }

    /**
     * Casos que se pasaron del compromiso y a los que nadie ha contactado.
     *
     * ── La REFERENCIA es la FECHA del vencimiento, no el día de hoy ────────
     * Con la fecha de hoy se avisaría cada mañana mientras siga sin atenderse, y
     * un recordatorio diario deja de leerse al tercero. Con la del vencimiento
     * se avisa UNA vez por plazo — y si alguien reasigna y fija otro, ése es
     * otro hecho y vuelve a avisar, que es lo correcto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function slaVencido(CarbonImmutable $momento, bool $seco): array
    {
        $avisados = [];

        CasoPermanencia::query()
            ->slaVencido($momento->toDateTimeString())
            ->with(['matricula.persona', 'responsable.persona'])
            ->chunkById(200, function ($casos) use ($momento, $seco, &$avisados) {
                foreach ($casos as $caso) {
                    $horas = (int) $caso->sla_vence_en->diffInHours($momento);

                    $datos = [
                        'evento' => AvisoPermanencia::SLA_VENCIDO,
                        'referencia' => $caso->sla_vence_en->format('Y-m-d H:i'),
                        'titulo' => 'Un caso pasó su plazo de primer contacto',
                        'cuerpo' => 'El caso '.$caso->folio.' de '
                            .($caso->matricula?->persona?->nombreCompleto() ?? 'un alumno')
                            .' se comprometió a un primer contacto para el '
                            .$caso->sla_vence_en->format('Y-m-d H:i').' y todavía no se ha '
                            .'registrado ninguno. Lleva '.$this->enPalabras($horas).' de retraso.',
                        'prioridad' => 'importante',
                        /*
                         * Al responsable POR PERSONA —es suyo— y además a quien
                         * puede escalar. Sólo al responsable, un caso atascado
                         * porque esa persona está de baja no lo destraba nadie.
                         */
                        'personas' => [$caso->responsable?->persona_id],
                        'permisos' => ['escalar-casos'],
                    ];

                    $avisados = $this->emitir($avisados, $datos, $caso, $seco, $momento);
                }
            });

        return $avisados;
    }

    /**
     * Casos abiertos que nadie ha tomado.
     *
     * Es lo único que impide que un caso se quede esperando a que alguien lo
     * mire: sin responsable no hay a quién reclamarle, y el SLA ni siquiera
     * empieza a correr porque se fija al asignar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sinAsignar(CarbonImmutable $momento, bool $seco): array
    {
        $dias = app(Ajustes::class)->entero(CatalogoAjustes::PERMANENCIA_DIAS_SIN_ASIGNAR);

        $avisados = [];

        CasoPermanencia::query()
            ->sinAsignar()
            ->where('abierto_en', '<=', $momento->subDays($dias)->toDateTimeString())
            ->with(['matricula.persona'])
            ->chunkById(200, function ($casos) use ($momento, $seco, $dias, &$avisados) {
                foreach ($casos as $caso) {
                    $datos = [
                        'evento' => AvisoPermanencia::CASO_SIN_ASIGNAR,
                        /*
                         * La referencia es el propio caso: se avisa UNA vez y no
                         * todos los días. Que siga sin responsable se ve en la
                         * bandeja, que tiene su cifra arriba.
                         */
                        'referencia' => (string) $caso->id,
                        'titulo' => 'Hay un caso sin responsable',
                        'cuerpo' => 'El caso '.$caso->folio.' de '
                            .($caso->matricula?->persona?->nombreCompleto() ?? 'un alumno')
                            .' lleva '.$dias.' día'.($dias === 1 ? '' : 's').' abierto y nadie se ha '
                            .'hecho cargo. El compromiso de primer contacto empieza a correr al '
                            .'asignarlo.',
                        'prioridad' => 'importante',
                        'permisos' => ['asignar-casos'],
                    ];

                    $avisados = $this->emitir($avisados, $datos, $caso, $seco, $momento);
                }
            });

        return $avisados;
    }

    /**
     * Tareas del caso que pasaron su fecha.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tareasVencidas(CarbonImmutable $momento, bool $seco): array
    {
        $avisados = [];

        TareaCaso::query()
            ->vencidas($momento->toDateString())
            /*
             * De casos ABIERTOS. Una tarea que quedó pendiente en un caso ya
             * cerrado no se le puede reclamar a nadie: el caso se cerró con su
             * motivo y su resultado, y avisar de ella sería pedir que se
             * reabriera por un renglón que quien cerró ya consideró.
             */
            ->whereHas('caso', fn ($c) => $c->abiertos())
            ->with(['caso.matricula.persona', 'responsable'])
            ->chunkById(200, function ($tareas) use ($momento, $seco, &$avisados) {
                foreach ($tareas as $tarea) {
                    $datos = [
                        'evento' => AvisoPermanencia::TAREA_VENCIDA,
                        'referencia' => (string) $tarea->id,
                        'titulo' => 'Una tarea de seguimiento venció',
                        'cuerpo' => '«'.$tarea->titulo.'», del caso '.($tarea->caso?->folio ?? '—')
                            .' de '.($tarea->caso?->matricula?->persona?->nombreCompleto() ?? 'un alumno')
                            .', vencía el '.$tarea->vence_en->toDateString().' y sigue pendiente.',
                        'prioridad' => 'importante',
                        'personas' => [$tarea->responsable?->persona_id],
                    ];

                    $avisados = $this->emitir($avisados, $datos, $tarea->caso, $seco, $momento);
                }
            });

        return $avisados;
    }

    /**
     * Lo que se agendó y toca hoy.
     *
     * ── Y por eso una PROGRAMADA puede estar en el futuro ──────────────────
     * La fase 5 validaba `before_or_equal:today` para todas, así que agendar era
     * imposible: `programada` existía como estado y no se podía fechar. Se
     * corrigió aquí —realizada no puede ser futura, programada no puede ser
     * pasada—, que es lo que hace que este recordatorio signifique algo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loAgendadoParaHoy(CarbonImmutable $momento, bool $seco): array
    {
        $avisados = [];

        Intervencion::query()
            ->where('estado', Intervencion::PROGRAMADA)
            ->whereDate('fecha', $momento->toDateString())
            ->whereHas('caso', fn ($c) => $c->abiertos())
            ->with(['caso.matricula.persona', 'tipo', 'responsable'])
            ->chunkById(200, function ($agendadas) use ($momento, $seco, &$avisados) {
                foreach ($agendadas as $cita) {
                    $datos = [
                        'evento' => AvisoPermanencia::INTERVENCION_HOY,
                        'referencia' => (string) $cita->id,
                        'titulo' => 'Tienes algo agendado hoy',
                        'cuerpo' => ($cita->tipo?->nombre ?? 'Una intervención').' del caso '
                            .($cita->caso?->folio ?? '—').' de '
                            .($cita->caso?->matricula?->persona?->nombreCompleto() ?? 'un alumno')
                            .' está agendada para hoy.'
                            .($cita->objetivo === null ? '' : ' '.$cita->objetivo),
                        'prioridad' => 'importante',
                        'personas' => [$cita->responsable?->persona_id],
                    ];

                    $avisados = $this->emitir($avisados, $datos, $cita->caso, $seco, $momento);
                }
            });

        return $avisados;
    }

    /**
     * @param  array<int, array<string, mixed>>  $avisados
     * @param  array<string, mixed>  $datos
     * @return array<int, array<string, mixed>>
     */
    private function emitir(
        array $avisados,
        array $datos,
        ?CasoPermanencia $caso,
        bool $seco,
        CarbonImmutable $momento,
    ): array {
        $anotados = $seco ? 1 : $this->notificador->avisar($datos, $caso, null, $momento);

        if ($anotados > 0) {
            $avisados[] = [
                'evento' => $datos['evento'],
                'caso' => $caso?->folio,
                'alumno' => $caso?->matricula?->persona?->nombreCompleto(),
                'referencia' => $datos['referencia'],
            ];
        }

        return $avisados;
    }

    /**
     * Un retraso en horas, leíble.
     *
     * «lleva 192 h de retraso» obliga a dividir de cabeza, y quien mira una cola
     * no lo hace. Es la misma lección que dejó la ficha del caso.
     */
    private function enPalabras(int $horas): string
    {
        if ($horas < 24) {
            return $horas.' hora'.($horas === 1 ? '' : 's');
        }

        $dias = intdiv($horas, 24);

        return $dias.' día'.($dias === 1 ? '' : 's');
    }
}
