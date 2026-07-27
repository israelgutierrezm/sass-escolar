<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos fiscales del emisor (razón social) para facturar con Facturapi.
 *
 * La razón social ya tenía rfc, razón social, régimen y CP. Aquí se agregan el
 * nombre comercial, el contacto fiscal, el domicilio (opcional, solo cuando el
 * régimen lo exige), el id del emisor en Facturapi y los PREDETERMINADOS de
 * CFDI de esa razón social —cada persona moral puede tener su serie, folios y
 * usos por defecto—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emisores_fiscales', function (Blueprint $table) {
            $table->string('nombre_comercial', 255)->nullable()->after('razon_social');
            $table->string('correo_fiscal', 190)->nullable()->after('cp');
            $table->string('telefono', 20)->nullable()->after('correo_fiscal');

            // Domicilio fiscal (opcional; el CP ya vive en `cp`).
            $table->string('calle', 190)->nullable()->after('telefono');
            $table->string('num_exterior', 30)->nullable()->after('calle');
            $table->string('num_interior', 30)->nullable()->after('num_exterior');
            $table->string('colonia', 190)->nullable()->after('num_interior');
            $table->string('municipio', 190)->nullable()->after('colonia');
            $table->string('estado', 120)->nullable()->after('municipio');
            $table->string('pais', 3)->default('MEX')->after('estado');

            // Vínculo con Facturapi (organización/emisor allá).
            $table->string('facturapi_id', 100)->nullable()->after('pais');

            // Predeterminados de CFDI de esta razón social.
            $table->string('uso_cfdi_default', 4)->nullable()->after('facturapi_id');
            $table->string('serie_default', 25)->nullable()->after('uso_cfdi_default');
            $table->unsignedInteger('folio_inicial')->nullable()->after('serie_default');
            $table->string('moneda_default', 3)->nullable()->after('folio_inicial');
            $table->string('forma_pago_default', 2)->nullable()->after('moneda_default');
            $table->string('metodo_pago_default', 3)->nullable()->after('forma_pago_default');
            $table->string('exportacion_default', 2)->nullable()->after('metodo_pago_default');
            $table->string('objeto_impuesto_default', 2)->nullable()->after('exportacion_default');
        });
    }

    public function down(): void
    {
        Schema::table('emisores_fiscales', function (Blueprint $table) {
            $table->dropColumn([
                'nombre_comercial', 'correo_fiscal', 'telefono',
                'calle', 'num_exterior', 'num_interior', 'colonia', 'municipio', 'estado', 'pais',
                'facturapi_id',
                'uso_cfdi_default', 'serie_default', 'folio_inicial', 'moneda_default',
                'forma_pago_default', 'metodo_pago_default', 'exportacion_default', 'objeto_impuesto_default',
            ]);
        });
    }
};
