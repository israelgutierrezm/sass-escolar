<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El lote de certificación es de certificados TOTALES o PARCIALES (mapea al
 * idTipoCertificacion del DEC: 79 Total / 80 Parcial). Un lote total sólo
 * admite alumnos que ya cerraron su plan; uno parcial, alumnos que aún no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes_certificacion', function (Blueprint $table) {
            $table->string('tipo', 20)->default('total')->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('lotes_certificacion', fn (Blueprint $table) => $table->dropColumn('tipo'));
    }
};
