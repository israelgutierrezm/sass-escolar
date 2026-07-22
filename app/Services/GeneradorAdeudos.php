<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ReglaGeneracion;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * El motor de cobro: convierte las reglas configuradas en adeudos concretos.
 *
 * Recorre las `reglas_generacion` del plan de cobro que le toca a la matrícula
 * (`ResolutorPlanCobro`) y crea un adeudo por cada periodo que ya debió
 * emitirse, aplicando las becas vigentes.
 *
 * **Idempotente.** Correrlo dos veces no duplica nada: la terna (matrícula,
 * regla, periodo) tiene índice único en la base, y la comprobación previa
 * consulta con `withTrashed()` porque el soft delete no libera un índice
 * único. Un `QueryException` de duplicado se traga a propósito —significa que
 * otra corrida ganó la carrera, que es exactamente lo que el índice existe para
 * resolver— y cualquier otro se deja subir.
 *
 * No decide cuándo correr. Lo llama el administrador desde el estado de cuenta
 * y, cuando exista el scheduler, un job diario.
 */
class GeneradorAdeudos
{
    public function __construct(
        private readonly ResolutorPlanCobro $resolutor,
        private readonly PeriodosCobro $periodos,
        private readonly AplicadorRecargosDescuentos $aplicador,
    ) {}

    /**
     * @return array{generados: int, omitidos: int, motivos: array<int, string>}
     */
    public function generarPara(MatriculaOferta $matricula, ?CarbonImmutable $hasta = null): array
    {
        $hasta ??= CarbonImmutable::today();
        $resultado = ['generados' => 0, 'omitidos' => 0, 'motivos' => []];

        // Una matrícula dada de baja deja de devengar. Seguir generándole
        // colegiaturas infla la cartera con dinero que nadie va a cobrar y que
        // después hay que cancelar a mano, adeudo por adeudo.
        if ($matricula->estatus !== 'activo') {
            $resultado['motivos'][] = 'La matrícula no está activa; no se generan cargos.';

            return $resultado;
        }

        $plan = $this->resolutor->para($matricula, $hasta);

        if ($plan === null) {
            $resultado['motivos'][] = 'No hay plan de cobro vigente que aplique a esta matrícula.';

            return $resultado;
        }

        // Nunca antes de que el alumno ingresara ni antes de que el plan
        // entrara en vigor: un plan nuevo no cobra retroactivamente los meses
        // en los que no existía.
        $desde = CarbonImmutable::parse($matricula->fecha_ingreso)
            ->max(CarbonImmutable::parse($plan->vigente_desde));

        if ($plan->vigente_hasta !== null) {
            $hasta = $hasta->min(CarbonImmutable::parse($plan->vigente_hasta));
        }

        $ingreso = CarbonImmutable::parse($matricula->fecha_ingreso);

        foreach ($plan->reglas()->with('concepto')->get() as $regla) {
            if (! $this->cumplePrerequisito($regla, $matricula)) {
                $resultado['omitidos']++;
                $resultado['motivos'][] = sprintf(
                    '%s: falta pagar %s.',
                    $regla->concepto?->nombre ?? 'Regla '.$regla->id,
                    $regla->conceptoPrerequisito?->nombre ?? 'el concepto previo',
                );

                continue;
            }

            foreach ($this->periodosDe($regla, $matricula, $desde, $hasta) as $periodo) {
                if ($periodo->generacion->gt($hasta)) {
                    continue; // todavía no toca emitirlo
                }

                $creado = $this->crear($regla, $matricula, $periodo, $ingreso);

                $creado ? $resultado['generados']++ : $resultado['omitidos']++;
            }
        }

        return $resultado;
    }

    /**
     * Genera para todas las matrículas activas. Es lo que llamará el job
     * diario. Se procesa con `cursor()` para no cargar la escuela entera en
     * memoria.
     *
     * @return array{matriculas: int, generados: int}
     */
    public function generarParaTodas(?CarbonImmutable $hasta = null): array
    {
        $totales = ['matriculas' => 0, 'generados' => 0];

        $consulta = MatriculaOferta::query()->where('estatus', 'activo')->with('oferta');

        foreach ($consulta->cursor() as $matricula) {
            $resultado = $this->generarPara($matricula, $hasta);

            $totales['matriculas']++;
            $totales['generados'] += $resultado['generados'];
        }

        return $totales;
    }

    /**
     * Los periodos de una regla. Las periodicidades de calendario las resuelve
     * `PeriodosCobro`; las que dependen de la operación escolar —por ciclo, por
     * materia— se arman aquí, que es donde se sabe de ciclos e inscripciones.
     *
     * @return array<int, PeriodoCobro>
     */
    private function periodosDe(
        ReglaGeneracion $regla,
        MatriculaOferta $matricula,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
    ): array {
        if ($regla->periodicidad === ReglaGeneracion::PERIODICIDAD_POR_CICLO) {
            return $this->periodosPorCiclo($regla, $desde, $hasta);
        }

        if ($regla->periodicidad === ReglaGeneracion::PERIODICIDAD_POR_MATERIA) {
            return $this->periodosPorMateria($regla, $matricula, $desde, $hasta);
        }

        return $this->periodos->para($regla, $desde, $hasta);
    }

