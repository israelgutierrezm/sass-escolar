<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Rubrica;
use App\Models\Lms\RubricaCriterio;
use App\Models\ProcesosFormativos\EvaluacionProceso;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\InformeProceso;
use App\Models\ProcesosFormativos\TipoInformeProceso;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el alumno entrega y lo que opinan de él.
 *
 * ── Los informes se PROGRAMAN al asignar, no al entregarse ────────────────
 * Sus filas nacen con la asignación, con las fechas ya calculadas del periodo y
 * la periodicidad de la regla. Así el alumno ve desde el primer día cuántos le
 * tocan y para cuándo; creándolas al entregar, «te falta el segundo parcial» no
 * se podría decir —no habría fila que nombrar— y la fecha límite no existiría
 * hasta después de vencer.
 *
 * ── Y se REPROGRAMAN si cambian las fechas ────────────────────────────────
 * Sólo los que siguen pendientes: mover la fecha de uno ya entregado cambiaría
 * si llegó tarde, que es un hecho fechado.
 *
 * ── El PUNTAJE de una evaluación lo pone el servidor ──────────────────────
 * De la petición se cree qué nivel se eligió, y se comprueba que sea de ESE
 * criterio y de ESA rúbrica. Creyéndole el puntaje, cualquiera se pondría el que
 * quisiera — es exactamente la lección de las rúbricas del LMS.
 */
class InformesYEvaluaciones
{
    public function __construct(private readonly AlcanceDeExpedientes $alcance) {}

    /**
     * Crea (o reprograma) los informes que la regla del expediente pide.
     *
     * @return int cuántos quedaron programados
     */
    public function programar(ExpedienteProceso $expediente): int
    {
        $version = $expediente->reglaVersion;

        if ($version === null || $expediente->fecha_inicio === null) {
            return 0;
        }

        return DB::transaction(function () use ($expediente, $version) {
            $parciales = $this->programarParciales($expediente, $version);
            $final = $this->programarFinal($expediente, $version);

            return $parciales + $final;
        });
    }

    /**
     * El alumno entrega un informe.
     *
     * @throws AvisoParaElUsuario 422 si ya se aceptó
     */
    public function entregar(InformeProceso $informe, string $ruta, ?string $nombreOriginal): InformeProceso
    {
        AvisoParaElUsuario::si(
            $informe->estado === InformeProceso::ACEPTADO,
            422,
            'Ese informe ya está aceptado. Si hay que cambiarlo, pide que te lo devuelvan primero.',
        );

        /*
         * Reemplaza el archivo y devuelve el estado a «entregado» sin revisar:
         * uno reemplazado después de haber sido devuelto no puede seguir
         * diciendo «rechazado» sobre un archivo que nadie miró. La
         * retroalimentación anterior se conserva —el alumno la necesita para
         * saber qué corrigió— hasta que alguien lo revise de nuevo.
         */
        $informe->forceFill([
            'archivo_ruta' => $ruta,
            'nombre_original' => $nombreOriginal,
            'estado' => InformeProceso::ENTREGADO,
            'entregado_en' => now(),
        ])->save();

        return $informe->refresh();
    }

    /**
     * La escuela lo revisa.
     *
     * @throws AvisoParaElUsuario 403 sin permiso, 422 si no está entregado
     */
    public function revisar(
        InformeProceso $informe,
        bool $aceptado,
        ?string $retroalimentacion,
        ?Usuario $quien,
    ): InformeProceso {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('revisar-informes-formativos') === true,
            403,
            'Tu rol no puede revisar informes.',
        );

        $this->alcance->exigirQueAlcance($informe->expediente, $quien);

        AvisoParaElUsuario::aMenosQue(
            $informe->entregado_en !== null,
            422,
            'Ese informe todavía no se ha entregado: no hay nada que revisar.',
        );

        /*
         * Devolverlo EXIGE decir por qué. Sin eso el alumno vuelve a mandar lo
         * mismo, que es lo que esta pantalla viene a evitar. Aceptarlo no lo
         * exige: el comentario es opcional cuando la respuesta ya es buena.
         */
        AvisoParaElUsuario::si(
            ! $aceptado && trim((string) $retroalimentacion) === '',
            422,
            'Para devolver un informe hace falta escribir qué hay que corregir.',
        );

