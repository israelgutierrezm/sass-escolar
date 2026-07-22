<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CFDI 4.0: el comprobante fiscal de lo que la escuela cobró.
 *
 * **Inmutable por regulación.** Un CFDI timbrado no se edita: corregirlo es
 * cancelarlo y volver a facturar. Por eso no hay ninguna pantalla que permita
 * cambiarle el receptor, los conceptos o los importes a una factura con UUID.
 *
 * La distinción que sí se hace, y que no contradice lo anterior: los DATOS
 * FISCALES no se tocan, pero el CICLO DE VIDA sí se registra. Cancelar escribe
 * `cancelada_en`, el motivo del SAT y —cuando aplica— cuál factura la
 * sustituye. Sin esas columnas la cancelación no tendría dónde constar, y la
 * regla "cancelación + refactura" quedaría en el aire.
 *
 * Lo que NO lleva, y es deliberado:
 *  - **Serie y folio interno.** En CFDI 4.0 son opcionales y el identificador
 *    fiscal es el UUID. Un consecutivo propio obligaría a otra tabla de
 *    contadores (el patrón de `contadores_acta`) para algo que hoy nadie pide.
 *  - **Datos fiscales del receptor guardados aparte.** Se capturan por factura
 *    y se copian aquí. Es lo correcto además de lo simple: el CFDI congela con
 *    qué RFC y régimen se emitió, y si el alumno cambia de régimen el año que
 *    entra, la factura vieja debe seguir diciendo lo que decía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');

            // Receptor: copiado, no referenciado. Ver la nota de arriba.
            $table->string('receptor_rfc', 13);
            $table->string('receptor_razon_social', 255);
            $table->string('receptor_uso_cfdi', 5);        // D10 colegiaturas, G03 gastos
            $table->string('receptor_regimen_fiscal', 5);  // 605, 612, 616...
            $table->string('receptor_cp', 5);              // domicilio fiscal, obligatorio en 4.0

            // Sin estos dos no se puede timbrar: son campos requeridos del
            // comprobante, no adornos. `forma_pago_sat` sale de
            // `metodos_pago.clave_sat`; `metodo_pago_sat` es PUE o PPD.
            $table->string('forma_pago_sat', 5)->nullable();
            $table->string('metodo_pago_sat', 5)->default('PUE');

            $table->string('moneda', 3)->default('MXN');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('iva', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            $table->string('uuid', 36)->nullable()->unique(); // folio fiscal
            $table->string('pac', 30)->nullable();            // facturama / sw_sapien / finkok
            $table->string('estatus', 20)->default('borrador'); // borrador/timbrando/timbrada/error/cancelada
            $table->text('xml_ruta')->nullable();
            $table->text('pdf_ruta')->nullable();
            $table->timestamp('fecha_timbrado')->nullable();

            // El timbrado corre en cola porque el PAC puede tardar o caerse.
            // Sin estas dos columnas, una factura fallida se queda en borrador
            // sin que nadie sepa por qué y alguien la vuelve a intentar a
            // ciegas.
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('ultimo_error')->nullable();

            // Cancelación: ciclo de vida, no edición de datos fiscales.
            $table->timestamp('cancelada_en')->nullable();
            $table->string('motivo_cancelacion', 2)->nullable(); // 01..04 del SAT
            $table->foreignId('factura_sustituye_id')->nullable()->constrained('facturas');

            $table->auditoria();

            $table->index(['matricula_oferta_id', 'estatus']);
            $table->index('estatus');
        });

        Schema::create('factura_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            // De qué pago salió el renglón. Es lo que impide facturar dos veces
            // el mismo dinero: se comprueba contra las facturas vivas.
            $table->foreignId('pago_id')->nullable()->constrained('pagos');
            $table->string('clave_sat', 15);
            $table->string('clave_unidad_sat', 10)->default('E48');
            $table->string('descripcion', 255);
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('valor_unitario', 12, 2);
            $table->decimal('importe', 12, 2);
            $table->decimal('iva', 12, 2)->default(0);
            $table->auditoria();

            $table->index('pago_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_conceptos');
        Schema::dropIfExists('facturas');
    }
};
