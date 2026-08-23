<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Nomina\ConceptoNomina;
use App\Models\Nomina\EsquemaPercepcion;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\PeriodoNomina;
use App\Models\Nomina\ReciboConcepto;
use App\Models\Nomina\ReciboNomina;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Calcula los recibos de un periodo.
 *
 * ── Lo que suma sale de las BANDERAS de la modalidad ──────────────────────
 * No de su clave. Una modalidad armada desde la pantalla —«base más horas»—
 * funciona sin tocar esta clase, que es lo que hace configurable al catálogo.
 * Cada bandera encendida produce UN renglón del recibo con su concepto.
 *
 * ── El recibo se materializa ──────────────────────────────────────────────
 * Los renglones guardan el importe calculado, no una referencia al sueldo. Ver
 * el modelo.
 *
 * ── Lo que no se puede calcular se ANOTA, no se supone ────────────────────
 * Sin esquema vigente, sin checadas cerradas, sin materias asignadas: el recibo
 * sale con la incidencia escrita en vez de con un cero silencioso. Un cero se
 * paga; una incidencia se corrige antes de pagar.
 *
 * ── El ISR no se calcula aquí ─────────────────────────────────────────────
 * Sale de la tarifa por rangos del artículo 96 y no de un factor plano, así que
 * su concepto no lleva fórmula y se captura a mano. Inventarlo daría un número
 * que alguien enteraría al SAT.
 */
class CalculadoraNomina
{
    /** Qué concepto usa cada componente del esquema. */
    private const CONCEPTO_DE = [
        'monto_base' => 'sueldo',
        'tarifa_hora' => 'horas_trabajadas',
        'tarifa_asignatura' => 'asignaturas_impartidas',
    ];

    public function __construct(private readonly ContadorHoras $horas) {}

    /**
     * Quiénes entran en el periodo.
     *
     * Los que están EN NÓMINA —contratados y con una situación que se paga— y,
     * si el periodo es de un campus, los adscritos a ese campus. Con el periodo
     * global entran todos, incluidos los que todavía no tienen adscripción:
     * dejarlos fuera haría que un alta reciente se quedara sin cobrar y nadie
     * sabría por qué.
     */
    public function elegibles(PeriodoNomina $periodo): Builder
    {
        return ExpedienteLaboral::query()
            ->enNomina()
            ->when($periodo->campus_id !== null, fn (Builder $q) => $q
                ->whereHas('adscripciones', fn (Builder $a) => $a
                    ->where('campus_id', $periodo->campus_id)
                    ->vigentes()));
    }

    /**
     * Rehace todos los recibos del periodo.
     *
     * @return array{recibos: int, manuales_borrados: int, con_incidencias: int}
     *
     * @throws RuntimeException si el periodo está cerrado
     */
    public function calcular(PeriodoNomina $periodo): array
    {
        if ($periodo->estaCerrado()) {
            throw new RuntimeException('Ese periodo está cerrado: para recalcularlo hay que reabrirlo.');
        }

        /*
         * Cuántos renglones capturados a mano se van a perder.
         *
         * Recalcular rehace el recibo desde cero, así que un descuento por
         * préstamo agregado a mano desaparece. Se cuenta ANTES para poder
         * decirlo: perderlo en silencio es pagarle de más a alguien.
         */
        $manuales = ReciboConcepto::query()
            ->where('manual', true)
            ->whereIn('recibo_nomina_id', $periodo->recibos()->select('id'))
            ->count();

        $conceptos = ConceptoNomina::query()->activos()->with('formula')->get()->keyBy('clave');

        return DB::transaction(function () use ($periodo, $manuales, $conceptos) {
            // Los renglones se van con el recibo por la foránea en cascada.
            $periodo->recibos()->forceDelete();

            $hechos = 0;
            $conIncidencias = 0;

            foreach ($this->elegibles($periodo)->cursor() as $expediente) {
                $recibo = $this->reciboDe($periodo, $expediente, $conceptos);
                $hechos++;

                if (filled($recibo->incidencias)) {
                    $conIncidencias++;
                }
            }

            $periodo->update(['estado' => PeriodoNomina::CALCULADO]);

            return [
                'recibos' => $hechos,
                'manuales_borrados' => $manuales,
                'con_incidencias' => $conIncidencias,
            ];
        });
    }

    /** Arma el recibo de una persona. */
    private function reciboDe(PeriodoNomina $periodo, ExpedienteLaboral $expediente, $conceptos): ReciboNomina
    {
        /*
         * El sueldo se resuelve al FIN del periodo, no a hoy.
         *
         * Un periodo de la quincena pasada tiene que pagarse con el sueldo que
         * regía entonces. Preguntar por «el abierto» le aplicaría a un recibo
         * viejo el aumento de la semana pasada.
         */
        $esquema = $expediente->esquemaEn($periodo->fecha_fin->toDateString());

        $recibo = ReciboNomina::create([
            'periodo_nomina_id' => $periodo->id,
            'expediente_laboral_id' => $expediente->id,
            'esquema_percepcion_id' => $esquema?->id,
        ]);

        $incidencias = [];

        if ($esquema === null) {
            // Sale el recibo en ceros CON el motivo escrito: un recibo que no
            // aparece se confunde con alguien a quien no le tocaba cobrar.
            $recibo->update(['incidencias' => 'No tiene sueldo fijado para este periodo.']);

            return $recibo->recalcularTotales();
        }

        $orden = 0;

        foreach ($esquema->modalidad?->componentes() ?? [] as $componente) {
            [$importe, $cantidad, $detalle, $aviso] = $this->renglonDe(
                $componente, $esquema, $expediente, $periodo
            );

            $aviso === null || $incidencias[] = $aviso;

            $concepto = $conceptos[self::CONCEPTO_DE[$componente]] ?? null;

            if ($concepto === null) {
                $incidencias[] = 'Falta el concepto «'.self::CONCEPTO_DE[$componente].'» en el catálogo.';

                continue;
            }

            $recibo->conceptos()->create([
                'concepto_nomina_id' => $concepto->id,
                'importe' => $importe,
                'cantidad' => $cantidad,
                'detalle' => $detalle,
                'manual' => false,
                'orden' => $orden += 10,
            ]);
        }

        $recibo->recalcularTotales();

        // Las deducciones con fórmula se aplican DESPUÉS: su base son las
        // percepciones, así que necesitan que ya estén todas puestas.
        $this->aplicarFormulas($recibo, $conceptos, $orden);

        if ($incidencias !== []) {
            $recibo->update(['incidencias' => implode(' ', $incidencias)]);
        }

        return $recibo->recalcularTotales();
    }

