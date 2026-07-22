<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\ReglaGeneracion;
use Carbon\CarbonImmutable;

/**
 * Traduce una `regla_generacion` a los periodos concretos que le tocan a un
 * rango de fechas. Es el calendario del motor de cobro, aislado a propósito:
 * aquí no se consulta la base ni se sabe de alumnos, así que la aritmética de
 * fechas —que es donde se esconden los errores de un día— se puede probar
 * sola.
 *
 * Cubre las periodicidades de calendario (único, semanal, quincenal, mensual).
 * `por_ciclo` y `por_materia` NO salen de aquí: dependen de los ciclos abiertos
 * y de las inscripciones del alumno, y esos los resuelve `GeneradorAdeudos`,
 * que sí tiene ese contexto.
 *
 * Convención de los dos días configurables, uniforme en todas las
 * periodicidades para no tener que recordar excepciones:
 *  - `dia_generacion`: el día del periodo en que nace el cargo (día del mes,
 *    1–31; día de la semana, 1 = lunes, en la semanal). Sin él, el primer día.
 *  - `dia_limite`: el día en que vence. Si cae antes que el de generación se
 *    entiende que vence en el periodo SIGUIENTE —"se genera el 25 y se paga el
 *    5"—, que es como cobra la mayoría de las escuelas. Sin él, el último día
 *    del periodo.
 */
class PeriodosCobro
{
    /** Nombres fijos, no del locale: la etiqueta es llave de idempotencia. */
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /**
     * @return array<int, PeriodoCobro> en orden cronológico
     */
    public function para(ReglaGeneracion $regla, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        if ($hasta->lt($desde)) {
            return [];
        }

        $monto = (float) $regla->monto_base;

        return match ($regla->periodicidad) {
            ReglaGeneracion::PERIODICIDAD_UNICO => $this->unico($regla, $desde, $monto),
            ReglaGeneracion::PERIODICIDAD_MENSUAL => $this->mensuales($regla, $desde, $hasta, $monto),
            ReglaGeneracion::PERIODICIDAD_QUINCENAL => $this->quincenales($regla, $desde, $hasta, $monto),
            ReglaGeneracion::PERIODICIDAD_SEMANAL => $this->semanales($regla, $desde, $hasta, $monto),
            default => [],
        };
    }

    /**
     * Un cargo que no se repite: la ficha, la inscripción, la titulación.
     *
     * Con `num_parcialidades` se parte en N mensualidades. El reparto usa
     * centavos enteros y le entrega el sobrante a la PRIMERA parcialidad, no a
     * la última: el redondeo tiene que caer donde el alumno todavía puede
     * reclamarlo, no en el pago final donde ya nadie revisa.
     *
     * @return array<int, PeriodoCobro>
     */
    private function unico(ReglaGeneracion $regla, CarbonImmutable $desde, float $monto): array
    {
        $partes = max(1, (int) ($regla->num_parcialidades ?? 1));

        if ($partes === 1) {
            // Un cargo único no tiene periodo de calendario del cual tomar una
            // fracción, así que su "inicio" es la fecha desde la que aplica.
            // Consecuencia deliberada: `prorratea` no le hace nada — no se
            // cobra media inscripción por entrar a mitad de mes.
            $inicio = $desde;
            $fin = $desde->endOfMonth();

            return [new PeriodoCobro(
                'Único',
                $this->diaDelMes($inicio, $regla->dia_generacion) ?? $inicio,
                $this->vencimientoMensual($inicio, $regla),
                $inicio,
                $fin,
                $monto,
            )];
        }

        $centavos = (int) round($monto * 100);
        $base = intdiv($centavos, $partes);
        $sobrante = $centavos - ($base * $partes);

        $periodos = [];

        for ($i = 0; $i < $partes; $i++) {
            $mes = $desde->addMonths($i);
            $inicio = $i === 0 ? $desde : $mes->startOfMonth();

            $periodos[] = new PeriodoCobro(
                sprintf('Parcialidad %d de %d', $i + 1, $partes),
                $this->diaDelMes($mes, $regla->dia_generacion) ?? $inicio,
                $this->vencimientoMensual($mes, $regla),
                $inicio,
                $mes->endOfMonth(),
                ($base + ($i === 0 ? $sobrante : 0)) / 100,
            );
        }

        return $periodos;
    }

