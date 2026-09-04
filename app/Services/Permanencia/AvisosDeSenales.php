<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\AvisoPermanencia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Avisar de las señales: al alumno las suyas, a la escuela su cola.
 *
 * ── AL ALUMNO sólo lo VALIDADO, y es la decisión central ───────────────────
 * Una señal sin revisar puede resultar un dato mal capturado —una lista que
 * nadie pasó, un acta que llegó tarde—, y avisarle a alguien de algo que mañana
 * se descarta es exactamente el daño que este módulo existe para no hacer. A la
 * ESCUELA sí se le avisa de lo NUEVO: eso es su cola de trabajo, y para eso está
 * el triage.
 *
 * ── UN aviso por persona, no uno por señal ─────────────────────────────────
 * Quien dispara tres reglas la misma madrugada recibiría tres avisos idénticos
 * en forma y a la tercera nadie los lee. El rastro, en cambio, va señal por
 * señal — es lo que impide volver a avisar mañana. Lo sostiene el notificador.
 *
 * ── Y la regla decide si se avisa ──────────────────────────────────────────
 * `avisa_al_alumno` y `avisa_a_la_escuela` viven en la VERSIÓN de la regla desde
 * la fase 1 y hasta hoy no los leía nadie. Aquí reciben su lector: sin ninguno
 * encendido este servicio no manda nada, que es lo que le pasa a una escuela
 * recién migrada — y es lo correcto, porque sus reglas también nacen apagadas.
 */
class AvisosDeSenales
{
    public function __construct(
        private readonly NotificadorDePermanencia $notificador,
        private readonly PlantillaDeAviso $plantillas,
    ) {}

    /**
     * @return array<int, array<string, mixed>> lo avisado
     */
    public function correr(?CarbonImmutable $ahora = null, bool $seco = false): array
    {
        $momento = $ahora ?? CarbonImmutable::now();

        return array_merge(
            $this->alAlumno($momento, $seco),
            $this->aLaEscuela($momento, $seco),
        );
    }

    /**
     * Las señales VALIDADAS de las que el alumno todavía no sabe.
     *
     * ── El cuerpo lo redacta la ESCUELA, y sí puede llevar el dato ─────────
     * El aviso es del portal y se lee con la sesión abierta; el pedido prohíbe
     * mandar el dato por un canal sin sesión y pide al alumno «pendientes
     * concretos». «Tienes una señal» no le sirve para nada.
     *
     * @return array<int, array<string, mixed>>
     */
    private function alAlumno(CarbonImmutable $momento, bool $seco): array
    {
        $avisados = [];

        $this->porAlumno(
            $this->consultaBase()
                ->where('estado_triage', Alerta::VALIDADA)
                ->whereHas('version', fn ($v) => $v->where('avisa_al_alumno', true)),
            function (Collection $senales) use ($momento, $seco, &$avisados) {
                $nuevas = $this->sinAvisar($senales, AvisoPermanencia::SENALES_AL_ALUMNO);

                if ($nuevas->isEmpty()) {
                    return;
                }

                $matricula = $nuevas->first()->matricula;

                $lineas = $nuevas->map(function (Alerta $senal) {
                    $texto = $senal->version?->plantilla_aviso;

                    return $texto === null || trim($texto) === ''
                        ? $this->plantillas->respaldo($senal)
                        : $this->plantillas->rellenar($texto, $senal);
                })->all();

                if ($seco) {
                    $avisados[] = $this->resumen(AvisoPermanencia::SENALES_AL_ALUMNO, $matricula, $nuevas->count());

                    return;
                }

                $anotados = $this->notificador->avisar([
                    'evento' => AvisoPermanencia::SENALES_AL_ALUMNO,
                    'referencias' => $nuevas->pluck('id')->map(fn ($id) => (string) $id)->all(),
                    'titulo' => 'Hay algo que conviene revisar contigo',
                    'cuerpo' => implode("\n\n", $lineas)."\n\n"
                        .'Entra a tu portal para verlo completo. Si algo de esto no cuadra, '
                        .'coméntalo con tu escuela: se puede corregir.',
                    'prioridad' => 'importante',
                    'persona_id' => $matricula?->persona_id,
                ], null, $matricula, $momento);

                $anotados === 0 || $avisados[] = $this->resumen(
                    AvisoPermanencia::SENALES_AL_ALUMNO, $matricula, $anotados
                );
            }
        );

        return $avisados;
    }

