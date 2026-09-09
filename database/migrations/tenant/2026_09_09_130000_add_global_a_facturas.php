<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La factura GLOBAL: un CFDI que ampara las ventas al público en general de un
 * periodo, sin receptor nominativo. Se distingue de una nominativa por
 * `es_global` y lleva su `InformacionGlobal` (periodicidad, mes, año), que es lo
 * que el SAT exige para agrupar por periodo.
 *
 * `matricula_oferta_id` YA es nullable: una global no cuelga de una matrícula
 * —agrupa pagos de muchas—, así que ahí va en NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->boolean('es_global')->default(false);
            // Claves del SAT: c_Periodicidad (01 Diario … 05 Bimestral) y
            // c_Meses (01-12, o 13-18 en bimestral). Se guardan tal cual viajan.
            $table->string('periodicidad_global', 2)->nullable();
            $table->string('periodo_global_meses', 2)->nullable();
            $table->unsignedSmallInteger('periodo_global_anio')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['es_global', 'periodicidad_global', 'periodo_global_meses', 'periodo_global_anio']);
        });
    }
};
