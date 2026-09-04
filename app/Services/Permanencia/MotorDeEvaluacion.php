<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CorridaEvaluacion;
use App\Models\Permanencia\ExclusionReglaAlerta;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\Medicion;
use App\Permanencia\RegistroProveedores;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El motor: recorre alumnos, evalúa reglas y levanta, actualiza o cierra alertas.
 *
 * ── Lo que este servicio NO hace, y es lo más importante ───────────────────
 * **No escribe una sola fila en `matricula_oferta`, `inscripcion`, `historial`,
 * `asistencia_clase`, `adeudos` ni en ninguna tabla de situaciones.** Reporta.
 * El pedido lo dice —«una alerta no debe causar una sanción automática»— y aquí
 * la tentación es real: la situación `condicionado` existe en el catálogo del
 * demo y nadie la usa, así que ponerla sola parecería una mejora. Sería una
 * sanción decidida por un cron de madrugada sobre un dato que quizá esté mal
 * capturado. Una prueba corre el motor entero y comprueba que ninguna de esas
 * tablas cambió.
 *
 * Mismo criterio que `ConciliadorCfdi`, `acadion:auditar-datos` y
 * `AlertasFormativas`.
 *
 * ── Tres resultados por evaluación, no dos ────────────────────────────────
 * `dispara`, `no_dispara` y **`sin_datos`**. La tercera es la que impide que
 * media escuela salga en rojo el día que un docente se enferma: bajo la
 * cobertura mínima de la regla, la respuesta no es «no» — es que no hay con qué
 * contestar. Medido en el demo: `asistencia_clase` tiene 8 filas para 17
 * inscripciones.
 *
 * ── La deduplicación la sostiene la BASE ──────────────────────────────────
 * Mientras la alerta siga abierta se ACTUALIZA la que hay con el valor nuevo, en
 * vez de crear otra. El `SELECT` previo existe para el camino normal, pero lo
 * que de verdad decide es el índice único sobre `clave_dedup`: dos corridas
 * simultáneas pasan el SELECT las dos. Es la lección del rastro de
 * `AlertasFormativas`, y aquí importa más porque el pedido pide explícitamente
 * «evitar una alerta nueva diaria por la misma causa».
 *
 * ── Una regla que revienta NO detiene a las demás ─────────────────────────
 * Cada regla va en su propio `try`: se aísla, se cuenta y su error queda en la
 * corrida CON SU NOMBRE. Una regla mal configurada en una escuela no puede
 * dejar sin evaluar a las otras diecinueve, y quien lee el informe a las siete
 * de la mañana necesita saber cuál falló sin cruzar un id contra una tabla.
 */
class MotorDeEvaluacion
{
    /** Por lotes: esto corre de madrugada sobre la escuela entera. */
    private const LOTE = 200;

    /** La corrida en curso, para que las filas de riesgo la apunten. */
    private ?int $corridaActual = null;

    public function __construct(
        private readonly RegistroProveedores $proveedores,
        private readonly ModulosDeLaEscuela $modulos,
        private readonly CalculadoraDeRiesgo $riesgo,
    ) {}

