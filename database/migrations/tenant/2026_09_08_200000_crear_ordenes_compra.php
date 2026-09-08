<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras y CxP, rebanada 3: las ÓRDENES DE COMPRA.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Una OC es un COMPROMISO previo: se arma en borrador, se autoriza (y ahí se
 * vuelve un compromiso presupuestal), y al RECIBIRSE genera la cuenta por pagar
 * —la obligación de pagar lo recibido—. La OC no crea egreso: el egreso nace al
 * pagar la CxP. Así el ejercido sigue teniendo una sola fuente (`egresos`).
 *
 * Lo RECIBIDO se deriva de las CxP que la OC generó (`cuentas_por_pagar` con
 * `origen = 'orden_compra'` y `origen_id` = la OC): no se guarda un contador que
 * se desincronice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ordenes_compra')) {
            Schema::create('ordenes_compra', function (Blueprint $tabla): void {
                $tabla->id();

                $tabla->foreignId('proveedor_id')->constrained('proveedores');
                $tabla->foreignId('centro_costo_id')->constrained('centros_costo');
                $tabla->foreignId('partida_id')->constrained('partidas_presupuesto');
                $tabla->foreignId('ciclo_id')->constrained('ciclos');

                $tabla->date('fecha');

                // borrador | autorizada | recibida | cerrada | cancelada
                $tabla->string('estado', 20)->default('borrador');

                // Quién la autorizó y cuándo: autorizar la vuelve un compromiso.
                $tabla->unsignedBigInteger('autorizada_por')->nullable();
                $tabla->timestamp('autorizada_en')->nullable();

                $tabla->string('referencia', 100)->nullable();
                $tabla->string('notas', 500)->nullable();

                $tabla->auditoria();

                $tabla->index(['estado', 'fecha']);
                $tabla->index(['proveedor_id', 'estado']);
            });
        }

        if (! Schema::hasTable('orden_compra_conceptos')) {
            Schema::create('orden_compra_conceptos', function (Blueprint $tabla): void {
                $tabla->id();
                $tabla->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
                $tabla->string('descripcion', 255);
                $tabla->decimal('cantidad', 12, 2);
                $tabla->decimal('precio_unitario', 12, 2);
                $tabla->auditoria();
            });
        }

        if (! Schema::hasColumn('cuentas_por_pagar', 'orden_compra_id')) {
            Schema::table('cuentas_por_pagar', function (Blueprint $tabla): void {
                // La OC que generó esta CxP, cuando nació de una. `origen`/
                // `origen_id` ya la nombran; esta columna es la FK con integridad.
                $tabla->foreignId('orden_compra_id')->nullable()->after('origen_id')
                    ->constrained('ordenes_compra')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cuentas_por_pagar', 'orden_compra_id')) {
            Schema::table('cuentas_por_pagar', function (Blueprint $tabla): void {
                $tabla->dropConstrainedForeignId('orden_compra_id');
            });
        }

        Schema::dropIfExists('orden_compra_conceptos');
        Schema::dropIfExists('ordenes_compra');
    }
};
