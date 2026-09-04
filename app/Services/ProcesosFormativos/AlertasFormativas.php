<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Enums\DestinoEvento;
use App\Models\Identidad\Rol;
use App\Models\Plataforma\Aviso;
use App\Models\ProcesosFormativos\AlertaProceso;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Los avisos que el módulo levanta solo: informes por vencer, plazos y demás.
 *
 * ── Por qué hace falta un comando y no un botón ───────────────────────────
 * Los cuatro casos que caza aparecen SIN un acto de nadie: pasa el tiempo. Un
 * informe se vence porque llegó su fecha, no porque alguien pulsara algo, así
 * que no hay ningún punto de la aplicación desde el que dispararlo. Es el mismo
 * argumento que `finanzas:conciliar-cfdi`.
 *
 * ── El RASTRO es lo que impide el goteo ───────────────────────────────────
 * Sin él, el comando diario volvería a avisar cada mañana mientras la condición
 * siga siendo cierta — y un recordatorio que llega treinta días seguidos deja
 * de leerse al tercero. `alertas_proceso` guarda la pareja (expediente, evento,
 * referencia) con índice ÚNICO: no basta un `SELECT` previo, porque dos
 * corridas simultáneas lo pasan las dos.
 *
 * ── Y NO escribe en el expediente ─────────────────────────────────────────
 * Avisar de que un plazo venció no lo suspende ni lo cancela: eso es una
 * decisión con permiso y bitácora, y tomarla desde un comando de madrugada
 * movería expedientes sin que nadie lo pidiera. Mismo criterio que
 * `ConciliadorCfdi` y `acadion:auditar-datos`, que reportan y no corrigen.
 *
 * ── Los textos van AQUÍ y no en un catálogo ───────────────────────────────
 * Cada evento tiene su condición, su destinatario y su momento; una fila nueva
 * en una tabla no produciría ninguna de las tres. Cuando la escuela quiera
 * redactarlos —como en la escalera de cobranza—, el catálogo llega con su
 * lector.
 */
class AlertasFormativas
{
    /** Cuántos días antes se avisa de un informe. */
    public const DIAS_ANTES_INFORME = 5;

    /** Y de que se acaba el periodo. */
    public const DIAS_ANTES_PLAZO = 15;

    public function __construct(private readonly LiberadorDeExpediente $liberador) {}

    /**
     * Recorre los expedientes vivos y levanta lo que toque.
     *
     * @param  bool  $seco  sólo informa a quién le llegaría, sin escribir nada
     * @return array<int, array<string, mixed>> lo levantado (o lo que se
     *                                          levantaría en seco)
     */
    public function correr(?CarbonImmutable $hoy = null, bool $seco = false): array
    {
        $dia = $hoy ?? CarbonImmutable::now()->startOfDay();

        $levantadas = [];

        /*
         * Por LOTES y no todo de golpe: esto corre de madrugada sobre la
         * escuela entera, y quedarse sin memoria a la mitad dejaría media
         * alerta emitida. Es la lección de `GeneradorAdeudos::generarParaTodas`.
         */
        ExpedienteProceso::query()
            ->whereIn('estado', [
                EstadoExpediente::EnCurso->value,
                EstadoExpediente::Suspendido->value,
                EstadoExpediente::Concluido->value,
            ])
            ->with([
                'matricula:id,persona_id,matricula',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'tipoProceso:id,nombre',
                'informes.tipo:id,nombre,es_final',
                'reglaVersion',
            ])
            ->chunkById(200, function ($expedientes) use ($dia, $seco, &$levantadas) {
                foreach ($expedientes as $expediente) {
                    foreach ($this->deEsteExpediente($expediente, $dia) as $alerta) {
                        $emitida = $seco ? true : $this->levantar($expediente, $alerta);

                        if ($emitida) {
                            $levantadas[] = [
                                'expediente' => $expediente->id,
                                'alumno' => $expediente->matricula?->persona?->nombreCompleto(),
                                'matricula' => $expediente->matricula?->matricula,
                                'evento' => $alerta['evento'],
                                'titulo' => $alerta['titulo'],
                            ];
                        }
                    }
                }
            });

        return $levantadas;
    }

    /**
     * Qué le toca a este expediente hoy.
     *
     * @return array<int, array<string, mixed>>
     */
    public function deEsteExpediente(ExpedienteProceso $expediente, CarbonImmutable $dia): array
    {
        return array_merge(
            $this->deLosInformes($expediente, $dia),
            $this->delPlazo($expediente, $dia),
            $this->deLaLiberacion($expediente),
        );
    }