    /**
     * Una corrida completa.
     *
     * @param  array<int, int>|null  $matriculas  sólo éstas; null = todas las vivas
     * @param  bool  $seco  mide y no escribe nada
     */
    public function correr(
        ?array $matriculas = null,
        string $disparo = 'programada',
        bool $seco = false,
        ?CarbonImmutable $hoy = null,
    ): CorridaEvaluacion {
        $inicio = microtime(true);
        $dia = $hoy ?? CarbonImmutable::now();

        $corrida = new CorridaEvaluacion([
            'iniciada_en' => $dia,
            'disparo' => $disparo,
            'corrida_por' => auth()->id(),
        ]);

        /*
         * La corrida se guarda ANTES del recorrido, en cuanto sabe que va a
         * escribir: cada fila de riesgo la apunta, y con la corrida guardada al
         * final habría que rellenar esa columna después —o dejarla en null, y
         * entonces no se podría contestar «de qué corrida salió este número»—.
         *
         * En modo seco no se guarda nada, así que las filas de riesgo tampoco
         * llegan a existir.
         */
        $seco || $corrida->save();
        $this->corridaActual = $seco ? null : $corrida->id;

        $reglas = $this->reglasQueRigen($dia);

        $contadores = [
            'matriculas_evaluadas' => 0,
            'reglas_evaluadas' => count($reglas),
            'alertas_creadas' => 0,
            'alertas_actualizadas' => 0,
            'alertas_resueltas' => 0,
            'alertas_obsoletas' => 0,
            'sin_datos' => 0,
            'riesgos_recalculados' => 0,
        ];

        $errores = [];

        /*
         * Lo primero: jubilar las alertas de reglas que ya no rigen.
         *
         * Va ANTES de evaluar y no después, porque una regla apagada no debe
         * aparecer en el recorrido y sus alertas tienen que quedar marcadas
         * aunque la corrida falle a la mitad.
         */
        $seco || $contadores['alertas_obsoletas'] = $this->jubilarHuerfanas($reglas);

        $this->porLotes($matriculas, function (MatriculaOferta $matricula) use (
            $reglas, $dia, $seco, &$contadores, &$errores
        ) {
            $contadores['matriculas_evaluadas']++;

            $exclusiones = $this->exclusionesDe($matricula, $dia);

            foreach ($reglas as $par) {
                [$regla, $version] = $par;

                try {
                    $this->evaluarUna($matricula, $regla, $version, $exclusiones, $dia, $seco, $contadores);
                } catch (Throwable $e) {
                    /*
                     * Se aísla POR REGLA y no por alumno: un error de una regla
                     * es de la regla —una métrica que su proveedor no conoce,
                     * una columna que se retiró— y afecta a todos igual. Se
                     * anota una vez, con su nombre, y se sigue.
                     */
                    $errores[$regla->id] ??= [
                        'regla' => $regla->nombre,
                        'regla_id' => $regla->id,
                        'error' => mb_substr($e->getMessage(), 0, 300),
                        'veces' => 0,
                    ];
                    $errores[$regla->id]['veces']++;
                }
            }

            /*
             * El riesgo compuesto, DESPUÉS de evaluar todas sus reglas.
             *
             * Antes mediría con las alertas de ayer; a mitad del bucle, con la
             * mitad de las de hoy. Y va aislado por su cuenta: un fallo
             * calculando el riesgo de un alumno no puede dejar sin evaluar a los
             * que faltan — el mismo criterio que las reglas.
             */
            try {
                $resultado = $this->riesgo->recalcular($matricula, $this->corridaActual, $seco);
                ($resultado['guardado'] ?? false) && $contadores['riesgos_recalculados']++;
            } catch (Throwable $e) {
                $errores['riesgo'] ??= [
                    'regla' => 'Cálculo del riesgo compuesto',
                    'regla_id' => null,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                    'veces' => 0,
                ];
                $errores['riesgo']['veces']++;
            }
        });

        $corrida->fill($contadores + [
            'terminada_en' => now(),
            'errores' => $errores === [] ? null : array_values($errores),
            'milisegundos' => (int) round((microtime(true) - $inicio) * 1000),
        ]);

        $seco || $corrida->save();

        $this->corridaActual = null;

        return $corrida;
    }

    /**
     * Las reglas encendidas con su versión vigente.
     *
     * Una regla encendida SIN versión vigente no se evalúa y no es un error: es
     * una regla a la que se le acabó la versión. La pantalla ya lo dice en su
     * renglón, y reventar aquí dejaría sin evaluar a las demás.
     *
     * @return array<int, array{0: ReglaAlerta, 1: ReglaAlertaVersion}>
     */
    private function reglasQueRigen(CarbonImmutable $dia): array
    {
        $salida = [];

        foreach (ReglaAlerta::query()->activas()->with('versiones', 'categoria')->get() as $regla) {
            $version = $regla->versionVigente($dia->toDateString());

            if ($version === null) {
                continue;
            }

            // El módulo del proveedor: con el LMS apagado, sus reglas no se
            // evalúan. Se comprueba UNA vez por corrida y no por alumno.
            $proveedor = $this->proveedores->de($regla->proveedor);

            if ($proveedor === null) {
                continue;
            }

            $modulo = $proveedor->modulo();

            if ($modulo !== null && ! $this->modulos->activo($modulo)) {
                continue;
            }

            $salida[] = [$regla, $version];
        }

        return $salida;
    }

