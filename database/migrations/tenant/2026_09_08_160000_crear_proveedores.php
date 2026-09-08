<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras y cuentas por pagar, rebanada 1: los PROVEEDORES.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Un proveedor ESTRUCTURA el `beneficiario` del egreso (hoy texto libre): no crea
 * una segunda verdad del gasto, sólo le pone nombre y RFC a quién se le pagó. El
 * `beneficiario` libre se queda para pagos que no son a un proveedor (un
 * reembolso, una persona). Institucional, sin acotar por campus: un proveedor le
 * factura a la persona moral, no a un plantel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('proveedores')) {
            Schema::create('proveedores', function (Blueprint $tabla): void {
                $tabla->id();
                $tabla->string('nombre', 200);
                // Opcional pero ÚNICO: una escuela captura proveedores antes de
                // tener su papel fiscal, pero el mismo capturado dos veces reparte
                // sus egresos entre duplicados y ningún reporte cuadra.
                $tabla->string('rfc', 15)->nullable()->unique();
                $tabla->string('razon_social', 200)->nullable();
                $tabla->string('contacto_nombre', 160)->nullable();
                $tabla->string('telefono', 40)->nullable();
                $tabla->string('correo', 160)->nullable();
                $tabla->string('domicilio', 255)->nullable();
                // Se APAGA, no se borra: sus egresos y cuentas por pagar son
                // historia y no se pueden quedar colgando.
                $tabla->boolean('activo')->default(true);
                $tabla->string('notas', 500)->nullable();

                $tabla->auditoria();
            });
        }

        if (! Schema::hasColumn('egresos', 'proveedor_id')) {
            Schema::table('egresos', function (Blueprint $tabla): void {
                // Nullable: hay egresos que no son a un proveedor. `nullOnDelete`
                // no aplica —un proveedor con egresos no se borra—, pero si algún
                // día se depurara, el egreso conserva su beneficiario libre.
                $tabla->foreignId('proveedor_id')->nullable()->after('centro_costo_id')
                    ->constrained('proveedores')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('egresos', 'proveedor_id')) {
            Schema::table('egresos', function (Blueprint $tabla): void {
                $tabla->dropConstrainedForeignId('proveedor_id');
            });
        }

        Schema::dropIfExists('proveedores');
    }
};
