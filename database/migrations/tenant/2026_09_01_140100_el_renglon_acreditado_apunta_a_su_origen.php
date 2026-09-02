<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada renglón de una nota de crédito dice QUÉ renglón está acreditando.
 *
 * ── Por qué no basta con el total ──────────────────────────────────────────
 * La pregunta que hay que poder contestar antes de emitir es «¿cuánto queda por
 * acreditar de este concepto?», y no «¿cuánto queda de la factura». Una factura
 * con colegiatura exenta de 2 500 y constancia gravada de 200 admite acreditar
 * 2 500 de la primera; comparando sólo totales, alguien podría acreditar 2 700
 * contra la constancia y reversar un IVA que nunca se causó.
 *
 * Es la misma razón por la que el IVA se desglosa por concepto y no sobre el
 * total: en un comprobante conviven partidas con tasas distintas.
 *
 * Nullable porque los renglones de una FACTURA no acreditan nada; sólo los de
 * una nota de crédito lo llevan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('factura_conceptos', 'concepto_origen_id')) {
            return;
        }

        Schema::table('factura_conceptos', function (Blueprint $tabla) {
            // Sin acción referencial: los renglones de una factura timbrada no
            // se borran, y dejar el de una nota apuntando a la nada haría
            // imposible saber qué se acreditó.
            $tabla->foreignId('concepto_origen_id')->nullable()
                ->after('pago_id')
                ->constrained('factura_conceptos');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('factura_conceptos', 'concepto_origen_id')) {
            return;
        }

        Schema::table('factura_conceptos', function (Blueprint $tabla) {
            $tabla->dropForeign(['concepto_origen_id']);
            $tabla->dropColumn('concepto_origen_id');
        });
    }
};