    /**
     * Las alertas abiertas cuya regla o versión ya no rige → OBSOLETAS.
     *
     * No «resueltas»: nadie arregló nada. Confundirlas haría que apagar una
     * regla se leyera como que doscientos alumnos se recuperaron, y ese número
     * acabaría en un informe.
     *
     * @param  array<int, array{0: ReglaAlerta, 1: ReglaAlertaVersion}>  $reglas
     */
    private function jubilarHuerfanas(array $reglas): int
    {
        $vivas = collect($reglas)->map(fn (array $p) => $p[1]->id)->all();

        return Alerta::query()
            ->abiertas()
            ->when($vivas !== [], fn ($q) => $q->whereNotIn('regla_version_id', $vivas))
            ->update([
                'estado_senal' => Alerta::OBSOLETA,
                'cerrada_en' => now(),
                'evidencia_cierre' => json_encode([
                    'motivo' => 'La regla se apagó o cambió de versión: se dejó de vigilar esta señal. '
                        .'No significa que la situación se haya resuelto.',
                    'cerrada_por' => 'motor',
                ], JSON_UNESCAPED_UNICODE),
            ]);
    }

    /**
     * Las matrículas a evaluar, por lotes.
     *
     * Sólo las que tienen oferta: sin ella no se puede afirmar su campus ni su
     * programa, y ninguna regla las alcanzaría de todos modos. Y `chunkById`
     * porque esto recorre la escuela entera de madrugada: cargarla en memoria
     * es cómo se muere a la mitad dejando media evaluación hecha.
     *
     * @param  array<int, int>|null  $soloEstas
     */
    private function porLotes(?array $soloEstas, callable $hacer): void
    {
        MatriculaOferta::query()
            ->whereHas('oferta')
            ->when($soloEstas !== null, fn ($q) => $q->whereIn('id', $soloEstas))
            ->with(['oferta:id,campus_id,programa_academico_id,plan_id,modalidad',
                'oferta.programaAcademico:id,nivel_estudios_id'])
            ->chunkById(self::LOTE, function ($lote) use ($hacer) {
                foreach ($lote as $matricula) {
                    $hacer($matricula);
                }
            });
    }

    /**
     * Las exclusiones vigentes de esta matrícula, indexadas por regla.
     *
     * La clave 0 es la exclusión GENERAL —`regla_id` en null—, que es el caso de
     * la licencia médica: no se excluye de una regla, se excluye del módulo
     * mientras dure.
     *
     * @return array<int, ExclusionReglaAlerta>
     */
    private function exclusionesDe(MatriculaOferta $matricula, CarbonImmutable $dia): array
    {
        return ExclusionReglaAlerta::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->vigentes($dia->toDateString())
            ->get()
            ->keyBy(fn (ExclusionReglaAlerta $e) => $e->regla_id ?? 0)
            ->all();
    }

    /**
     * @param  array<int, ExclusionReglaAlerta>  $exclusiones
     * @param  array<string, int>  $contadores
     */
    private function evaluarUna(
        MatriculaOferta $matricula,
        ReglaAlerta $regla,
        ReglaAlertaVersion $version,
        array $exclusiones,
        CarbonImmutable $dia,
        bool $seco,
        array &$contadores,
    ): void {
        if (! $regla->alcanzaA($matricula)) {
            return;
        }

        /*
         * Una exclusión vigente cierra lo que hubiera y no evalúa.
         *
         * Cerrarlo importa: sin eso, una alerta levantada ayer se quedaría
         * abierta para siempre en la cola de alguien, y la exclusión no habría
         * servido de nada.
         */
        if (isset($exclusiones[$regla->id]) || isset($exclusiones[0])) {
            $seco || $contadores['alertas_obsoletas'] += $this->cerrarAbiertas(
                $matricula, $regla, Alerta::OBSOLETA,
                ['motivo' => 'Hay una exclusión vigente para este alumno.',
                    'exclusion' => ($exclusiones[$regla->id] ?? $exclusiones[0])->motivo],
            );

            return;
        }

        $proveedor = $this->proveedores->de($regla->proveedor);
        $umbral = $this->umbralDe($version, $matricula);

        foreach ($proveedor->medir($matricula, $version->metrica, $version) as $medicion) {
            $this->resolver($matricula, $regla, $version, $medicion, $umbral, $dia, $seco, $contadores);
        }
    }

