<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Un periodo cobrable ya resuelto: qué se etiqueta, cuándo se genera y cuándo
 * vence. Es lo que `PeriodosCobro` entrega y lo que `GeneradorAdeudos`
 * convierte en filas.
 *
 * `etiqueta` no es decorativa: es la mitad de la llave de idempotencia
 * (matrícula + regla + periodo), así que tiene que ser estable entre corridas.
 * Por eso los nombres de mes van en un arreglo propio y no salen del locale,
 * que puede cambiar con la configuración del servidor y convertiría "Marzo
 * 2026" en "March 2026" — dos etiquetas distintas para el mismo mes, o sea un
 * cobro duplicado.
 */
final readonly class PeriodoCobro
{
    public function __construct(
        public string $etiqueta,
        public CarbonImmutable $generacion,
        public CarbonImmutable $vencimiento,
        public CarbonImmutable $inicio,
        public CarbonImmutable $fin,
        public float $monto,
    ) {}

    /** El periodo dentro del cual cae una fecha (para prorratear el ingreso). */
    public function contiene(CarbonImmutable $fecha): bool
    {
        return $fecha->betweenIncluded($this->inicio, $this->fin);
    }

    /**
     * Proporción del periodo que queda a partir de una fecha, en [0, 1].
     *
     * Quien entra el día 20 de un mes de 30 no debería pagar el mes completo.
     * Se cuenta el día de ingreso como día cubierto: se entra por la mañana y
     * se recibe clase ese mismo día.
     */
    public function proporcionDesde(CarbonImmutable $fecha): float
    {
        if (! $this->contiene($fecha)) {
            return 1.0;
        }

        // Días ENTEROS, con las tres fechas a medianoche. Carbon 3 devuelve
        // `diffInDays` como flotante y `endOfMonth()` cae a las 23:59:59, así
        // que sin normalizar un marzo mide 31.99 días y el prorrateo de quien
        // entra el día 16 daba 17/32 en vez de 16/31: unos pesos de más, todos
        // los meses, en todas las altas a media periodicidad.
        $inicio = $this->inicio->startOfDay();
        $fin = $this->fin->startOfDay();
        $desde = $fecha->startOfDay();

        $dias = (int) $inicio->diffInDays($fin) + 1;

        if ($dias <= 0) {
            return 1.0;
        }

        return ((int) $desde->diffInDays($fin) + 1) / $dias;
    }

    public function conMonto(float $monto): self
    {
        return new self(
            $this->etiqueta,
            $this->generacion,
            $this->vencimiento,
            $this->inicio,
            $this->fin,
            round($monto, 2),
        );
    }
}