    /**
     * El importe de un componente del esquema.
     *
     * @return array{0: float, 1: float|null, 2: string|null, 3: string|null}
     */
    private function renglonDe(
        string $componente,
        EsquemaPercepcion $esquema,
        ExpedienteLaboral $expediente,
        PeriodoNomina $periodo,
    ): array {
        if ($componente === 'monto_base') {
            // El monto base es mensual y el periodo puede ser quincenal: se
            // prorratea por días para que dos quincenas den el mes.
            $dias = $periodo->fecha_inicio->diffInDays($periodo->fecha_fin) + 1;
            $proporcion = min(1.0, $dias / 30);

            return [
                round((float) $esquema->monto_base * $proporcion, 2),
                null,
                $dias.' de 30 días',
                null,
            ];
        }

        if ($componente === 'tarifa_hora') {
            $medido = $this->horas->contar(
                (int) $expediente->persona_id,
                $periodo->fecha_inicio->toDateString(),
                $periodo->fecha_fin->toDateString(),
            );

            $aviso = $medido['sin_cerrar'] === []
                ? null
                : count($medido['sin_cerrar']).' checada(s) sin cerrar, no se pagaron: '
                    .implode(', ', array_slice($medido['sin_cerrar'], 0, 5)).'.';

            // Cero horas con cero checadas también se avisa: un recibo en cero
            // sin explicación se confunde con un error del sistema.
            if ($medido['horas'] <= 0 && $aviso === null) {
                $aviso = 'No hay checadas en el periodo.';
            }

            return [
                round($medido['horas'] * (float) $esquema->tarifa_hora, 2),
                $medido['horas'],
                $medido['horas'].' h × $'.$esquema->tarifa_hora,
                $aviso,
            ];
        }

        $materias = $this->materiasEn($expediente, $periodo);

        return [
            round($materias * (float) $esquema->tarifa_asignatura, 2),
            (float) $materias,
            $materias.' materia(s) × $'.$esquema->tarifa_asignatura,
            $materias === 0 ? 'No tiene materias asignadas en el periodo.' : null,
        ];
    }

    /**
     * Cuántas materias impartió en el periodo.
     *
     * ── La tarifa es POR PERIODO, no por el ciclo completo ────────────────
     * Se cuentan las materias cuyo rango se encima con el periodo, y se pagan
     * cada vez. Es como funciona el pago por asignatura en México: se cobra
     * mes a mes mientras se imparte. Pagar el ciclo entero en el primer recibo
     * daría un pico imposible de conciliar y dejaría los meses siguientes en
     * cero sin explicación.
     */
    private function materiasEn(ExpedienteLaboral $expediente, PeriodoNomina $periodo): int
    {
        return AsignaturaGrupo::query()
            // Calificado: en un `belongsToMany` la consulta une el pivote, y ahí
            // `persona_id` existe en los dos lados. Sin calificar, MySQL lo
            // rechaza por ambiguo.
            ->whereHas('docentes', fn (Builder $q) => $q->where('docentes.persona_id', $expediente->persona_id))
            ->whereDate('fecha_inicio', '<=', $periodo->fecha_fin->toDateString())
            ->whereDate('fecha_fin', '>=', $periodo->fecha_inicio->toDateString())
            ->count();
    }

    /** Las deducciones y percepciones que salen de un porcentaje. */
    private function aplicarFormulas(ReciboNomina $recibo, $conceptos, int $orden): void
    {
        $gravables = $recibo->conceptos()->with('concepto')->get()
            ->filter(fn (ReciboConcepto $r) => (bool) $r->concepto?->suma() && (bool) $r->concepto?->es_gravable)
            ->sum(fn (ReciboConcepto $r) => (float) $r->importe);

        foreach ($conceptos as $concepto) {
            if ($concepto->formula === null || ! $concepto->formula->activo) {
                continue;
            }

            $base = $concepto->formula->base === $concepto->formula::BASE_GRAVABLE
                ? $gravables
                : (float) $recibo->total_percepciones;

            $importe = $concepto->formula->aplicar($base);

            // Un renglón en cero no se escribe: ensucia el recibo con líneas
            // que no dicen nada.
            if ($importe <= 0) {
                continue;
            }

            $recibo->conceptos()->create([
                'concepto_nomina_id' => $concepto->id,
                'importe' => $importe,
                'cantidad' => null,
                'detalle' => $concepto->formula->nombre,
                'manual' => false,
                'orden' => $orden += 10,
            ]);
        }
    }
}