    /**
     * Los informes que vencen o vencieron.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deLosInformes(ExpedienteProceso $expediente, CarbonImmutable $dia): array
    {
        // Un expediente CONCLUIDO ya no trabaja: avisarle de que se le acerca
        // un informe cuando lo que le falta es entregarlo sería ruido. Lo
        // vencido sí se sigue diciendo.
        $alertas = [];

        foreach ($expediente->informes as $informe) {
            if ($informe->fecha_limite === null || $informe->entregado_en !== null) {
                continue;
            }

            $limite = CarbonImmutable::parse($informe->fecha_limite);
            $nombre = ($informe->tipo?->nombre ?? 'informe')
                .($informe->numero > 1 ? ' n.º '.$informe->numero : '');

            if ($limite->lt($dia)) {
                $alertas[] = [
                    'evento' => AlertaProceso::INFORME_VENCIDO,
                    'referencia' => (string) $informe->id,
                    'titulo' => 'Se te pasó la fecha de «'.$nombre.'»',
                    'cuerpo' => 'Tu '.mb_strtolower($nombre).' de '
                        .mb_strtolower($expediente->tipoProceso?->nombre ?? 'tu proceso')
                        .' vencía el '.$limite->format('d/m/Y').' y todavía no lo has entregado. '
                        .'Entrégalo desde tu portal en cuanto puedas.',
                    'prioridad' => 'importante',
                    'para_la_escuela' => false,
                ];

                continue;
            }

            /*
             * El aviso PREVIO se manda una sola vez, en la ventana. Mandarlo
             * cada día de los cinco sería el goteo que el rastro existe para
             * impedir, y el único no bastaría: la referencia es la misma.
             *
             * **El orden de la resta importa**: `diffInDays` devuelve un número
             * CON SIGNO, así que `$limite->diffInDays($dia)` sobre una fecha
             * límite que todavía no llega da un NEGATIVO —y cualquier negativo
             * es menor que cinco—. Escrito al revés, la ventana no acotaba nada:
             * se avisaba de un informe que vence dentro de tres meses. Lo cazó
             * la suite construyendo el caso lejano; con uno cercano, las dos
             * formas dan lo mismo.
             */
            if ($dia->diffInDays($limite) <= self::DIAS_ANTES_INFORME && $expediente->estado !== EstadoExpediente::Concluido) {
                $alertas[] = [
                    'evento' => AlertaProceso::INFORME_POR_VENCER,
                    'referencia' => (string) $informe->id,
                    'titulo' => 'Se acerca la fecha de «'.$nombre.'»',
                    'cuerpo' => 'Tienes hasta el '.$limite->format('d/m/Y').' para entregar tu '
                        .mb_strtolower($nombre).'. Lo subes desde tu portal.',
                    'prioridad' => 'informativo',
                    'para_la_escuela' => false,
                ];
            }
        }

        return $alertas;
    }

    /**
     * El periodo que se acaba.
     *
     * @return array<int, array<string, mixed>>
     */
    private function delPlazo(ExpedienteProceso $expediente, CarbonImmutable $dia): array
    {
        if ($expediente->fecha_fin_programada === null
            || $expediente->estado === EstadoExpediente::Concluido) {
            return [];
        }

        $fin = CarbonImmutable::parse($expediente->fecha_fin_programada);
        $proceso = mb_strtolower($expediente->tipoProceso?->nombre ?? 'tu proceso');

        if ($fin->lt($dia)) {
            /*
             * Vencido: le llega al ALUMNO y también a la escuela. Es el caso que
             * alguien tiene que resolver —ampliar el periodo, suspenderlo o
             * darlo por concluido—, y sin avisar a la escuela se queda ahí
             * hasta que alguien abra la bandeja por casualidad.
             */
            return [[
                'evento' => AlertaProceso::PLAZO_VENCIDO,
                'referencia' => $fin->toDateString(),
                'titulo' => 'Se pasó la fecha de término de tu '.$proceso,
                'cuerpo' => 'Tu '.$proceso.' debía terminar el '.$fin->format('d/m/Y')
                    .' y sigue abierto. Pasa a servicios escolares para ver cómo se cierra.',
                'prioridad' => 'importante',
                'para_la_escuela' => false,
            ]];
        }

        // Con el signo al revés, esto no acota: ver el aviso del informe.
        if ($dia->diffInDays($fin) > self::DIAS_ANTES_PLAZO) {
            return [];
        }

        return [[
            'evento' => AlertaProceso::PLAZO_POR_VENCER,
            'referencia' => $fin->toDateString(),
            'titulo' => 'Tu '.$proceso.' termina pronto',
            'cuerpo' => 'El '.$fin->format('d/m/Y').' se acaba el periodo de tu '.$proceso
                .'. Revisa en tu portal qué te falta para poder liberarte.',
            'prioridad' => 'informativo',
            'para_la_escuela' => false,
        ]];
    }

    /**
     * El que ya cumplió todo y nadie ha liberado.
     *
     * Este aviso es para la ESCUELA, no para el alumno: él ya hizo lo suyo, y
     * decirle «ya puedes liberarte» cuando liberar no está en sus manos sólo lo
     * manda a ventanilla. Lo que hace falta es que alguien de servicios
     * escolares lo vea.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deLaLiberacion(ExpedienteProceso $expediente): array
    {
        if ($expediente->estado !== EstadoExpediente::Concluido) {
            return [];
        }

        if (! $this->liberador->sePuedeLiberar($expediente)) {
            return [];
        }

        return [[
            'evento' => AlertaProceso::LISTO_PARA_LIBERAR,
            'referencia' => '',
            'titulo' => 'Hay un expediente listo para liberar',
            'cuerpo' => ($expediente->matricula?->persona?->nombreCompleto() ?? 'Un alumno')
                .' ('.($expediente->matricula?->matricula ?? '—').') cumplió todo lo que su programa '
                .'pide para '.mb_strtolower($expediente->tipoProceso?->nombre ?? 'su proceso')
                .'. Falta emitir su constancia.',
            'prioridad' => 'informativo',
            'para_la_escuela' => true,
        ]];
    }

    /**
     * Levanta el aviso y anota el rastro, en la misma transacción.
     *
     * @param  array<string, mixed>  $alerta
     * @return bool false si ya se había avisado de esto
     */
    private function levantar(ExpedienteProceso $expediente, array $alerta): bool
    {
        return DB::transaction(function () use ($expediente, $alerta) {
            try {
                /*
                 * El RASTRO se escribe PRIMERO, y es lo que decide.
                 *
                 * Su índice único es la defensa: si ya existe, revienta aquí y
                 * no se levanta ningún aviso. Al revés —crear el aviso y luego
                 * intentar el rastro— dos corridas simultáneas dejarían dos
                 * avisos y un solo rastro.
                 */
                $rastro = AlertaProceso::create([
                    'expediente_id' => $expediente->id,
                    'evento' => $alerta['evento'],
                    'referencia' => $alerta['referencia'],
                    'emitida_en' => now(),
                ]);
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), '1062')) {
                    throw $e;
                }

                return false;
            }

            $aviso = Aviso::create([
                'titulo' => mb_substr($alerta['titulo'], 0, 180),
                'cuerpo' => $alerta['cuerpo'],
                'prioridad' => $alerta['prioridad'],
                'publicado' => true,
                /*
                 * La hora REAL, no la medianoche: con `startOfDay` el aviso sale
                 * fechado «12:00 a.m.» y se lee como si la escuela trabajara de
                 * madrugada. Es el defecto que se vio en el portal del alumno
                 * con los recordatorios de cobranza.
                 */
                'publicado_desde' => now(),
                /*
                 * Caduca. Pasado el plazo diría algo que quizá ya no es cierto
                 * —lo normal es que lo hayan entregado— y la verdad sigue
                 * estando donde siempre, en su expediente.
                 */
                'vigente_hasta' => now()->addDays(30)->endOfDay(),
            ]);

            $alerta['para_la_escuela']
                ? $this->dirigirALaEscuela($aviso)
                : $this->dirigirAlAlumno($aviso, $expediente);

            $rastro->forceFill(['aviso_id' => $aviso->id])->save();

            return true;
        });
    }

    private function dirigirAlAlumno(Aviso $aviso, ExpedienteProceso $expediente): void
    {
        $aviso->destinos()->create([
            'tipo' => DestinoEvento::Alumno,
            'destino_id' => $expediente->matricula?->persona_id,
        ]);
    }

    /**
     * A quien coordina el módulo, por su ROL.
     *
     * Y no a una persona concreta: el responsable de un expediente puede
     * cambiar, irse de vacaciones o dejar la escuela, y un aviso dirigido a él
     * se quedaría sin leer. El rol es lo que sobrevive.
     */
    private function dirigirALaEscuela(Aviso $aviso): void
    {
        $roles = Rol::query()
            ->get()
            ->filter(fn ($rol) => $rol->concede('liberar-expedientes-formativos'));

        foreach ($roles as $rol) {
            $aviso->destinos()->create([
                'tipo' => DestinoEvento::Rol,
                'destino_id' => $rol->id,
            ]);
        }
    }
}
