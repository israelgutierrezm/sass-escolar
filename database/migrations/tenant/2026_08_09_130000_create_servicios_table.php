<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * servicios (TENANT-CONFIG) — el catálogo de productos y servicios que la
 * escuela vende de uno en uno: constancias, credencial de repuesto, examen
 * extraordinario, cartas.
 *
 * ── Por qué una tabla nueva y no un precio en `conceptos_pago` ─────────────
 * `conceptos_pago` responde QUÉ se cobra y trae la identidad fiscal —clave del
 * SAT, si causa IVA y a qué tasa—, pero a propósito no tiene precio: el mismo
 * concepto vale distinto en cada plan de cobro, y por eso el importe vive en las
 * líneas del plan. Un servicio es lo contrario: tiene UN precio de lista que no
 * depende de ningún plan.
 *
 * Meter ese precio en `conceptos_pago` obligaría a que «colegiatura» tuviera uno
 * que no significa nada. Y duplicar aquí las claves del SAT dejaría dos sitios
 * donde vive lo fiscal del mismo concepto, que es donde acaban divergiendo. Así
 * que el servicio APUNTA a su concepto y sólo agrega lo suyo: cuánto cuesta.
 *
 * ── Por qué el concepto es nulo cuando el servicio es gratuito ─────────────
 * Un servicio sin costo no genera adeudo ni se factura nunca, así que exigirle
 * clave del SAT sería pedir un dato fiscal para algo que no llega a Hacienda.
 * Cuando el precio es mayor que cero, el concepto es obligatorio y lo comprueba
 * el controlador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->string('descripcion', 500)->nullable();

            $table->foreignId('concepto_id')->nullable()
                ->constrained('conceptos_pago')
                ->restrictOnDelete();

            // Cero = sin costo. Es un importe, no una ausencia: un servicio
            // gratuito lo es de verdad, no es uno al que le falta el precio.
            $table->decimal('precio', 10, 2)->default(0);

            // Lo que sigue lo administra Control Escolar, no Finanzas. Vive en
            // la misma fila porque es el mismo servicio visto desde dos áreas;
            // partirlo en dos tablas obligaría a unirlas en cada pantalla para
            // responder «¿qué puede pedir el alumno y cuánto cuesta?».
            $table->boolean('solicitable')->default(false);
            $table->string('instrucciones', 1000)->nullable();

            $table->boolean('activo')->default(true);
            $table->auditoria();

            $table->index(['activo', 'solicitable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};
