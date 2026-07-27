<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de facturación del alumno.
 *
 * Guarda si el alumno QUIERE factura y los datos fiscales del RECEPTOR del CFDI.
 * El receptor puede ser el propio alumno o un TERCERO (un padre, una empresa),
 * por eso los datos fiscales viven aquí y no en `personas`: la persona es quien
 * estudia, el receptor es a nombre de quién sale la factura.
 *
 * Uno por alumno (persona). `facturapi_customer_id` queda listo para cuando se
 * cree el cliente en Facturapi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datos_facturacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->boolean('quiere_factura')->default(false);
            $table->boolean('es_tercero')->default(false);

            // Datos fiscales del receptor.
            $table->string('rfc', 13)->nullable();
            $table->string('razon_social', 255)->nullable();
            $table->string('regimen_fiscal', 5)->nullable();
            $table->string('cp', 5)->nullable();
            $table->string('uso_cfdi', 4)->nullable();
            $table->string('correo_fiscal', 190)->nullable();

            $table->string('facturapi_customer_id', 100)->nullable();
            $table->auditoria();

            $table->unique('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datos_facturacion');
    }
};
