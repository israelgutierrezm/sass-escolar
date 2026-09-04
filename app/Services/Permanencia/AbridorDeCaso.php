<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\RiesgoMatricula;
use App\Models\Permanencia\TransicionCaso;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Abrir un caso desde una alerta validada, y sumarle las siguientes.
 *
 * ── Por qué no vive en `TransicionDeCaso` ──────────────────────────────────
 * Abrir son cuatro cosas que las demás transiciones no hacen: numerar de forma
 * atómica, congelar el riesgo del momento, atar la alerta que lo originó y
 * garantizar que no salgan dos. Metidas entre las demás, cualquiera de ellas se
 * perdería el día que alguien agregue un estado. Es la misma separación que
 * `LiberadorDeExpediente` frente a `TransicionDeExpediente`.
 *
 * Aun así el caso **nace por la puerta de siempre**: la apertura anota su
 * renglón en `transiciones_caso` con el origen en NULL. Sin él, el movimiento
 * más importante del trámite sería el único sin rastro — y «cuánto tarda un caso
 * en asignarse» no tendría desde cuándo contar.
 */
class AbridorDeCaso
{
    public function __construct(private readonly AlcanceDeCasos $alcance) {}

    /**
     * Abre un caso desde una alerta VALIDADA.
     *
     * @param  int|null  $slaHoras  el compromiso de primer contacto
     */
    public function abrir(
        Alerta $alerta,
        ?Usuario $quien,
        ?string $prioridad = null,
        ?int $slaHoras = null,
        ?string $ip = null,
    ): CasoPermanencia {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('abrir-casos') === true,
            403,
            'Tu rol no puede abrir casos de seguimiento.',
        );

        /*
         * SÓLO desde una alerta validada. Abrir desde una sin revisar saltaría
         * el triage entero —que existe para que nadie acompañe a alguien por una
         * señal que resultó ser un dato mal capturado— y desde una descartada
         * contradiría a quien la descartó.
         */
        AvisoParaElUsuario::aMenosQue(
            $alerta->estado_triage === Alerta::VALIDADA,
            422,
            $alerta->estado_triage === Alerta::DESCARTADA
                ? 'Esa señal se descartó: no se abre un caso sobre algo que alguien decidió que no amerita.'
                : 'Primero hay que validar la señal: el caso se abre sobre lo que ya se revisó.',
        );

        $matricula = $alerta->matricula;

        AvisoParaElUsuario::si($matricula === null, 422, 'La señal no tiene matrícula.');

