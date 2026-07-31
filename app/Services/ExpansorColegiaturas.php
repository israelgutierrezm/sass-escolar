<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\PlanCobro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Convierte un RANGO de colegiaturas en las líneas fechadas del plan.
 *
 * Una escuela cobra mensual, otra semanal y otra cada quince días. En vez de
 * modelar cada cadencia como un caso del código, la UI captura "de marzo a
 * julio, cada mes, $2,500, vence el día 5" y aquí se expande en N líneas
 * concretas que comparten `grupo_colegiatura`. A partir de ahí el resto del
 * sistema solo ve líneas con fecha, sin saber de periodicidades.
 *
 * Que compartan grupo es lo que permite después editar o borrar el bloque
 * completo sin tener que adivinar cuáles iban juntas.
 */
class ExpansorColegiaturas
{
    public const CADA_SEMANA = 'semanal';

    public const CADA_QUINCENA = 'quincenal';

    public const CADA_MES = 'mensual';

    /** @var array<string, string> */
    public const CADENCIAS = [
        self::CADA_SEMANA => 'Cada semana',
        self::CADA_QUINCENA => 'Cada quincena',
        self::CADA_MES => 'Cada mes',
    ];

    /**
     * Genera (sin guardar) las líneas de un rango.
     *
     * @param  array{concepto_id:int, descripcion:?string, monto:float|string,
     *               desde:string, cantidad:int, cadencia:string,
     *               dia_limite:?int, aplica_recargos:bool}  $datos
     * @return array<int, array<string, mixed>>
     */
    public function expandir(PlanCobro $plan, array $datos): array
    {
        $desde = CarbonImmutable::parse($datos['desde']);
        $cantidad = max(1, min(60, (int) $datos['cantidad']));
        $cadencia = $datos['cadencia'];
        $grupo = (string) Str::uuid();

        // Si el plan no permite recargos, ninguna línea puede llevarlos por más
        // que venga marcado desde el formulario.
        $conRecargos = $plan->aplica_recargos && (bool) ($datos['aplica_recargos'] ?? false);

        $lineas = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $fecha = $this->avanzar($desde, $cadencia, $i);
            $limite = $this->fechaLimite($plan, $fecha, $datos['dia_limite'] ?? null, $cadencia);

            $lineas[] = [
                'plan_cobro_id' => $plan->id,
                'concepto_id' => $datos['concepto_id'],
                'tipo_pago' => ConceptoPlan::TIPO_COLEGIATURA,
                'descripcion' => $datos['descripcion'] ?: null,
                'monto' => $datos['monto'],
                'mes_referencia' => $fecha->month,
                'anio_referencia' => $fecha->year,
                'fecha_limite' => $plan->tiene_fecha_limite ? $limite->toDateString() : null,
                'aplica_recargos' => $conRecargos,
                'obligatorio' => true,
                'grupo_colegiatura' => $grupo,
                'orden' => $i,
            ];
        }

        return $lineas;
    }

    /** Crea y guarda las líneas del rango. */
    public function crear(PlanCobro $plan, array $datos): int
    {
        $lineas = $this->expandir($plan, $datos);

        foreach ($lineas as $linea) {
            ConceptoPlan::create($linea);
        }

        return count($lineas);
    }

    private function avanzar(CarbonImmutable $base, string $cadencia, int $n): CarbonImmutable
    {
        return match ($cadencia) {
            self::CADA_SEMANA => $base->addWeeks($n),
            self::CADA_QUINCENA => $base->addDays(15 * $n),
            default => $base->addMonths($n),
        };
    }

    /**
     * Fecha límite de una línea. En cadencia mensual se respeta el día del mes
     * pedido (acotado al último día del mes, para que "día 31" no se caiga en
     * febrero); en las demás, el vencimiento es la fecha del periodo.
     *
     * Si el plan marca `dia_siguiente`, la mora arranca un día después, así que
     * el límite efectivo se corre un día.
     */
    private function fechaLimite(PlanCobro $plan, CarbonImmutable $fecha, ?int $diaLimite, string $cadencia): CarbonImmutable
    {
        $limite = $fecha;

        if ($cadencia === self::CADA_MES && $diaLimite !== null) {
            $dia = min($diaLimite, $fecha->daysInMonth);
            $limite = $fecha->setDay($dia);
        }

        if ($plan->fecha_limite_modo === PlanCobro::LIMITE_DIA_SIGUIENTE) {
            $limite = $limite->addDay();
        }

        return $limite;
    }
}
