<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Support\CacheExterno;
use Illuminate\Support\Facades\Http;

/**
 * Los dos números con los que se hacen cuentas en una escuela mexicana.
 *
 * ── La UMA ─────────────────────────────────────────────────────────────────
 * Es la unidad en la que están escritas las becas, los recargos, las multas y
 * media legislación. La publica el INEGI cada enero y entra en vigor el 1 de
 * febrero, así que cambia una vez al año y hay que capturarla una vez al año.
 *
 * NO se adivina: si el valor del año en curso no está cargado, se dice —«falta
 * capturar la UMA de 2027»— en vez de mostrar el del año pasado como si fuera
 * el vigente. Un número viejo presentado como actual es peor que ninguno:
 * alguien calcula una beca con él.
 *
 * ── El tipo de cambio ──────────────────────────────────────────────────────
 * El que vale para efectos fiscales en México es el FIX que publica Banxico en
 * el DOF, y su API pide un token gratuito. Si la escuela lo configuró, se usa
 * ése y se marca como oficial. Si no, se muestra la referencia del Banco
 * Central Europeo —que sirve para tener una idea, no para timbrar— y se dice
 * que es referencia.
 *
 * Confundir los dos sería lo peor que podría hacer esta tarjeta: alguien
 * facturaría con un tipo de cambio que el SAT no reconoce.
 */
class IndicadoresFinancieros
{
    private const SEGUNDOS_ESPERA = 5;

    /**
     * Valor DIARIO de la UMA por año, en pesos.
     *
     * Se guardan aquí los publicados por el INEGI. Es una lista corta que
     * crece un renglón al año; una tabla en la base para doce números que
     * nadie edita sería más ceremonia que dato.
     *
     * @var array<int, float>
     */
    private const UMA_DIARIA = [
        2021 => 89.62,
        2022 => 96.22,
        2023 => 103.74,
        2024 => 108.57,
        2025 => 113.14,
    ];

    /**
     * @return array<string, mixed>
     */
    public function todos(): array
    {
        return [
            'uma' => $this->uma(),
            'cambio' => $this->tipoDeCambio(),
        ];
    }

    /**
     * La UMA vigente, o el aviso de que falta capturarla.
     *
     * @return array<string, mixed>
     */
    public function uma(): array
    {
        $anio = (int) date('Y');

        /*
         * Antes del 1 de febrero sigue vigente la del año anterior: el INEGI la
         * publica en enero y entra en vigor el primero de febrero. Cobrar en
         * enero con la UMA nueva sería cobrar de más.
         */
        $vigente = (int) date('n') === 1 ? $anio - 1 : $anio;

        $valor = self::UMA_DIARIA[$vigente] ?? null;

        if ($valor === null) {
            return [
                'disponible' => false,
                'anio' => $vigente,
                'aviso' => "Falta capturar la UMA de {$vigente}.",
            ];
        }

        return [
            'disponible' => true,
            'anio' => $vigente,
            'diaria' => $valor,
            // Los tres valores que se usan: casi toda referencia legal habla de
            // UMA mensuales o anuales, no diarias.
            'mensual' => round($valor * 30.4, 2),
            'anual' => round($valor * 30.4 * 12, 2),
        ];
    }

    /**
     * El dólar: el FIX de Banxico si hay token, o la referencia del BCE.
     *
     * @return array<string, mixed>|null
     */
    public function tipoDeCambio(): ?array
    {
        $token = config('services.banxico.token');

        $oficial = filled($token) ? $this->deBanxico((string) $token) : null;

        return $oficial ?? $this->deReferencia();
    }

    /**
     * El FIX, que es el que vale para el SAT.
     *
     * @return array<string, mixed>|null
     */
    private function deBanxico(string $token): ?array
    {
        return $this->recordar('cambio:banxico', 60, function () use ($token) {
            try {
                // SF43718 es la serie del tipo de cambio FIX.
                $r = Http::timeout(self::SEGUNDOS_ESPERA)
                    ->withHeaders(['Bmx-Token' => $token])
                    ->get('https://www.banxico.org.mx/SieAPIRest/service/v1/series/SF43718/datos/oportuno');

                $dato = $r->json('bmx.series.0.datos.0');

                if ($dato === null) {
                    return null;
                }

                return [
                    'valor' => (float) str_replace(',', '', (string) $dato['dato']),
                    'fecha' => $this->aIso((string) $dato['fecha']),
                    'fuente' => 'Banxico (FIX)',
                    'oficial' => true,
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /**
     * La referencia del Banco Central Europeo: sirve para orientarse.
     *
     * @return array<string, mixed>|null
     */
    private function deReferencia(): ?array
    {
        return $this->recordar('cambio:bce', 180, function () {
            try {
                $r = Http::timeout(self::SEGUNDOS_ESPERA)
                    ->get('https://api.frankfurter.dev/v1/latest', ['base' => 'USD', 'symbols' => 'MXN']);

                $valor = $r->json('rates.MXN');

                if ($valor === null) {
                    return null;
                }

                return [
                    'valor' => (float) $valor,
                    'fecha' => (string) $r->json('date'),
                    'fuente' => 'Referencia BCE',
                    // Se dice que NO es el oficial: con éste no se timbra.
                    'oficial' => false,
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /**
     * Atajo al almacén de servicios externos.
     *
     * El almacén y la regla de que el fallo no se guarda están en
     * {@see CacheExterno}; aquí sólo se le pone nombre corto porque se usa en
     * cada consulta de este archivo.
     *
     * @return array<string, mixed>|null
     */
    private function recordar(string $llave, int $minutos, callable $traer): ?array
    {
        return CacheExterno::recordar($llave, $minutos, $traer);
    }

    /** Banxico devuelve `dd/mm/aaaa`. */
    private function aIso(string $fecha): string
    {
        [$d, $m, $a] = array_pad(explode('/', $fecha), 3, '');

        return "{$a}-{$m}-{$d}";
    }
}