        return DB::transaction(function () use ($alerta, $matricula, $quien, $prioridad, $slaHoras, $ip) {
            /*
             * ¿Ya hay uno abierto? Se le SUMA la alerta en vez de abrir otro.
             *
             * Alguien que ya está siendo acompañado por su asistencia y al que
             * además le sale una señal académica no necesita un segundo caso:
             * necesita que la nueva entre al que ya tiene. Con dos, las
             * intervenciones se reparten y acaban dos personas llamando al mismo
             * alumno.
             */
            $abierto = CasoPermanencia::query()
                ->abiertos()
                ->where('matricula_oferta_id', $matricula->id)
                ->lockForUpdate()
                ->first();

            if ($abierto !== null) {
                $this->sumarAlerta($abierto, $alerta);

                return $abierto;
            }

            $riesgo = RiesgoMatricula::query()->vigenteDe($matricula->id)->first();

            try {
                $caso = CasoPermanencia::create([
                    'folio' => $this->folio(),
                    'matricula_oferta_id' => $matricula->id,
                    // Se COPIA: un cambio de plantel no puede mover un caso
                    // cerrado de reporte.
                    'campus_id' => $matricula->oferta?->campus_id,
                    'ciclo_id' => $alerta->ciclo_id,
                    'estado' => EstadoCaso::Abierto->value,
                    'prioridad' => $prioridad ?? $this->prioridadPara($alerta),
                    /*
                     * El riesgo AL ABRIR, congelado. Leerlo en vivo haría que un
                     * caso resuelto se viera como si nunca hubiera hecho falta
                     * —el riesgo baja justamente porque el caso funcionó—, y con
                     * eso se pierde la única forma de medir si sirvió.
                     */
                    'nivel_riesgo_apertura_id' => $riesgo?->nivelQueManda()?->id,
                    'puntaje_apertura' => $riesgo?->puntaje,
                    'abierto_por' => $quien?->id,
                    'abierto_en' => now(),
                    'sla_vence_en' => $slaHoras === null ? null : now()->addHours($slaHoras),
                ]);
            } catch (QueryException $e) {
                /*
                 * El único de la base es lo que de verdad decide: dos
                 * coordinadores mirando la misma alerta pasan el `SELECT` los
                 * dos. Que reviente aquí significa que el otro ya lo abrió.
                 */
                if (! str_contains($e->getMessage(), '1062')) {
                    throw $e;
                }

                $caso = CasoPermanencia::query()->abiertos()
                    ->where('matricula_oferta_id', $matricula->id)->firstOrFail();

                $this->sumarAlerta($caso, $alerta);

                return $caso;
            }

            // El renglón de apertura, con el origen en NULL.
            TransicionCaso::create([
                'caso_id' => $caso->id,
                'estado_origen' => null,
                'estado_destino' => EstadoCaso::Abierto->value,
                'motivo' => 'Abierto desde la señal «'.($alerta->regla?->nombre ?? '—').'».',
                'quien' => $quien?->id,
                'ip' => $ip,
                'momento' => now(),
            ]);

            $this->sumarAlerta($caso, $alerta);

            return $caso;
        });
    }

    /**
     * Ata una alerta a un caso. Idempotente por el único de la base.
     *
     * Una alerta pertenece a UN caso: con dos, «de qué señales salió este
     * seguimiento» tendría dos respuestas.
     */
    public function sumarAlerta(CasoPermanencia $caso, Alerta $alerta): void
    {
        try {
            DB::table('caso_alerta')->insert([
                'caso_id' => $caso->id,
                'alerta_id' => $alerta->id,
                'sumada_en' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), '1062')) {
                throw $e;
            }
        }
    }

    /**
     * Reabrir: un caso NUEVO que apunta al anterior.
     *
     * No se resucita el cerrado. El cierre es un hecho fechado con su resultado,
     * y reescribirlo borraría la medición de RECURRENCIA — que es justo lo que
     * este módulo existe para medir. Es el molde del acta de corrección, de la
     * nota de crédito y de la liberación formativa.
     */
    public function reabrir(
        CasoPermanencia $cerrado,
        string $motivo,
        ?Usuario $quien,
        ?string $ip = null,
    ): CasoPermanencia {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('cerrar-casos') === true,
            403,
            'Tu rol no puede reabrir casos.',
        );

        $this->alcance->exigirQueAlcance($cerrado, $quien);

        AvisoParaElUsuario::aMenosQue(
            $cerrado->estado === EstadoCaso::Cerrado,
            422,
            'Sólo se reabre lo que está cerrado. Éste sigue abierto.',
        );

        AvisoParaElUsuario::si(
            trim($motivo) === '',
            422,
            'Reabrir exige decir por qué: es lo que explica que la situación volvió.',
        );

        return DB::transaction(function () use ($cerrado, $motivo, $quien, $ip) {
            $nuevo = CasoPermanencia::create([
                'folio' => $this->folio(),
                'matricula_oferta_id' => $cerrado->matricula_oferta_id,
                'campus_id' => $cerrado->campus_id,
                'ciclo_id' => $cerrado->ciclo_id,
                'estado' => EstadoCaso::Abierto->value,
                'prioridad' => $cerrado->prioridad,
                'abierto_por' => $quien?->id,
                'abierto_en' => now(),
                'caso_origen_id' => $cerrado->id,
            ]);

            TransicionCaso::create([
                'caso_id' => $nuevo->id,
                'estado_origen' => null,
                'estado_destino' => EstadoCaso::Abierto->value,
                'motivo' => 'Reapertura de '.$cerrado->folio.': '.trim($motivo),
                'quien' => $quien?->id,
                'ip' => $ip,
                'momento' => now(),
            ]);

            return $nuevo;
        });
    }

    /**
     * El folio, con incremento ATÓMICO.
     *
     * Nunca `MAX(folio)+1`, que colisiona bajo concurrencia. Y su tabla va SIN
     * `id` autoincremental: un INSERT sobre una que lo tenga pisa
     * `LAST_INSERT_ID()` y el incremento deja de ser atómico — la trampa
     * documentada de `contadores_matricula`.
     *
     * Por AÑO: el 1 de enero vuelve a empezar, que es como se numeran los
     * expedientes en cualquier oficina.
     */
    private function folio(): string
    {
        $anio = now()->year;

        DB::statement(
            'INSERT INTO contadores_caso (clave, valor, created_at, updated_at)
             VALUES (?, LAST_INSERT_ID(1), NOW(), NOW())
             ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1), updated_at = NOW()',
            [(string) $anio],
        );

        $consecutivo = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('CASO-%d-%05d', $anio, $consecutivo);
    }

    /**
     * La prioridad de partida, DERIVADA de la severidad de la señal.
     *
     * Es un punto de partida y no una sentencia: quien abre puede ponerla a
     * mano, y la pantalla la deja cambiar después. Sin derivarla, todos los
     * casos nacerían en «media» y el orden de la cola no diría nada el primer
     * día.
     *
     * **Pública porque la PANTALLA la pregunta** para preseleccionar el
     * desplegable. Recalculándola en el Vue habría dos definiciones, y el día
     * que una cambie el formulario prometería una cosa y el caso nacería con
     * otra — que es justo lo que se vio: el aviso decía «sale de la severidad»
     * y el desplegable arrancaba siempre en «media».
     */
    public function prioridadPara(Alerta $alerta): string
    {
        return match ($alerta->severidad) {
            'critico', 'alto' => 'alta',
            'informativo', 'bajo' => 'baja',
            default => 'media',
        };
    }
}
