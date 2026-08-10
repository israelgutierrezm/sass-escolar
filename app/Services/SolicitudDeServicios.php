<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\Servicio;
use App\Models\Finanzas\SolicitudServicio;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pedir un servicio del catálogo, y el cargo que eso genera.
 *
 * Vive en un servicio y no en el controlador porque la operación son DOS
 * escrituras que tienen que pasar juntas: la solicitud y su adeudo. Si el cargo
 * se generara sin la solicitud, aparecería un cobro que el alumno no reconoce;
 * si la solicitud se guardara sin el cargo, la escuela trabajaría gratis y nadie
 * lo notaría hasta el corte.
 */
class SolicitudDeServicios
{
    /**
     * Cuántos días tiene el alumno para pagar el servicio que pidió.
     *
     * Constante y no ajuste configurable, a propósito: un servicio no vence como
     * una colegiatura —nadie persigue a quien no pagó su constancia, simplemente
     * no se la dan—, así que la fecha existe porque la columna la exige y porque
     * ordena la cartera, no porque cambie nada al llegar. Un ajuste en pantalla
     * invitaría a moverlo esperando un efecto que no tiene.
     */
    private const DIAS_PARA_PAGAR = 30;

    /**
     * Registra la solicitud y, si el servicio tiene costo, su cargo.
     *
     * El precio se copia del catálogo EN ESTE MOMENTO y no se lee después: si
     * la escuela sube la tarifa mañana, a quien pidió hoy se le cobra lo que
     * vio. Es la misma razón por la que una factura guarda el importe y no un
     * puntero al precio de lista.
     */
    public function pedir(
        Servicio $servicio,
        MatriculaOferta $matricula,
        ?string $nota = null,
    ): SolicitudServicio {
        if (! $servicio->activo || ! $servicio->solicitable) {
            throw new RuntimeException('Ese servicio no está disponible para solicitarse.');
        }

        return DB::transaction(function () use ($servicio, $matricula, $nota) {
            $adeudo = $servicio->tieneCosto()
                ? $this->cargoDe($servicio, $matricula)
                : null;

            return SolicitudServicio::create([
                'servicio_id' => $servicio->id,
                'matricula_oferta_id' => $matricula->id,
                'adeudo_id' => $adeudo?->id,
                'estado' => SolicitudServicio::ESTADO_PEDIDA,
                'nota_alumno' => $nota,
            ]);
        });
    }

    /**
     * Cierra la solicitud: atendida o rechazada.
     *
     * Rechazar NO cancela el adeudo aquí a propósito. Devolver dinero es un acto
     * de finanzas —hay que decidir si se condona, si se aplica a otra cosa o si
     * se reembolsa— y tiene su propia pantalla, con su permiso y su bitácora.
     * Hacerlo de paso desde el mostrador escondería un movimiento de dinero
     * dentro de una acción que parece administrativa.
     */
    public function cerrar(
        SolicitudServicio $solicitud,
        string $estado,
        ?string $respuesta,
        ?int $personaId,
    ): void {
        if ($solicitud->estado !== SolicitudServicio::ESTADO_PEDIDA) {
            throw new RuntimeException('Esa solicitud ya estaba cerrada.');
        }

        $solicitud->update([
            'estado' => $estado,
            'respuesta' => $respuesta,
            'atendida_por' => $personaId,
            'atendida_en' => now(),
        ]);
    }

    /** El alumno se arrepiente antes de que la atiendan. */
    public function cancelar(SolicitudServicio $solicitud): void
    {
        if (! $solicitud->esCancelable()) {
            throw new RuntimeException('Esa solicitud ya no se puede cancelar.');
        }

        $solicitud->update(['estado' => SolicitudServicio::ESTADO_CANCELADA]);
    }

    /**
     * El cargo del servicio.
     *
     * `monto_total` se escribe y no se deja calcular, igual que en los cargos
     * sueltos de admisión: es el número que miran el saldo del portal y quien
     * aplica el pago. Un servicio no lleva recargos —se paga para que arranque
     * el trámite, no a plazos— ni beca, que se otorga por plan de estudios.
     */
    private function cargoDe(Servicio $servicio, MatriculaOferta $matricula): Adeudo
    {
        return Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $servicio->concepto_id,
            'monto' => $servicio->precio,
            'monto_total' => $servicio->precio,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(self::DIAS_PARA_PAGAR)->toDateString(),
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);
    }
}
