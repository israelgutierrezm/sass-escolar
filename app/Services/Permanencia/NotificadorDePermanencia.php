<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Enums\DestinoEvento;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Rol;
use App\Models\Permanencia\AvisoPermanencia;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Plataforma\Aviso;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LA ÚNICA PUERTA por la que este módulo le habla a una persona.
 *
 * ── Qué garantiza, y por qué está en un solo sitio ─────────────────────────
 *  1. **El RASTRO se escribe PRIMERO**, y su índice único es lo que decide. Al
 *     revés —crear el aviso y luego intentar el rastro— dos corridas
 *     simultáneas dejarían dos avisos y un solo rastro. Un `SELECT` previo no
 *     basta: lo pasan las dos.
 *  2. **UN aviso, N rastros.** Quien dispara tres reglas la misma madrugada
 *     recibiría tres avisos idénticos en forma, y a la tercera nadie los lee.
 *     El rastro sí va uno por hecho: es lo que impide volver a avisar mañana.
 *     Es la forma de `RecordatorioDeCobranza`, tal cual.
 *  3. **La FRANJA horaria.** Un aviso sobre la situación de alguien no se
 *     entrega a las tres de la mañana. Lo que se levanta fuera de la franja no
 *     se descarta —la situación es cierta— sino que se publica al abrir.
 *  4. **Ningún aviso a la ESCUELA lleva el DATO.** Va dirigido a un ROL, o sea a
 *     varias personas, y algunas no tienen el permiso que abre el detalle. Quien
 *     llama decide qué texto manda; lo que este servicio garantiza es que haya
 *     UN solo sitio donde mirar si eso se cumple.
 *
 * ── Y por qué reusa `Aviso` en vez de estrenar un canal ────────────────────
 * `avisos` + `avisos_destinos` + `AlcanceDeDestinos` ya sabe segmentar por nueve
 * destinos, tiene prioridad, vigencia y constancia de lectura. Un segundo motor
 * de avisos es lo que este proyecto rechazó al descartar `avisos_familiares`.
 *
 * ── Lo que NO hace: correo, SMS ni push ────────────────────────────────────
 * El driver de correo aquí es `log`. Y aunque no lo fuera: el pedido prohíbe
 * mandar el dato por un canal que no exige sesión, así que lo que saldría sería
 * «entra a la plataforma» — que es lo que el aviso ya dice, dentro.
 */
class NotificadorDePermanencia
{
    /** Cuánto vive un aviso de este módulo. */
    public const DIAS_DE_VIGENCIA = 30;

    /**
     * Levanta UN aviso y anota su rastro por cada hecho, en una transacción.
     *
     * @param  array<string, mixed>  $datos  evento, referencias, titulo, cuerpo,
     *                                       prioridad y a quién va dirigido
     * @return int cuántos rastros se anotaron; 0 si ya se había avisado de todo
     */
    public function avisar(
        array $datos,
        ?CasoPermanencia $caso = null,
        ?MatriculaOferta $matricula = null,
        ?CarbonImmutable $ahora = null,
    ): int {
        $momento = $ahora ?? CarbonImmutable::now();

        $referencias = $datos['referencias'] ?? [(string) ($datos['referencia'] ?? '')];

        return DB::transaction(function () use ($datos, $caso, $matricula, $momento, $referencias) {
            $rastros = [];

            foreach ($referencias as $referencia) {
                $rastro = $this->anotar($datos['evento'], (string) $referencia, $caso, $matricula, $momento);

                $rastro === null || $rastros[] = $rastro;
            }

            /*
             * Si TODO lo que se iba a avisar ya tenía rastro, no se levanta
             * nada. Es lo que impide el goteo: un recordatorio que llega treinta
             * días seguidos deja de leerse al tercero.
             */
            if ($rastros === []) {
                return 0;
            }

            $aviso = Aviso::create([
                'titulo' => mb_substr($datos['titulo'], 0, 180),
                'cuerpo' => $datos['cuerpo'],
                /*
                 * `importante` y no `critico`: el crítico obliga a confirmar la
                 * lectura y se pone delante de todo. Una situación que pide
                 * seguimiento admite «ahorita lo veo», y usar el crítico para
                 * esto lo inutiliza para el día que de verdad importe.
                 */
                'prioridad' => $datos['prioridad'] ?? 'importante',
                'publicado' => true,
                'publicado_desde' => $this->cuandoSePuedePublicar($momento),
                /*
                 * Caduca. Pasado el plazo diría algo que probablemente ya no es
                 * cierto —una señal se resuelve, un caso se atiende— y la verdad
                 * sigue estando donde siempre.
                 */
                'vigente_hasta' => $momento->addDays(self::DIAS_DE_VIGENCIA)->endOfDay(),
            ]);

            $destinatarios = $this->dirigir($aviso, $datos);

            foreach ($rastros as $rastro) {
                $rastro->forceFill([
                    'aviso_id' => $aviso->id,
                    'destinatarios' => $destinatarios,
                ])->save();
            }

            return count($rastros);
        });
    }

