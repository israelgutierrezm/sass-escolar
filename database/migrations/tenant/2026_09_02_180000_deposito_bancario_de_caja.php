<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pasó con el efectivo que se contó: el depósito al banco.
 *
 * ── Es el eslabón que faltaba ──────────────────────────────────────────────
 * El corte contesta «¿lo que hay en el cajón es lo que debería?». Falta la
 * siguiente pregunta, que es la que hace la dirección: «¿y ese dinero llegó al
 * banco?». Sin ella el rastro se corta en el cajón, y un faltante que aparece
 * entre la ventanilla y la sucursal no tiene dónde notarse.
 *
 * Es además lo que hará posible la conciliación bancaria: sin un registro de
 * lo depositado no hay contra qué casar el movimiento del estado de cuenta.
 *
 * ── Una COLUMNA en la sesión, no una tabla pivote ──────────────────────────
 * Un turno se deposita como mucho una vez, y un depósito junta varios turnos
 * —lo normal es llevar al banco lo de todo el día—. Eso es uno a muchos, no
 * muchos a muchos: con `sesiones_caja.deposito_caja_id` la regla «un turno se
 * deposita una vez» la sostiene la estructura, sin índice único que recordar ni
 * pivote que consultar.
 *
 * ── El importe se CAPTURA, no se calcula ───────────────────────────────────
 * Lo natural sería exigir que el depósito sea exactamente lo contado menos el
 * fondo. No se hace: la escuela decide si deja fondo para mañana, si junta dos
 * días o si separa un gasto, y forzar la igualdad convertiría cada caso normal
 * en un impedimento. Lo que sí hace la pantalla es enseñar la cuenta propuesta
 * y la diferencia, para que capturar otra cosa sea una decisión y no un dedazo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('depositos_caja')) {
            Schema::create('depositos_caja', function (Blueprint $tabla) {
                $tabla->id();
                // A qué cuenta fue. Es lo que después permite casarlo contra el
                // estado de cuenta de ESA cuenta y no de otra.
                $tabla->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias');
                $tabla->decimal('monto', 12, 2);
                $tabla->date('fecha');
                // La ficha o el folio de la operación bancaria: sin él, casar el
                // depósito con el renglón del banco es adivinar por importe.
                $tabla->string('referencia', 100)->nullable();
                $tabla->text('notas')->nullable();
                $tabla->auditoria();

                $tabla->index('fecha');
            });
        }

        if (! Schema::hasColumn('sesiones_caja', 'deposito_caja_id')) {
            Schema::table('sesiones_caja', function (Blueprint $tabla) {
                // Sin acción referencial: un depósito no se borra —es el rastro
                // de que el dinero salió— y dejar el turno apuntando a la nada
                // haría irreconstruible a dónde fue.
                $tabla->foreignId('deposito_caja_id')->nullable()
                    ->after('autorizada_en')
                    ->constrained('depositos_caja');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sesiones_caja', 'deposito_caja_id')) {
            Schema::table('sesiones_caja', function (Blueprint $tabla) {
                $tabla->dropForeign(['deposito_caja_id']);
                $tabla->dropColumn('deposito_caja_id');
            });
        }

        Schema::dropIfExists('depositos_caja');
    }
};
