<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos TENANT-CONFIG del módulo de finanzas.
 *
 * `conceptos_pago` responde QUÉ se cobra y trae los datos que el CFDI exige
 * (clave del SAT, si causa IVA y a qué tasa). Se resuelven aquí y no al
 * facturar: la clave de un concepto no cambia entre una factura y otra, y
 * tenerla en el catálogo evita que cada timbrado la adivine.
 *
 * `metodos_pago` va como catálogo aunque la spec lo describía como varchar: en
 * este proyecto todo lo enumerable es tabla, porque una escuela puede aceptar
 * un método que otra no y "efectivo" escrito a mano en una columna acaba
 * conviviendo con "EFECTIVO" y "efectivo ".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conceptos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique(); // colegiatura, inscripcion, titulacion...
            $table->string('nombre', 150);
            $table->string('clave_sat', 15)->nullable();        // ClaveProdServ del CFDI
            $table->string('clave_unidad_sat', 10)->nullable(); // E48 = servicio
            $table->boolean('gravado')->default(false);
            $table->decimal('tasa_iva', 5, 4)->nullable();       // 0.16, 0 exento
            $table->string('cuenta_contable', 50)->nullable();
            $table->auditoria();
        });

        Schema::create('situaciones_pago', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique(); // corriente, moroso, bloqueado, becado
            $table->string('nombre', 100);
            // Si esta situación impide reinscribirse o ver calificaciones. Lo
            // decide la escuela: hay quien bloquea al primer adeudo y quien no
            // bloquea nunca.
            $table->boolean('bloquea')->default(false);
            $table->auditoria();
        });

        Schema::create('metodos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique(); // efectivo, spei, tarjeta, oxxo
            $table->string('nombre', 100);
            $table->string('clave_sat', 5)->nullable(); // 01 efectivo, 03 transferencia...
            // Un pago en ventanilla se da por cobrado al registrarlo; uno por
            // pasarela no lo está hasta que el webhook lo confirma.
            $table->boolean('requiere_confirmacion')->default(false);
            $table->boolean('activo')->default(true);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_pago');
        Schema::dropIfExists('situaciones_pago');
        Schema::dropIfExists('conceptos_pago');
    }
};
