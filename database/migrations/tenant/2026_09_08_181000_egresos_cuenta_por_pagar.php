<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras y CxP, rebanada 2: el enlace del pago con su cuenta por pagar.
 *
 * Un pago de CxP es un EGRESO, y una cuenta admite VARIOS pagos (parcialidades).
 * No se puede reusar `egresos.origen_id` para enlazarlos: el único
 * `egreso_origen_unico (origen, origen_id, centro_costo_id)` —que existe para que
 * llevar la misma NÓMINA dos veces no duplique el gasto— impediría un segundo
 * pago del mismo centro a la misma cuenta. Por eso el enlace es una columna
 * propia; el egreso conserva `origen = 'cxp'` (para el guard) con `origen_id`
 * NULL, y así el único deja convivir las parcialidades.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('egresos', 'cuenta_por_pagar_id')) {
            Schema::table('egresos', function (Blueprint $tabla): void {
                $tabla->foreignId('cuenta_por_pagar_id')->nullable()->after('proveedor_id')
                    ->constrained('cuentas_por_pagar')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('egresos', 'cuenta_por_pagar_id')) {
            Schema::table('egresos', function (Blueprint $tabla): void {
                $tabla->dropConstrainedForeignId('cuenta_por_pagar_id');
            });
        }
    }
};
