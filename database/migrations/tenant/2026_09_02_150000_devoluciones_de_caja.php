<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El dinero que SALE del cajón: las devoluciones.
 *
 * ── Por qué no basta con revertir el pago ──────────────────────────────────
 * Revertir un pago lo saca de los totales del turno donde se cobró. Eso alcanza
 * cuando se devuelve el mismo día y en la misma caja —entró y salió, neto
 * cero—, y NO alcanza en el caso normal: se devuelve hoy un pago de la semana
 * pasada. Ahí el dinero sale del cajón de HOY y el turno de entonces no se
 * puede tocar —su corte está firmado—, así que sin este registro la caja de hoy
 * aparece con un faltante que nadie puede explicar.
 *
 * ── Y por eso el turno cuenta también lo REEMBOLSADO ───────────────────────
 * Si la salida se registra siempre, la entrada tiene que seguir contando, o la
 * devolución del mismo día restaría dos veces. Este sistema ya distingue las
 * dos cosas y es lo que lo hace posible: `fallido` es «nunca fue dinero» —no
 * entra ni sale— y `reembolsado` es «fue dinero y se devolvió» —entra y sale—.
 *
 * ── Una devolución es por el pago COMPLETO ─────────────────────────────────
 * Es lo que hace `revertir`, que es todo o nada. Un reembolso parcial exigiría
 * además repartirlo entre los adeudos que el pago cubrió, y eso es otra
 * decisión; media función aquí dejaría cuentas que no cuadran. El `monto` se
 * guarda igual —congelado— porque el movimiento del cajón es un hecho fechado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devoluciones_caja')) {
            return;
        }

        Schema::create('devoluciones_caja', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('sesion_caja_id')->constrained('sesiones_caja');
            // El pago que se devolvió. Sin acción referencial: un pago
            // reembolsado no se borra, y dejar la devolución apuntando a la nada
            // haría irreconstruible el arqueo.
            $tabla->foreignId('pago_id')->constrained('pagos');
            $tabla->decimal('monto', 12, 2);
            $tabla->string('motivo', 255)->nullable();
            $tabla->auditoria();

            // Un pago se devuelve UNA vez: sin esto, dos peticiones simultáneas
            // dejarían dos salidas por el mismo dinero y el arqueo saldría
            // faltante por el doble.
            $tabla->unique('pago_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones_caja');
    }
};
