<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cierre de un periodo fiscal.
 *
 * ── Qué significa cerrar aquí, que no es lo obvio ──────────────────────────
 * En este sistema una factura se emite SIEMPRE con la fecha de hoy —se factura
 * dinero ya cobrado—, así que «cerrar» no puede querer decir «que no entren
 * comprobantes nuevos con fecha vieja»: eso no puede pasar.
 *
 * Lo que sí puede pasar, y es lo que el cierre impide, es que alguien CANCELE
 * un comprobante de un mes ya declarado. Eso cambia hacia atrás un número que
 * la escuela ya presentó, y es el error caro: se descubre en la siguiente
 * revisión y no hay forma de explicarlo.
 *
 * ── La nota de crédito SIGUE permitida, a propósito ────────────────────────
 * Es la asimetría que hace útil todo esto. Una nota de crédito se emite con
 * fecha de HOY y pertenece al periodo de hoy, así que corrige el mes cerrado
 * sin tocarlo — que es exactamente lo que hace un contador cuando el mes ya se
 * declaró. Cerrar el periodo no bloquea la corrección: la empuja al
 * instrumento correcto.
 *
 * ── Los totales se CONGELAN al cerrar ──────────────────────────────────────
 * No se recalculan al mirarlos. Un cierre es una afirmación fechada —«este mes
 * tenía estos comprobantes y estos importes»— y recalcularla haría que el
 * cierre cambiara solo, que es justo lo que un cierre existe para impedir. Si
 * lo congelado y lo actual difieren, la diferencia se puede ver; recalculando,
 * no habría contra qué comparar.
 *
 * ── Lo que NO guarda ───────────────────────────────────────────────────────
 * La cadena completa de cierres y reaperturas. Se conserva el motivo de la
 * ÚLTIMA reapertura y la auditoría de quién y cuándo; una bitácora de todos los
 * movimientos sería otra tabla y no se construye por si acaso. Misma decisión
 * que en las autorizaciones de la familia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('periodos_fiscales')) {
            return;
        }

        Schema::create('periodos_fiscales', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedSmallInteger('anio');
            $tabla->unsignedTinyInteger('mes');

            // Nulo = abierto. Es el estado, y no una bandera aparte: con las dos
            // cosas se podría quedar «cerrado» sin fecha de cierre, y nadie
            // sabría cuál manda.
            $tabla->timestamp('cerrado_en')->nullable();

            // Los totales del momento del cierre. Ver la nota de arriba.
            $tabla->unsignedInteger('comprobantes')->default(0);
            $tabla->decimal('ingresos', 14, 2)->default(0);
            $tabla->decimal('egresos', 14, 2)->default(0);

            $tabla->timestamp('reabierto_en')->nullable();
            $tabla->string('motivo_reapertura', 255)->nullable();

            // Un mes se cierra una vez: sin el único, dos peticiones simultáneas
            // crearían dos filas del mismo periodo y una diría abierto y la otra
            // cerrado.
            $tabla->unique(['anio', 'mes']);

            $tabla->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_fiscales');
    }
};