    /**
     * Lo que espera en la bandeja, agrupado por ALUMNO.
     *
     * ── Y aquí la categoría SENSIBLE sí decide el texto ────────────────────
     * Este aviso va a un ROL, o sea a varias personas, y algunas no tienen el
     * permiso que abre el detalle de una señal financiera. Así que el cuerpo
     * NUNCA lleva el dato ni la plantilla de la regla: dice cuántas señales hay
     * y a dónde entrar. Quien pueda verlas las verá; quien no, no se entera de
     * un monto por un aviso.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aLaEscuela(CarbonImmutable $momento, bool $seco): array
    {
        $avisados = [];

        $this->porAlumno(
            $this->consultaBase()
                ->where('estado_triage', Alerta::NUEVA)
                ->whereHas('version', fn ($v) => $v->where('avisa_a_la_escuela', true)),
            function (Collection $senales) use ($momento, $seco, &$avisados) {
                $nuevas = $this->sinAvisar($senales, AvisoPermanencia::SENALES_POR_REVISAR);

                if ($nuevas->isEmpty()) {
                    return;
                }

                $matricula = $nuevas->first()->matricula;
                $cuantas = $nuevas->count();

                if ($seco) {
                    $avisados[] = $this->resumen(AvisoPermanencia::SENALES_POR_REVISAR, $matricula, $cuantas);

                    return;
                }

                $anotados = $this->notificador->avisar([
                    'evento' => AvisoPermanencia::SENALES_POR_REVISAR,
                    'referencias' => $nuevas->pluck('id')->map(fn ($id) => (string) $id)->all(),
                    'titulo' => 'Hay señales por revisar',
                    'cuerpo' => $cuantas.' '.($cuantas === 1 ? 'señal' : 'señales')
                        .' de '.($matricula?->persona?->nombreCompleto() ?? 'un alumno')
                        .' ('.($matricula?->matricula ?? '—').') '
                        .($cuantas === 1 ? 'espera' : 'esperan').' revisión en la bandeja de '
                        .'permanencia. Entra para ver de qué se trata y decidir si amerita '
                        .'seguimiento.',
                    'prioridad' => 'informativo',
                    'permisos' => ['validar-alertas'],
                ], null, $matricula, $momento);

                $anotados === 0 || $avisados[] = $this->resumen(
                    AvisoPermanencia::SENALES_POR_REVISAR, $matricula, $anotados
                );
            }
        );

        return $avisados;
    }

    /** @return array<string, mixed> */
    private function resumen(string $evento, $matricula, int $cuantas): array
    {
        return [
            'evento' => $evento,
            'alumno' => $matricula?->persona?->nombreCompleto(),
            'matricula' => $matricula?->matricula,
            'senales' => $cuantas,
        ];
    }

    /** Las señales abiertas, con lo que hace falta para redactar. */
    private function consultaBase()
    {
        return Alerta::query()
            ->abiertas()
            ->with([
                'matricula:id,persona_id,matricula',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'regla:id,nombre,categoria_id',
                'categoria',
                'version',
                'asignaturaGrupo:id,plan_materia_id',
                'asignaturaGrupo.planMateria:id,asignatura_id',
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
            ]);
    }

    /**
     * Recorre por LOTES y llama con las señales de cada alumno juntas.
     *
     * Por lotes porque esto corre de madrugada sobre la escuela entera, y
     * quedarse sin memoria a la mitad dejaría medio aviso emitido — la lección
     * de `GeneradorAdeudos::generarParaTodas`.
     */
    private function porAlumno($consulta, callable $hacer): void
    {
        $consulta->chunkById(300, function ($lote) use ($hacer) {
            foreach ($lote->groupBy('matricula_oferta_id') as $senales) {
                $hacer($senales);
            }
        });
    }

    /**
     * De estas señales, las que todavía no tienen rastro de este evento.
     *
     * El único del rastro es lo que de verdad impide el duplicado, pero sin este
     * filtro el aviso agrupado se armaría con las tres señales y sólo entraría
     * el rastro de la nueva: el alumno leería otra vez lo de la semana pasada.
     *
     * @param  Collection<int, Alerta>  $senales
     * @return Collection<int, Alerta>
     */
    private function sinAvisar(Collection $senales, string $evento): Collection
    {
        $yaAvisadas = AvisoPermanencia::query()
            ->where('evento', $evento)
            ->whereIn('referencia', $senales->pluck('id')->map(fn ($id) => (string) $id))
            ->pluck('referencia')
            ->all();

        return $senales->reject(fn (Alerta $s) => in_array((string) $s->id, $yaAvisadas, true))->values();
    }
}