    /**
     * Anota el rastro de UN hecho, o null si ya estaba.
     *
     * El 1062 del índice único es lo que decide. Un `SELECT` previo lo pasan dos
     * corridas simultáneas.
     */
    private function anotar(
        string $evento,
        string $referencia,
        ?CasoPermanencia $caso,
        ?MatriculaOferta $matricula,
        CarbonImmutable $momento,
    ): ?AvisoPermanencia {
        try {
            return AvisoPermanencia::create([
                'caso_id' => $caso?->id,
                'matricula_oferta_id' => $matricula?->id,
                'evento' => $evento,
                'referencia' => $referencia,
                'emitida_en' => $momento,
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), '1062')) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Cuándo se puede publicar, respetando la franja de la escuela.
     *
     * ── Y por qué no se descarta lo que cae fuera ──────────────────────────
     * La situación es cierta a la hora que sea; lo que la franja decide es
     * cuándo se le enseña. Descartarlo haría que una corrida manual de madrugada
     * —la primera que hace quien está configurando el módulo— dejara sin avisar
     * de todo y nadie sabría por qué.
     */
    public function cuandoSePuedePublicar(CarbonImmutable $momento): CarbonImmutable
    {
        $ajustes = app(Ajustes::class);

        $desde = $ajustes->entero(CatalogoAjustes::PERMANENCIA_AVISOS_DESDE);
        $hasta = $ajustes->entero(CatalogoAjustes::PERMANENCIA_AVISOS_HASTA);

        /*
         * Una franja imposible —cierre antes que apertura— se toma como abierta
         * todo el día. Al revés, dejaría de avisar PARA SIEMPRE, y eso no se
         * descubre hasta que alguien pregunta por qué nadie se enteró de nada.
         * Falla ABIERTO a propósito: aquí el daño lo hace el silencio.
         */
        if ($hasta <= $desde) {
            return $momento;
        }

        if ($momento->hour < $desde) {
            return $momento->setTime($desde, 0);
        }

        if ($momento->hour >= $hasta) {
            return $momento->addDay()->setTime($desde, 0);
        }

        return $momento;
    }

    /**
     * A quién le llega, y cuántos destinos se pusieron.
     *
     * @param  array<string, mixed>  $datos
     */
    private function dirigir(Aviso $aviso, array $datos): int
    {
        $puestos = 0;

        /*
         * A la PERSONA del alumno, y **NO a su familia**.
         *
         * Es deliberado y se aparta de lo que hace cobranza. Allá la familia
         * entra siempre porque quien paga es la familia; aquí lo que se avisa es
         * una situación del alumno, y decírselo a su casa es una decisión de la
         * escuela sobre un dato sensible — se toma en una INTERVENCIÓN, que es
         * un acto humano con su registro y su visibilidad, no como efecto
         * secundario de una regla que corre de madrugada.
         */
        if (($datos['persona_id'] ?? null) !== null) {
            $aviso->destinos()->create([
                'tipo' => DestinoEvento::Alumno,
                'destino_id' => $datos['persona_id'],
            ]);
            $puestos++;
        }

        /*
         * A personas concretas —el responsable de un caso, el de una tarea—.
         * Aquí sí por persona y no por rol: la tarea es de alguien, y mandársela
         * al rol entero es cómo se llega a que nadie la haga.
         */
        foreach (array_unique(array_filter($datos['personas'] ?? [])) as $persona) {
            $aviso->destinos()->create([
                'tipo' => DestinoEvento::Alumno,
                'destino_id' => $persona,
            ]);
            $puestos++;
        }

        /*
         * Y a la ESCUELA por ROL, nunca a una persona: quien coordina se va de
         * vacaciones o deja la escuela, y un aviso dirigido a él se queda sin
         * leer. Los roles se eligen con `concede()` y no con un `whereHas`,
         * porque un rol funcional HEREDA los permisos de su faceta y la consulta
         * directa dejaría fuera a casi todos.
         */
        foreach ($datos['permisos'] ?? [] as $permiso) {
            foreach ($this->rolesQueConceden($permiso) as $rol) {
                $aviso->destinos()->create([
                    'tipo' => DestinoEvento::Rol,
                    'destino_id' => $rol->id,
                ]);
                $puestos++;
            }
        }

        return $puestos;
    }

    /**
     * Los roles que conceden un permiso, heredado incluido.
     *
     * Se memoiza: el comando pregunta por el mismo permiso una vez por caso, y
     * `concede()` recorre la jerarquía cada vez.
     *
     * @return Collection<int, Rol>
     */
    private function rolesQueConceden(string $permiso)
    {
        static $memoria = [];

        return $memoria[$permiso] ??= Rol::query()->get()
            ->filter(fn (Rol $rol) => $rol->concede($permiso))
            ->values();
    }
}
