<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Lo que espera una decisión humana en caja.
 *
 * Dos colas de la misma persona: los comprobantes de transferencia que un
 * alumno subió y nadie ha mirado, y los pagos cuyo método exige que alguien los
 * confirme. Se juntan porque quien atiende caja no piensa en ellas por
 * separado: piensa «qué tengo que revisar hoy».
 *
 * ── No hace falta preguntar por `requiere_confirmacion` ───────────────────
 * El estatus del pago no lo elige nadie: lo dicta el método al registrarlo, así
 * que un pago con método que no exige confirmación nace ya completado. Volver a
 * preguntarlo sería un `join` de más para una condición ya garantizada, y es el
 * mismo criterio que ve el alumno en su estado de cuenta.
 *
 * ── Y NO se excluyen los de pasarela ──────────────────────────────────────
 * El cobro en línea crea el pago y lo confirma en la misma llamada. Uno de
 * pasarela que se quedara pendiente es precisamente un cobro a medio conciliar
 * —justo lo que necesita a una persona—, así que esconderlo sería esconder el
 * caso interesante.
 *
 * ── Sin acotar por campus, a propósito ────────────────────────────────────
 * La pantalla de comprobantes tampoco acota. Si la tarjeta contara distinto que
 * la pantalla a la que lleva, el número de arriba no cuadraría con la lista de
 * abajo y nadie sabría cuál mirar.
 */
class CobranzaPorConfirmar implements TarjetaPanel
{
    /** Cuatro de cada cola: la tarjeta avisa, la pantalla lista. */
    private const POR_COLA = 4;

    /** A partir de aquí un comprobante lleva demasiado esperando. */
    private const DIAS_QUE_ARDEN = 2;

    public function clave(): string
    {
        return 'cobranza-por-confirmar';
    }

    public function titulo(): string
    {
        return 'Por confirmar en caja';
    }

    public function permiso(): ?string
    {
        return 'registrar-pagos';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M12 3v8.25m0 0-3-3m3 3 3-3';
    }

    public function datos(Usuario $usuario): ?array
    {
        $totalComprobantes = ComprobantePago::query()->pendientes()->count();
        $totalPagos = Pago::query()->where('estatus', Pago::ESTATUS_PENDIENTE)->count();

        // Cola de trabajo: la caja al día no dibuja tarjeta.
        if ($totalComprobantes === 0 && $totalPagos === 0) {
            return null;
        }

        /*
         * El titular es DUAL —matrícula o aspirante, exactamente uno—, porque el
         * aspirante paga antes de tener matrícula. Se cargan los dos caminos.
         *
         * Las columnas de `personas` son las tres que arma `nombreCompleto()`:
         * pedir de menos devuelve el nombre mutilado sin error, y esa trampa ya
         * mordió antes en el historial.
         */
        $conTitular = [
            'matriculaOferta:id,matricula,persona_id',
            'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
            'aspirante:id,persona_id',
            'aspirante.persona:id,nombre,primer_apellido,segundo_apellido',
        ];

        $comprobantes = ComprobantePago::query()
            ->pendientes()
            ->with($conTitular)
            // El que lleva más esperando primero, igual que la pantalla.
            ->orderBy('created_at')
            ->limit(self::POR_COLA)
            ->get();

        $pagos = Pago::query()
            ->where('estatus', Pago::ESTATUS_PENDIENTE)
            ->with([...$conTitular, 'metodoPago:id,nombre'])
            ->orderBy('momento')
            ->limit(self::POR_COLA)
            ->get();

        return [
            'renglones' => [
                ...$comprobantes->map(fn (ComprobantePago $c) => $this->deComprobante($c))->all(),
                ...$pagos->map(fn (Pago $p) => $this->dePago($p))->all(),
            ],
            'pie' => $this->resumen($totalComprobantes, $totalPagos),
            'enlace' => '/finanzas/comprobantes',
        ];
    }

    /** Sin fingir plurales: «1 comprobantes» delata que nadie miró el texto. */
    private function resumen(int $comprobantes, int $pagos): string
    {
        $piezas = [];

        if ($comprobantes > 0) {
            $piezas[] = $comprobantes === 1 ? '1 comprobante' : "{$comprobantes} comprobantes";
        }

        if ($pagos > 0) {
            $piezas[] = $pagos === 1 ? '1 pago por confirmar' : "{$pagos} pagos por confirmar";
        }

        return implode(' · ', $piezas);
    }

    /** @return array<string, mixed> */
    private function deComprobante(ComprobantePago $comprobante): array
    {
        return [
            'etiqueta' => $comprobante->titular()?->persona?->nombreCompleto() ?? 'Sin titular',
            'detalle' => 'Comprobante · '.($comprobante->fecha_transferencia?->format('d/m/Y') ?? 's/f'),
            'valor' => '$'.number_format((float) $comprobante->monto, 2),
            'pie' => 'esperando desde hace '.$comprobante->created_at?->diffForHumans(null, true),
            'progreso' => null,
            // Lo que arde no es el monto: es el tiempo que alguien lleva sin
            // saber si su pago quedó registrado.
            'alerta' => $comprobante->created_at?->lt(now()->subDays(self::DIAS_QUE_ARDEN)) ?? false,
            'enlace' => '/finanzas/comprobantes',
        ];
    }

    /** @return array<string, mixed> */
    private function dePago(Pago $pago): array
    {
        return [
            'etiqueta' => $pago->titular()?->persona?->nombreCompleto() ?? 'Sin titular',
            'detalle' => 'Por confirmar · '.($pago->metodoPago?->nombre ?? 'método desconocido'),
            'valor' => '$'.number_format((float) $pago->monto, 2),
            'pie' => null,
            'progreso' => null,
            'alerta' => false,
            // El aspirante no tiene pantalla de estado de cuenta: sin matrícula,
            // el renglón informa pero no lleva a ninguna parte.
            'enlace' => $pago->matricula_oferta_id !== null
                ? "/finanzas/cuentas/{$pago->matricula_oferta_id}"
                : null,
        ];
    }
}