    /**
     * Un cargo por ciclo escolar traslapado con el rango: la reinscripción
     * típica. La etiqueta es la clave del ciclo, que es única en toda la
     * escuela y por tanto sirve de llave de idempotencia.
     *
     * @return array<int, PeriodoCobro>
     */
    private function periodosPorCiclo(ReglaGeneracion $regla, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $ciclos = Ciclo::query()
            ->whereDate('fecha_fin', '>=', $desde->toDateString())
            ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->orderBy('fecha_inicio')
            ->get();

        return $ciclos->map(function (Ciclo $ciclo) use ($regla) {
            $inicio = CarbonImmutable::parse($ciclo->fecha_inicio);
            $fin = CarbonImmutable::parse($ciclo->fecha_fin);

            return new PeriodoCobro(
                $ciclo->clave,
                $inicio,
                $regla->dia_limite !== null ? $inicio->addDays((int) $regla->dia_limite) : $inicio,
                $inicio,
                $fin,
                (float) $regla->monto_base,
            );
        })->all();
    }

    /**
     * Un cargo por materia inscrita: el esquema de quien paga por crédito o por
     * asignatura en vez de una colegiatura fija.
     *
     * Solo cuentan las inscripciones vivas: dar de baja una materia antes de
     * que se genere el cargo debe evitarlo, no obligar a cancelarlo después.
     *
     * @return array<int, PeriodoCobro>
     */
    private function periodosPorMateria(
        ReglaGeneracion $regla,
        MatriculaOferta $matricula,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
    ): array {
        $inscripciones = Inscripcion::query()
            ->with(['asignaturaGrupo.planMateria.asignatura', 'ciclo', 'situacion'])
            ->where('matricula_oferta_id', $matricula->id)
            ->whereHas('ciclo', fn ($q) => $q
                ->whereDate('fecha_fin', '>=', $desde->toDateString())
                ->whereDate('fecha_inicio', '<=', $hasta->toDateString()))
            ->get()
            ->reject(fn (Inscripcion $i) => $i->estaDeBaja());

        return $inscripciones->map(function (Inscripcion $inscripcion) use ($regla) {
            $ciclo = $inscripcion->ciclo;
            $inicio = CarbonImmutable::parse($ciclo->fecha_inicio);
            $asignatura = $inscripcion->asignaturaGrupo?->planMateria?->asignatura;

            return new PeriodoCobro(
                trim(($ciclo->clave ?? '').' · '.($asignatura->clave ?? 'materia '.$inscripcion->id)),
                $inicio,
                $regla->dia_limite !== null ? $inicio->addDays((int) $regla->dia_limite) : $inicio,
                $inicio,
                CarbonImmutable::parse($ciclo->fecha_fin),
                (float) $regla->monto_base,
            );
        })->all();
    }

    /**
     * Crea el adeudo si no existía. Devuelve false cuando ya estaba —que es el
     * caso normal en la segunda corrida del día, no un error—.
     */
    private function crear(
        ReglaGeneracion $regla,
        MatriculaOferta $matricula,
        PeriodoCobro $periodo,
        CarbonImmutable $ingreso,
    ): bool {
        $yaExiste = Adeudo::withTrashed()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('regla_id', $regla->id)
            ->where('periodo_etiqueta', $periodo->etiqueta)
            ->exists();

        if ($yaExiste) {
            return false;
        }

        $monto = $periodo->monto;

        // Quien ingresa a media periodicidad paga la parte que le corresponde,
        // si la regla lo permite. Solo alcanza al periodo de su ingreso.
        if ($regla->prorratea && $periodo->contiene($ingreso)) {
            $monto = round($monto * $periodo->proporcionDesde($ingreso), 2);
        }

        $descuento = $this->aplicador->descuentoPara($matricula->id, $monto, $periodo->generacion);

        try {
            Adeudo::create([
                'matricula_oferta_id' => $matricula->id,
                'concepto_id' => $regla->concepto_id,
                'regla_id' => $regla->id,
                'ciclo_id' => $this->cicloDe($periodo),
                'periodo_etiqueta' => $periodo->etiqueta,
                'monto' => $monto,
                'monto_recargos' => 0,
                'monto_descuentos' => $descuento,
                'monto_total' => round($monto - $descuento, 2),
                'fecha_generacion' => $periodo->generacion->toDateString(),
                'fecha_vencimiento' => $periodo->vencimiento->toDateString(),
                'estatus' => Adeudo::ESTATUS_PENDIENTE,
            ]);
        } catch (QueryException $e) {
            // 23000 = violación de integridad. Aquí solo puede ser el único de
            // generación: otra corrida lo creó entre el SELECT y el INSERT.
            if ($e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /** El ciclo al que pertenece el periodo, si la etiqueta salió de uno. */
    private function cicloDe(PeriodoCobro $periodo): ?int
    {
        return Ciclo::query()
            ->whereDate('fecha_inicio', '<=', $periodo->generacion->toDateString())
            ->whereDate('fecha_fin', '>=', $periodo->generacion->toDateString())
            ->value('id');
    }

    /**
     * Una regla con concepto prerrequisito no genera hasta que ese concepto
     * esté pagado.
     *
     * Es lo que impide cobrarle las colegiaturas del semestre a quien nunca
     * completó su inscripción: sería cartera que la escuela cree tener y no
     * tiene, y que además le llega al alumno como un estado de cuenta que no
     * reconoce.
     */
    private function cumplePrerequisito(ReglaGeneracion $regla, MatriculaOferta $matricula): bool
    {
        if ($regla->concepto_prerequisito_id === null) {
            return true;
        }

        return Adeudo::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('concepto_id', $regla->concepto_prerequisito_id)
            ->whereIn('estatus', [Adeudo::ESTATUS_PAGADO, Adeudo::ESTATUS_CONDONADO])
            ->exists();
    }
}
