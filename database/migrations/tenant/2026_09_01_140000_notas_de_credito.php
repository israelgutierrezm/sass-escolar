<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La nota de crédito: corregir una factura sin cancelarla.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * Hasta hoy la ÚNICA corrección disponible era cancelar y refacturar, y eso no
 * sirve para el caso más frecuente: una beca o un descuento que se autoriza
 * después de haber facturado, o un cobro de más que se descubre semanas
 * después. Peor, cancelar deja de ser una opción en cuanto pasa el plazo del
 * SAT o cuando el receptor se niega a aceptarla — y entonces la escuela se
 * queda sin ningún instrumento y el importe declarado nunca se corrige.
 *
 * ── `factura_origen_id` es OTRA cosa que `factura_sustituye_id` ────────────
 * Las dos apuntan a una factura, y fundirlas rompería el cobro. La sustitución
 * significa «ésta reemplaza a aquélla», y `EmisorFactura::pagosOcupados` se
 * apoya en eso: una factura con sustituta viva DEJA DE AMPARAR sus pagos, para
 * que la nueva pueda tomarlos.
 *
 * Una nota de crédito no reemplaza nada: la factura original sigue vigente y
 * sigue amparando su dinero; lo único que cambia es cuánto se cobró de verdad.
 * Con una sola columna, emitir una nota de crédito liberaría los pagos de la
 * original y el mismo dinero se podría facturar dos veces.
 *
 * ── `tipo` como columna y no como catálogo ─────────────────────────────────
 * Sus valores los define el SAT y los reconoce el código —'I' de ingreso, 'E'
 * de egreso—, no algo que una escuela deba renombrar. Mismo criterio que
 * `facturas.estatus` y `actas.situacion`.
 *
 * Las facturas que ya existen son todas de INGRESO, que es lo único que este
 * sistema sabía emitir, así que el default sirve de relleno y no hay backfill
 * que hacer.
 *
 * ── `motivo_egreso` es obligatorio en la práctica ──────────────────────────
 * Una nota de crédito reduce lo declarado al SAT. Sin la razón escrita, dentro
 * de un año nadie puede explicar por qué la escuela declaró menos ingreso, que
 * es exactamente lo que una revisión pregunta. La columna es nullable porque
 * las filas viejas no la tienen; quien emite una nota SÍ la debe capturar, y
 * eso lo exige la validación.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pieza por pieza, no por bloque: un reintento tras un fallo parcial no
        // debe saltarse lo que quedó pendiente.
        if (! Schema::hasColumn('facturas', 'tipo')) {
            Schema::table('facturas', function (Blueprint $tabla) {
                $tabla->string('tipo', 1)->default('I')->after('matricula_oferta_id');
            });
        }

        if (! Schema::hasColumn('facturas', 'factura_origen_id')) {
            Schema::table('facturas', function (Blueprint $tabla) {
                // Sin acción referencial: una factura timbrada no se borra, y
                // si alguien borrara un borrador, dejar la nota apuntando a la
                // nada es peor que impedir el borrado.
                $tabla->foreignId('factura_origen_id')->nullable()
                    ->after('factura_sustituye_id')
                    ->constrained('facturas');
            });
        }

        if (! Schema::hasColumn('facturas', 'motivo_egreso')) {
            Schema::table('facturas', function (Blueprint $tabla) {
                $tabla->string('motivo_egreso', 255)->nullable()->after('factura_origen_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('facturas', 'factura_origen_id')) {
            Schema::table('facturas', function (Blueprint $tabla) {
                $tabla->dropForeign(['factura_origen_id']);
                $tabla->dropColumn('factura_origen_id');
            });
        }

        foreach (['motivo_egreso', 'tipo'] as $columna) {
            if (Schema::hasColumn('facturas', $columna)) {
                Schema::table('facturas', fn (Blueprint $t) => $t->dropColumn($columna));
            }
        }
    }
};
