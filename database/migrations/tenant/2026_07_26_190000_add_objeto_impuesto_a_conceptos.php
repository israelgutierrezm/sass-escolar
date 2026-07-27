<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objeto de impuesto (CFDI 4.0) en los conceptos de pago.
 *
 * El concepto ya traía sus datos fiscales (`clave_sat`, `clave_unidad_sat`,
 * `gravado`, `tasa_iva`); faltaba el «ObjImp», que cada partida de una factura
 * debe declarar. Se agrega con default `02` (sí objeto de impuesto), que es lo
 * más común; los servicios educativos exentos se ajustan a `01`/`03` desde el
 * catálogo. Al llevar default, las filas existentes quedan con un valor válido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conceptos_pago', function (Blueprint $table) {
            $table->string('objeto_impuesto', 2)->default('02')->after('tasa_iva');
        });
    }

    public function down(): void
    {
        Schema::table('conceptos_pago', function (Blueprint $table) {
            $table->dropColumn('objeto_impuesto');
        });
    }
};
