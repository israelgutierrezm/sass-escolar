<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlace con Facturapi en las facturas y objeto de impuesto por renglón.
 *
 * `facturapi_id` guarda el id de la factura EN Facturapi (distinto del folio
 * fiscal/UUID del SAT): se necesita para cancelar, descargar el XML/PDF y
 * consultar su estado allá. El objeto de impuesto se copia al renglón —igual
 * que las demás claves fiscales— para que el comprobante ya timbrado conserve
 * lo que se declaró aunque el catálogo cambie después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('facturapi_id', 100)->nullable()->after('uuid');
        });

        Schema::table('factura_conceptos', function (Blueprint $table) {
            $table->string('objeto_impuesto', 2)->default('02')->after('iva');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', fn (Blueprint $t) => $t->dropColumn('facturapi_id'));
        Schema::table('factura_conceptos', fn (Blueprint $t) => $t->dropColumn('objeto_impuesto'));
    }
};