    /**
     * @return array<int, PeriodoCobro>
     */
    private function mensuales(ReglaGeneracion $regla, CarbonImmutable $desde, CarbonImmutable $hasta, float $monto): array
    {
        $periodos = [];
        $cursor = $desde->startOfMonth();

        while ($cursor->lte($hasta)) {
            // El periodo son los límites REALES del mes, no el rango recortado
            // al ingreso del alumno. Recortarlos hacía que "del 16 al 31" se
            // creyera un mes completo y el prorrateo devolviera siempre 1: el
            // que entraba a media quincena pagaba el mes entero.
            $periodos[] = new PeriodoCobro(
                ucfirst(self::MESES[$cursor->month]).' '.$cursor->year,
                $this->diaDelMes($cursor, $regla->dia_generacion) ?? $cursor,
                $this->vencimientoMensual($cursor, $regla),
                $cursor->startOfMonth(),
                $cursor->endOfMonth(),
                $monto,
            );

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $periodos;
    }

    /**
     * Dos por mes, partidas el 16. La segunda quincena tiene 13, 14, 15 o 16
     * días según el mes, y eso importa al prorratear.
     *
     * @return array<int, PeriodoCobro>
     */
    private function quincenales(ReglaGeneracion $regla, CarbonImmutable $desde, CarbonImmutable $hasta, float $monto): array
    {
        $periodos = [];
        $cursor = $desde->startOfMonth();

        while ($cursor->lte($hasta)) {
            foreach ([1, 2] as $mitad) {
                $inicio = $mitad === 1 ? $cursor->startOfMonth() : $cursor->startOfMonth()->addDays(15);
                $fin = $mitad === 1 ? $cursor->startOfMonth()->addDays(14) : $cursor->endOfMonth();

                if ($fin->lt($desde) || $inicio->gt($hasta)) {
                    continue;
                }

                $periodos[] = new PeriodoCobro(
                    sprintf('%da quincena de %s %d', $mitad, self::MESES[$cursor->month], $cursor->year),
                    $this->diaDelMes($cursor, $regla->dia_generacion, $inicio, $fin) ?? $inicio,
                    $this->vencimientoEnRango($inicio, $fin, $regla->dia_limite),
                    $inicio,
                    $fin,
                    $monto,
                );
            }

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $periodos;
    }

    /**
     * Semanas ISO (lunes a domingo). La etiqueta lleva el año ISO, no el
     * natural: el 1 de enero puede pertenecer a la semana 52 del año anterior y
     * llamarla "Semana 52 de 2027" en vez de "de 2026" duplicaría el cobro.
     *
     * @return array<int, PeriodoCobro>
     */
    private function semanales(ReglaGeneracion $regla, CarbonImmutable $desde, CarbonImmutable $hasta, float $monto): array
    {
        $periodos = [];
        // MONDAY explícito, no el `startOfWeek()` pelado: ese respeta la
        // configuración de la aplicación, que en esta instalación empieza en
        // domingo. Con el lunes implícito las semanas quedaban corridas un día
        // respecto de la etiqueta ISO —dos cursores distintos podían caer en la
        // misma semana ISO y producir la MISMA etiqueta, o sea un cobro
        // duplicado— y además el mismo rango daba 4 o 5 periodos según la
        // config. La etiqueta es llave de idempotencia: sus límites no pueden
        // depender de un ajuste que alguien cambie el día de mañana.
        $cursor = $desde->startOfWeek(CarbonImmutable::MONDAY);

        while ($cursor->lte($hasta)) {
            $fin = $cursor->endOfWeek(CarbonImmutable::SUNDAY);
            $generacion = $regla->dia_generacion !== null
                ? $cursor->addDays(max(0, min(6, (int) $regla->dia_generacion - 1)))
                : $cursor;

            $vencimiento = $regla->dia_limite !== null
                ? $cursor->addDays(max(0, min(6, (int) $regla->dia_limite - 1)))
                : $fin;

            // "Se genera el viernes y se paga el lunes": el límite anterior al
            // día de generación cae en la semana siguiente.
            if ($vencimiento->lt($generacion)) {
                $vencimiento = $vencimiento->addWeek();
            }

            $periodos[] = new PeriodoCobro(
                sprintf('Semana %02d de %d', (int) $cursor->isoWeek(), (int) $cursor->isoWeekYear()),
                $generacion,
                $vencimiento,
                $cursor,
                $fin,
                $monto,
            );

            $cursor = $cursor->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        }

        return $periodos;
    }

    /**
     * El día `n` del mes de `$referencia`, recortado al último día real: un
     * `dia_generacion` de 31 en febrero es el 28 (o el 29), no un desbordamiento
     * al 3 de marzo.
     */
    private function diaDelMes(
        CarbonImmutable $referencia,
        ?int $dia,
        ?CarbonImmutable $min = null,
        ?CarbonImmutable $max = null,
    ): ?CarbonImmutable {
        if ($dia === null) {
            return null;
        }

        $fecha = $referencia->startOfMonth()->addDays(
            max(0, min((int) $dia, $referencia->daysInMonth) - 1)
        );

        if ($min !== null && $fecha->lt($min)) {
            return $min;
        }

        if ($max !== null && $fecha->gt($max)) {
            return $max;
        }

        return $fecha;
    }

    /** Vence el día `dia_limite`; si cae antes de generarse, el mes siguiente. */
    private function vencimientoMensual(CarbonImmutable $mes, ReglaGeneracion $regla): CarbonImmutable
    {
        if ($regla->dia_limite === null) {
            return $mes->endOfMonth();
        }

        $vencimiento = $this->diaDelMes($mes, $regla->dia_limite);
        $generacion = $this->diaDelMes($mes, $regla->dia_generacion) ?? $mes->startOfMonth();

        return $vencimiento->lt($generacion)
            ? $this->diaDelMes($mes->addMonth()->startOfMonth(), $regla->dia_limite)
            : $vencimiento;
    }

    private function vencimientoEnRango(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $dia): CarbonImmutable
    {
        if ($dia === null) {
            return $fin;
        }

        $vencimiento = $this->diaDelMes($inicio, $dia);

        return $vencimiento->lt($inicio) ? $fin : $vencimiento->min($fin);
    }
}
