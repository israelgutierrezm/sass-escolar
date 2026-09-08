<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras y CxP, rebanada 2: las CUENTAS POR PAGAR.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Una CxP es una OBLIGACIÓN (compromiso), todavía no un gasto. **Pagarla registra
 * un `egreso`** —ahí, y sólo ahí, el dinero «sale»— y ese egreso es el que
 * consume presupuesto. La CxP nunca cuenta como ejercido por sí sola. Así
 * «ejercido» sigue significando lo mismo en todo el sistema (una sola fuente:
 * `egresos`).
 *
 * Los PAGOS de una CxP son sus egresos con `origen = 'cxp'` y `origen_id` = su id:
 * no hace falta una tabla de pagos aparte, y el saldo se deriva de ellos, como el
 * estatus del adeudo en `RegistradorPago`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cuentas_por_pagar')) {
            return;
        }

        Schema::create('cuentas_por_pagar', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $tabla->foreignId('centro_costo_id')->constrained('centros_costo');
            $tabla->foreignId('partida_id')->constrained('partidas_presupuesto');
            $tabla->foreignId('ciclo_id')->constrained('ciclos');

            $tabla->string('concepto', 255);
            $tabla->decimal('monto', 12, 2);
            $tabla->date('fecha');
            $tabla->date('vencimiento');

            // pendiente | parcial | pagada | cancelada. Se DERIVA de lo pagado
            // (sus egresos), como el estatus del adeudo; se guarda porque el
            // listado y la antigüedad de saldos filtran por él.
            $tabla->string('estado', 20)->default('pendiente');

            $tabla->string('referencia', 100)->nullable();
            $tabla->string('comprobante_ruta', 255)->nullable();
            $tabla->string('comprobante_nombre', 255)->nullable();

            // De dónde nació: capturada directa, o de una orden de compra
            // (rebanada 3). `origen_id` es la OC cuando viene de una.
            $tabla->string('origen', 20)->default('captura');
            $tabla->unsignedBigInteger('origen_id')->nullable();

            $tabla->auditoria();

            // Para la antigüedad de saldos: lo que se debe, por vencimiento.
            $tabla->index(['estado', 'vencimiento']);
            $tabla->index(['proveedor_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_por_pagar');
    }
};
