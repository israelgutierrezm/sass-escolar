<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el SAT dice de cada comprobante, guardado al lado de lo que decimos
 * nosotros.
 *
 * ── Por qué son columnas propias y no un UPDATE de `estatus` ───────────────
 * La tentación es escribir «cancelada» en `estatus` cuando el SAT dice que lo
 * está. No se hace, y es la decisión que sostiene toda la conciliación:
 * `estatus` es NUESTRO estado de trabajo y tiene consecuencias —`vivas()`
 * decide qué pagos siguen amparados—, así que moverlo desde un comando de
 * madrugada LIBERARÍA esos pagos y alguien podría volver a facturar el mismo
 * dinero sin que nadie lo pidiera.
 *
 * Guardando las dos versiones, la discrepancia se puede ver, explicar y
 * resolver a mano. Es la misma regla que `acadion:auditar-datos`: informar por
 * omisión, y que reparar sea un acto deliberado.
 *
 * ── `sat_estado_cancelacion` es OTRA cosa que `sat_estado` ─────────────────
 * Una factura puede estar VIGENTE con una cancelación PENDIENTE de que el
 * receptor la acepte. Es el caso que más engaña: aquí se pidió la cancelación,
 * la base dice «cancelada» y el CFDI sigue vivo ante el SAT. Con una sola
 * columna ese estado intermedio no se puede expresar.
 *
 * ── `sat_error` no sobra ───────────────────────────────────────────────────
 * Que el PAC no conteste es una tercera respuesta. Si sólo se reportara en la
 * salida del comando, se perdería: eso corre de madrugada y nadie lo lee. Con
 * la columna, la factura que no se pudo consultar lo dice en su pantalla.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['sat_estado', fn (Blueprint $t) => $t->string('sat_estado', 20)->nullable()->after('motivo_cancelacion')],
            ['sat_estado_cancelacion', fn (Blueprint $t) => $t->string('sat_estado_cancelacion', 20)->nullable()->after('sat_estado')],
            ['sat_error', fn (Blueprint $t) => $t->string('sat_error', 255)->nullable()->after('sat_estado_cancelacion')],
            ['sat_consultado_en', fn (Blueprint $t) => $t->timestamp('sat_consultado_en')->nullable()->after('sat_error')],
        ] as [$columna, $agregar]) {
            // Pieza por pieza: un reintento tras un fallo parcial no debe
            // saltarse lo que quedó pendiente.
            if (! Schema::hasColumn('facturas', $columna)) {
                Schema::table('facturas', $agregar);
            }
        }
    }

    public function down(): void
    {
        foreach (['sat_consultado_en', 'sat_error', 'sat_estado_cancelacion', 'sat_estado'] as $columna) {
            if (Schema::hasColumn('facturas', $columna)) {
                Schema::table('facturas', fn (Blueprint $t) => $t->dropColumn($columna));
            }
        }
    }
};