    /**
     * El umbral: el fijo, o el del PLAN.
     *
     * Leerlo del plan y no copiarlo es lo que impide que corregir el plan deje
     * la regla comparando contra un número viejo. Sin plan capturado devuelve
     * null, y una comparación contra null no cruza nunca — que es el lado que no
     * molesta a nadie.
     */
    private function umbralDe(ReglaAlertaVersion $version, MatriculaOferta $matricula): ?float
    {
        if ($version->umbral_fuente !== ReglaAlertaVersion::FUENTE_PLAN) {
            return $version->umbral;
        }

        $minimo = $matricula->oferta?->plan?->calificacion_minima_aprobatoria;

        return $minimo === null ? null : (float) $minimo;
    }

    /** @param array<string, int> $contadores */
    private function resolver(
        MatriculaOferta $matricula,
        ReglaAlerta $regla,
        ReglaAlertaVersion $version,
        Medicion $medicion,
        ?float $umbral,
        CarbonImmutable $dia,
        bool $seco,
        array &$contadores,
    ): void {
        /*
         * SIN DATOS: ni dispara ni deja de disparar.
         *
         * Y NO se cierra lo que hubiera abierto. Es deliberado: que hoy no haya
         * con qué medir no significa que la situación de ayer se resolviera —un
         * docente que deja de pasar lista no cura la inasistencia—. La alerta se
         * queda como está y su fecha de última evaluación lo dice.
         */
        if (! $medicion->hayDato() || $medicion->cobertura < $version->cobertura_minima) {
            $contadores['sin_datos']++;

            return;
        }

        $abierta = Alerta::query()
            ->abiertas()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('regla_id', $regla->id)
            ->where('asignatura_grupo_id', $medicion->asignaturaGrupoId)
            ->first();

        if (! $version->cruza($medicion->valor, $umbral)) {
            /*
             * Dejó de ser cierta: se RESUELVE con la evidencia de la mejora.
             *
             * Guardarla es lo que permite decir «tu asistencia subió del 68 al
             * 84 %» en vez de que la alerta desaparezca sin explicación —y sin
             * ella nadie puede medir si la intervención sirvió, que es lo único
             * que este módulo promete—.
             */
            if ($abierta !== null && ! $seco) {
                $abierta->update([
                    'estado_senal' => Alerta::RESUELTA,
                    'cerrada_en' => now(),
                    'ultima_evaluacion_en' => now(),
                    'evidencia_cierre' => [
                        'motivo' => 'La señal dejó de cumplirse.',
                        'valor_al_cerrar' => $medicion->valor,
                        'umbral' => $umbral,
                        'evidencia' => $medicion->evidencia,
                        'cerrada_por' => 'motor',
                    ],
                ]);

                $contadores['alertas_resueltas']++;
            }

            return;
        }

        // Cruza. Si ya estaba abierta se ACTUALIZA: no se levanta otra.
        if ($abierta !== null) {
            $seco || $abierta->update([
                'valor_observado' => $medicion->valor,
                'umbral' => $umbral,
                'cobertura' => $medicion->cobertura,
                'evidencia' => $medicion->evidencia,
                'ultima_evaluacion_en' => now(),
            ]);

            $contadores['alertas_actualizadas']++;

            return;
        }

        if ($this->enEnfriamiento($matricula, $regla, $version, $medicion, $dia)) {
            return;
        }

        $seco || $this->levantar($matricula, $regla, $version, $medicion, $umbral, $dia, $contadores);
        $seco && $contadores['alertas_creadas']++;
    }