        $informe->forceFill([
            'estado' => $aceptado ? InformeProceso::ACEPTADO : InformeProceso::RECHAZADO,
            'retroalimentacion' => $retroalimentacion,
            'revisado_por' => $quien?->id,
            'revisado_en' => now(),
        ])->save();

        return $informe->refresh();
    }

    /**
     * Guarda una evaluación, calculando su puntaje de los niveles elegidos.
     *
     * @param  array<int, int>  $niveles  criterio => nivel
     *
     * @throws AvisoParaElUsuario 422 si un nivel no es de su criterio
     */
    public function evaluar(
        ExpedienteProceso $expediente,
        string $origen,
        ?Rubrica $rubrica,
        array $niveles,
        ?string $comentarios,
        ?Usuario $quien,
    ): EvaluacionProceso {
        AvisoParaElUsuario::aMenosQue(
            array_key_exists($origen, EvaluacionProceso::ORIGENES),
            422,
            'Ese origen de evaluación no existe.',
        );

        [$respuestas, $puntaje] = $this->resolverRubrica($rubrica, $niveles);

        return DB::transaction(function () use ($expediente, $origen, $rubrica, $respuestas, $puntaje, $comentarios, $quien) {
            /*
             * Una por origen: el supervisor evalúa UNA vez. Corregirla es
             * editarla, no acumular otra que la contradiga y obligue a elegir
             * a cuál creerle. El único de la base lo sostiene.
             */
            $evaluacion = $expediente->evaluaciones()->firstOrNew(['origen' => $origen]);

            $evaluacion->fill([
                'rubrica_id' => $rubrica?->id,
                'comentarios' => $comentarios,
                'capturada_por' => $quien?->id,
            ]);

            $evaluacion->forceFill([
                'respuestas' => $respuestas,
                'puntaje' => $puntaje,
                'firmada_en' => now(),
            ])->save();

            return $evaluacion->refresh();
        });
    }

    /**
     * Qué le falta de papeleo para poder liberarse.
     *
     * Devuelve la LISTA con su razón, como la elegibilidad: «no se puede
     * liberar» manda a la gente a ventanilla, y «te falta el informe final» se
     * resuelve. La liberación de la fase 6 preguntará aquí.
     *
     * @return array<int, string>
     */
    public function impedimentosDePapeleo(ExpedienteProceso $expediente): array
    {
        $version = $expediente->reglaVersion;

        if ($version === null) {
            return [];
        }

        $expediente->loadMissing('informes.tipo', 'evaluaciones', 'excepciones');

        $faltan = [];

        foreach ($expediente->informes as $informe) {
            if ($informe->estado === InformeProceso::ACEPTADO) {
                continue;
            }

            $nombre = '«'.($informe->tipo?->nombre ?? 'informe').'»'
                .($informe->numero > 1 ? ' n.º '.$informe->numero : '');

            /*
             * TRES ramas y no dos: un informe DEVUELTO conserva su
             * `entregado_en` —el de la entrega anterior—, así que con dos caía
             * en «está entregado y sin aceptar» y quien lo leía creía que sólo
             * faltaba que alguien lo revisara. La pelota está del lado
             * contrario, y decirlo mal deja el trámite parado esperando a la
             * persona equivocada.
             */
            $faltan[] = match (true) {
                $informe->estado === InformeProceso::RECHAZADO => 'Te devolvieron '.$nombre.': hay que rehacerlo.',
                $informe->entregado_en === null => 'Falta entregar '.$nombre.'.',
                default => $nombre.' está entregado y todavía sin revisar.',
            };
        }

        $exigidas = [
            EvaluacionProceso::SUPERVISOR => $version->exige_evaluacion_supervisor,
            EvaluacionProceso::ESTUDIANTE => $version->exige_evaluacion_estudiante,
        ];

        foreach ($exigidas as $origen => $seExige) {
            if ($seExige !== true) {
                continue;
            }

            $expediente->evaluaciones->firstWhere('origen', $origen) !== null
                || $faltan[] = 'Falta la evaluación: '
                    .strtolower(EvaluacionProceso::ORIGENES[$origen]).'.';
        }

        return $faltan;
    }

    /**
     * Los parciales, repartidos por la periodicidad de la regla.
     *
     * @return int cuántos quedaron programados
     */
    private function programarParciales(ExpedienteProceso $expediente, $version): int
    {
        $cuantos = (int) ($version->informes_parciales ?? 0);
        $cadaCuantos = (int) ($version->periodicidad_informe_dias ?? 0);

        if ($cuantos < 1 || $cadaCuantos < 1) {
            return 0;
        }

        $tipo = $this->tipoParcial();

        if ($tipo === null) {
            return 0;
        }

        $programados = 0;

        for ($numero = 1; $numero <= $cuantos; $numero++) {
            $limite = $expediente->fecha_inicio->copy()->addDays($cadaCuantos * $numero);

            $programados += $this->programarUno($expediente, $tipo->id, $numero, $limite->toDateString());
        }

        return $programados;
    }

    /** El final, al terminar el periodo. */
    private function programarFinal(ExpedienteProceso $expediente, $version): int
    {
        if ($version->exige_informe_final !== true) {
            return 0;
        }

        $tipo = TipoInformeProceso::query()->activos()->where('es_final', true)->first();

        if ($tipo === null) {
            return 0;
        }

        // Al fin del periodo si lo hay; si no, sin fecha: un límite inventado
        // marcaría como vencido algo que nadie fechó.
        $limite = $expediente->fecha_fin_programada?->toDateString();

        return $this->programarUno($expediente, $tipo->id, 1, $limite);
    }

    /**
     * Crea uno, o le mueve la fecha si sigue pendiente.
     *
     * Un informe ya ENTREGADO no se reprograma: mover su fecha cambiaría si
     * llegó tarde, que es un hecho fechado y no una configuración.
     */
    private function programarUno(ExpedienteProceso $expediente, int $tipoId, int $numero, ?string $limite): int
    {
        $existente = $expediente->informes()
            ->where('tipo_informe_id', $tipoId)
            ->where('numero', $numero)
            ->first();

        if ($existente === null) {
            $expediente->informes()->create([
                'tipo_informe_id' => $tipoId,
                'numero' => $numero,
                'fecha_limite' => $limite,
            ]);

            return 1;
        }

        if ($existente->entregado_en === null) {
            $existente->forceFill(['fecha_limite' => $limite])->save();
        }

        return 0;
    }

    /**
     * El tipo de informe PARCIAL: el primero que no cierra el proceso.
     *
     * Por la BANDERA y no por la clave: la escuela puede llamarlo «Reporte
     * mensual» o «Avance», y preguntar por `clave === 'parcial'` funciona hoy y
     * deja de funcionar en silencio el día que edite su catálogo.
     */
    private function tipoParcial(): ?TipoInformeProceso
    {
        return TipoInformeProceso::query()
            ->activos()
            ->where('es_final', false)
            ->orderBy('orden')
            ->orderBy('id')
            ->first();
    }

    /**
     * Los niveles elegidos, comprobados y congelados.
     *
     * @param  array<int, int>  $niveles
     * @return array{0: array<int, array<string, mixed>>|null, 1: float|null}
     */
    private function resolverRubrica(?Rubrica $rubrica, array $niveles): array
    {
        if ($rubrica === null || $niveles === []) {
            return [null, null];
        }

        $rubrica->loadMissing('criterios.niveles');

        $respuestas = [];
        $puntaje = 0.0;

        foreach ($rubrica->criterios as $criterio) {
            $elegido = $niveles[$criterio->id] ?? null;

            if ($elegido === null) {
                continue;
            }

            $nivel = $criterio->niveles->firstWhere('id', (int) $elegido);

            /*
             * El nivel tiene que ser DE ESE criterio. Sin comprobarlo, mandar
             * el id del nivel más alto de otro criterio daría puntos que esa
             * rúbrica no concede — y el desplegable no es una defensa.
             */
            AvisoParaElUsuario::aMenosQue(
                $nivel !== null,
                422,
                'Uno de los niveles elegidos no es de su criterio.',
            );

            $puntaje += (float) $nivel->puntos;

            /*
             * Se congela el TEXTO además de los puntos: la rúbrica se puede
             * editar después, y una evaluación que se relea contra la de hoy
             * diría algo que el supervisor nunca firmó.
             */
            $respuestas[] = [
                'criterio_id' => $criterio->id,
                // `titulo` y no `nombre`: el nombre de una columna se
                // pregunta, no se adivina.
                'criterio' => $criterio->titulo,
                'nivel_id' => $nivel->id,
                'nivel' => $nivel->titulo,
                'puntos' => (float) $nivel->puntos,
                'maximo' => $criterio->maximo(),
            ];
        }

        return [$respuestas === [] ? null : $respuestas, $respuestas === [] ? null : round($puntaje, 2)];
    }
}
