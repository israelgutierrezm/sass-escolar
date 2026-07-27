<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de facturación (Facturapi) y su bitácora, por escuela (tenant).
 *
 * Es una config ÚNICA por escuela (una fila). Las API keys se guardan CIFRADAS
 * (cast `encrypted` en el modelo) y NUNCA se devuelven completas al frontend ni
 * se escriben en logs: solo se muestran enmascaradas.
 *
 * El AMBIENTE (pruebas/producción) decide qué llave usar; el endpoint de
 * Facturapi es el mismo —la llave `sk_test_` vs `sk_live_` es la que separa los
 * mundos—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturacion_config', function (Blueprint $table) {
            $table->id();
            $table->boolean('activo')->default(false);
            $table->string('ambiente', 12)->default('pruebas'); // pruebas | produccion

            // Credenciales (cifradas). Text porque el valor cifrado es largo.
            $table->text('api_key_pruebas')->nullable();
            $table->text('api_key_produccion')->nullable();
            $table->string('organizacion_id', 100)->nullable();

            // Estado de la conexión.
            $table->string('conexion_estado', 12)->nullable(); // ok | error
            $table->text('conexion_mensaje')->nullable();
            $table->timestamp('conexion_probada_en')->nullable();
            $table->timestamp('validado_en')->nullable();

            // Predeterminados de CFDI (los usa cada factura si no se especifica).
            $table->string('uso_cfdi_default', 4)->nullable();          // G03, P01...
            $table->string('serie_default', 25)->nullable();
            $table->unsignedInteger('folio_inicial')->nullable();
            $table->string('moneda_default', 3)->default('MXN');
            $table->string('forma_pago_default', 2)->nullable();        // 01, 03, 99...
            $table->string('metodo_pago_default', 3)->nullable();       // PUE | PPD
            $table->string('exportacion_default', 2)->default('01');    // 01 = No aplica
            $table->string('objeto_impuesto_default', 2)->nullable();   // 01, 02, 03
            $table->string('version_factura', 5)->default('4.0');       // CFDI 4.0

            $table->auditoria();
        });

        Schema::create('facturacion_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id')->nullable();
            // config_guardada, conexion_probada, ambiente_cambiado,
            // modulo_activado, modulo_desactivado
            $table->string('tipo', 40)->index();
            $table->string('ambiente', 12)->nullable();
            $table->string('resultado', 12)->nullable(); // ok | error
            $table->text('mensaje')->nullable();
            $table->json('detalle')->nullable(); // sin llaves completas jamás
            $table->timestamp('creado_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_eventos');
        Schema::dropIfExists('facturacion_config');
    }
};
