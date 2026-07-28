<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pasarelas de pago que la escuela puede habilitar para que los alumnos paguen
 * (Stripe, Mercado Pago, PayPal, OpenPay). Una fila por pasarela.
 *
 * Las credenciales van CIFRADAS y separadas por ambiente (pruebas/producción):
 * el JSON de cada ambiente guarda las llaves propias de esa pasarela. Solo se
 * puede activar una pasarela si su ambiente activo trae completos los campos
 * requeridos (eso se valida en el controlador, no en la base).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasarelas_pago', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique(); // stripe, mercadopago, paypal, openpay
            $table->boolean('activa')->default(false);
            $table->string('ambiente', 20)->default('pruebas'); // pruebas | produccion
            // Credenciales por ambiente, cifradas (cast encrypted:array en el modelo).
            $table->text('credenciales_pruebas')->nullable();
            $table->text('credenciales_produccion')->nullable();
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasarelas_pago');
    }
};
