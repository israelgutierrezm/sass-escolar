<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Factura;
use App\Services\Cfdi\EstadoEnElPac;
use App\Services\Cfdi\Pac;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Le pregunta al PAC qué opina el SAT de cada comprobante, y anota la respuesta.
 *
 * ── Los dos desajustes que existe para cazar ───────────────────────────────
 * Un CFDI vive aquí y en el SAT, y se separan solos:
 *
 *  1. Alguien lo cancela desde el portal del PAC o del SAT. Aquí sigue vigente,
 *     y la escuela cuenta como ingreso un comprobante que ya no existe.
 *  2. Se pidió la cancelación desde aquí y el SAT la dejó esperando que el
 *     receptor la acepte. La base dice «cancelada» y el CFDI sigue vivo: la
 *     escuela cree que corrigió y no corrigió nada.
 *
 * Ninguno falla ni avisa. Los dos se descubren en la declaración, cuando ya no
 * hay margen.
 *
 * ── NUNCA escribe `estatus` ────────────────────────────────────────────────
 * Es la regla que sostiene todo lo demás. `estatus` es el estado de trabajo y
 * tiene consecuencias: `Factura::vivas()` decide qué pagos siguen amparados,
 * así que moverlo desde un comando de madrugada liberaría esos pagos y alguien
 * podría volver a facturar el mismo dinero sin haberlo pedido. Se guardan las
 * dos versiones, se reporta la diferencia, y resolverla es un acto deliberado.
 *
 * Es lo mismo que hace `acadion:auditar-datos`: informar por omisión.
 */
class ConciliadorCfdi
{
    public function __construct(private readonly Pac $pac) {}

    /**
     * Concilia las facturas fiscales de la escuela ya inicializada.
     *
     * @param  int  $dias  cuánto hacia atrás mirar
     * @return array{consultadas: int, discrepancias: array<int, array{id: int, uuid: string|null, motivo: string}>, sinRespuesta: int, enEspera: int, omitido: string|null}
     */
    public function conciliar(int $dias = 90, ?int $limite = null): array
    {
        if (! $this->pac->puedeConciliar()) {
            // Se dice UNA vez y no factura por factura: el driver de prueba no
            // consulta al SAT, y llenar el informe con un error por comprobante
            // enseñaría a ignorar el informe entero.
            return [
                'consultadas' => 0, 'discrepancias' => [], 'sinRespuesta' => 0, 'enEspera' => 0,
                'omitido' => 'El PAC en uso ('.$this->pac->nombre().') no consulta al SAT.',
            ];
        }

        $resumen = ['consultadas' => 0, 'discrepancias' => [], 'sinRespuesta' => 0, 'enEspera' => 0, 'omitido' => null];

        // Sólo las FISCALES: un borrador o un rechazo no existen para el SAT y
        // preguntarle por ellos sería una llamada por cada intento fallido.
        $consulta = Factura::query()
            ->whereNotNull('uuid')
            ->where('fecha_timbrado', '>=', Carbon::now()->subDays($dias))
            ->orderBy('id');

        if ($limite !== null) {
            $consulta->limit($limite);
        }

        // Por lotes: esto corre de madrugada sobre años de historia y quedarse
        // sin memoria a la mitad dejaría media cartera sin conciliar y el
        // informe diciendo que todo cuadra.
        $consulta->chunkById(200, function ($facturas) use (&$resumen) {
            foreach ($facturas as $factura) {
                $this->conciliarUna($factura, $resumen);
            }
        });

        return $resumen;
    }

    /**
     * @param  array<string, mixed>  $resumen
     */
    private function conciliarUna(Factura $factura, array &$resumen): void
    {
        try {
            $estado = $this->pac->consultarEstado($factura);
        } catch (Throwable $e) {
            // Una factura que revienta no cancela a las demás: es la misma
            // regla de `finanzas:generar-cargos`. Y se anota, en vez de dejar
            // la fila con la respuesta de la consulta anterior, que ya no vale.
            $estado = EstadoEnElPac::desconocido($e->getMessage());
        }

        $factura->forceFill([
            'sat_estado' => $estado->estado,
            'sat_estado_cancelacion' => $estado->cancelacion,
            'sat_error' => $estado->error,
            'sat_consultado_en' => Carbon::now(),
        ])->save();

        $resumen['consultadas']++;

        if (! $estado->conocido) {
            $resumen['sinRespuesta']++;

            return;
        }

        if ($estado->cancelacionEnEspera()) {
            $resumen['enEspera']++;
        }

        // Se relee del modelo ya actualizado y no se recalcula aquí: la
        // definición de «discrepancia» es una sola y vive en la factura.
        $motivo = $factura->discrepanciaSat();

        if ($motivo !== null) {
            $resumen['discrepancias'][] = [
                'id' => $factura->id,
                'uuid' => $factura->uuid,
                'motivo' => $motivo,
            ];
        }
    }
}