    /**
     * ¿Se cerró una de ésta hace poco?
     *
     * El enfriamiento impide el REBOTE de una señal que oscila alrededor del
     * umbral: sin él, una asistencia en el 79-81 % abriría y cerraría una alerta
     * cada semana, y a la tercera nadie la mira.
     *
     * Se cuenta desde que se CERRÓ y no desde que nació: desde el nacimiento, una
     * alerta que estuvo abierta dos meses volvería a nacer al día siguiente de
     * cerrarse.
     *
     * **Una DESCARTADA también enfría.** Descartar es una afirmación humana —«no
     * amerita»— y volver a levantarla mañana la contradice; lo que la trae de
     * vuelta pasado el enfriamiento es que la situación siga ahí.
     */
    private function enEnfriamiento(
        MatriculaOferta $matricula,
        ReglaAlerta $regla,
        ReglaAlertaVersion $version,
        Medicion $medicion,
        CarbonImmutable $dia,
    ): bool {
        if ($version->cooldown_dias <= 0) {
            return false;
        }

        return Alerta::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('regla_id', $regla->id)
            ->where('asignatura_grupo_id', $medicion->asignaturaGrupoId)
            ->where(fn ($q) => $q
                ->where('cerrada_en', '>=', $dia->subDays($version->cooldown_dias))
                ->orWhere('revisada_en', '>=', $dia->subDays($version->cooldown_dias)))
            ->exists();
    }

    /** @param array<string, int> $contadores */
    private function levantar(
        MatriculaOferta $matricula,
        ReglaAlerta $regla,
        ReglaAlertaVersion $version,
        Medicion $medicion,
        ?float $umbral,
        CarbonImmutable $dia,
        array &$contadores,
    ): void {
        [$desde, $hasta] = $this->ventana($version, $dia);

        try {
            Alerta::create([
                'matricula_oferta_id' => $matricula->id,
                'regla_id' => $regla->id,
                'regla_version_id' => $version->id,
                // La categoría se COPIA: de ella depende quién ve el detalle, y
                // si la regla cambiara de categoría las alertas ya levantadas
                // cambiarían de visibilidad de golpe.
                'categoria_id' => $regla->categoria_id,
                'asignatura_grupo_id' => $medicion->asignaturaGrupoId,
                'ciclo_id' => $regla->ciclo_id,
                'severidad' => $version->severidad,
                'estado_senal' => Alerta::ACTIVA,
                'estado_triage' => Alerta::NUEVA,
                'valor_observado' => $medicion->valor,
                'umbral' => $umbral,
                'cobertura' => $medicion->cobertura,
                'ventana_desde' => $desde,
                'ventana_hasta' => $hasta,
                'evidencia' => $medicion->evidencia + [
                    'regla' => $regla->nombre,
                    'version' => $version->version,
                    'condicion' => $version->comoSeLee(),
                    'umbral_aplicado' => $umbral,
                    'valor_observado' => $medicion->valor,
                ],
                'primera_vez_en' => now(),
                'ultima_evaluacion_en' => now(),
            ]);

            $contadores['alertas_creadas']++;
        } catch (QueryException $e) {
            /*
             * El único de `clave_dedup` es lo que de verdad decide: dos corridas
             * simultáneas pasan el `SELECT` previo las dos. Que reviente aquí
             * significa que la otra ya la levantó, y eso es exactamente lo que
             * se quería.
             */
            if (! str_contains($e->getMessage(), '1062')) {
                throw $e;
            }

            $contadores['alertas_actualizadas']++;
        }
    }

    /**
     * @param  array<string, mixed>  $evidencia
     */
    private function cerrarAbiertas(
        MatriculaOferta $matricula,
        ReglaAlerta $regla,
        string $estado,
        array $evidencia,
    ): int {
        return Alerta::query()
            ->abiertas()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('regla_id', $regla->id)
            ->update([
                'estado_senal' => $estado,
                'cerrada_en' => now(),
                'evidencia_cierre' => json_encode(
                    $evidencia + ['cerrada_por' => 'motor'],
                    JSON_UNESCAPED_UNICODE,
                ),
            ]);
    }

    /** @return array{0: string|null, 1: string|null} */
    private function ventana(ReglaAlertaVersion $version, CarbonImmutable $dia): array
    {
        if ($version->ventana_tipo !== 'ultimos_dias' || $version->ventana_valor === null) {
            return [null, null];
        }

        return [$dia->subDays($version->ventana_valor)->toDateString(), $dia->toDateString()];
    }
}
