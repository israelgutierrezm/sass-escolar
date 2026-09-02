<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caja y cortes: cuadrar el efectivo contra lo que el sistema dice que entró.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * Hoy `pagos` registra dinero recibido y nada más. Un cobro en efectivo en la
 * ventanilla y una transferencia entran idénticos: no hay forma de saber en qué
 * caja se recibió el efectivo, ni de quién era el turno, ni —al final del día—
 * si lo que hay en el cajón es lo que debería. El docblock de `RegistradorPago`
 * lleva desde que existe hablando de «una caja que no cuadra» sobre un sistema
 * que no tenía cajas.
 *
 * ── El pago APUNTA a su sesión; no se deduce por hora ──────────────────────
 * La tentación es derivarlo: «los pagos de este usuario entre la apertura y el
 * cierre». No sirve — quien cobra en dos ventanillas el mismo día repartiría su
 * dinero por el reloj, y un cobro registrado a las 14:00 por alguien cuyo turno
 * abrió a las 8:00 en OTRA caja acabaría en el corte equivocado. Se guarda
 * `pagos.sesion_caja_id` y el corte deja de depender de una interpretación.
 *
 * ── `metodos_pago.afecta_caja`: bandera, no clave cableada ─────────────────
 * Lo que se cuenta en el arqueo es lo que ENTRÓ AL CAJÓN. Una tarjeta cobrada
 * en la misma ventanilla pertenece al corte —sale en sus totales— pero no al
 * conteo de billetes. Preguntar por `clave = 'efectivo'` funcionaría hoy y
 * dejaría de funcionar en silencio el día que una escuela agregue «efectivo en
 * dólares» o marque el cheque como dinero de cajón.
 *
 * ── Dos columnas GENERADAS, y no son adorno ────────────────────────────────
 * «Una caja tiene como mucho una sesión abierta» y «una persona tiene como
 * mucho una sesión abierta» son las dos preguntas que DEBEN tener una sola
 * respuesta: sin ellas, el pago que se registra no sabe a qué corte pertenece.
 * Un `SELECT` previo no basta —dos peticiones simultáneas lo pasan las dos—, y
 * un único sobre `(caja_id, cerrada_en)` tampoco: MySQL considera distintos dos
 * NULL, así que admitiría dos sesiones abiertas de la misma caja.
 *
 * La columna generada vale `caja_id` mientras la sesión está abierta y NULL en
 * cuanto se cierra, así que el único la vigila de verdad y deja pasar todos los
 * cierres que haga falta. Misma idea que `adeudos_generacion_unica`: que la
 * base sostenga la regla y no sólo la comprobación previa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cajas')) {
            Schema::create('cajas', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('clave', 30)->unique();
                $tabla->string('nombre', 100);
                // Por CAMPUS, como pidió el cliente: una caja es un mostrador
                // físico y el efectivo no viaja entre planteles.
                $tabla->foreignId('campus_id')->constrained('campus');
                $tabla->boolean('activa')->default(true);
                $tabla->auditoria();
            });
        }

        if (! Schema::hasTable('sesiones_caja')) {
            Schema::create('sesiones_caja', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('caja_id')->constrained('cajas');
                $tabla->foreignId('usuario_id')->constrained('usuarios');
                $tabla->timestamp('abierta_en');

                // El fondo con el que se abre: lo que había en el cajón ANTES de
                // cobrar nada. Sin él, el arqueo compararía el conteo contra lo
                // cobrado y saldría sobrante todos los días por el mismo importe.
                $tabla->decimal('fondo_inicial', 12, 2)->default(0);

                $tabla->timestamp('cerrada_en')->nullable();
                $tabla->foreignId('cerrada_por_usuario_id')->nullable()->constrained('usuarios');

                // Las tres cifras del arqueo. `esperado` se CONGELA al cerrar:
                // recalcularlo al mirarlo haría que un corte de hace un mes
                // cambiara solo, y entonces la diferencia dejaría de ser un
                // hecho para volverse una opinión de hoy.
                $tabla->decimal('efectivo_esperado', 12, 2)->nullable();
                $tabla->decimal('efectivo_contado', 12, 2)->nullable();
                $tabla->decimal('diferencia', 12, 2)->nullable();

                $tabla->string('estatus', 20)->default('abierta');
                $tabla->string('motivo_diferencia', 255)->nullable();
                $tabla->text('notas')->nullable();

                $tabla->foreignId('autorizada_por_usuario_id')->nullable()->constrained('usuarios');
                $tabla->timestamp('autorizada_en')->nullable();

                $tabla->auditoria();

                $tabla->index(['caja_id', 'abierta_en']);
            });

            // Las dos reglas que la base tiene que sostener. Ver la nota de
            // arriba: con un `SELECT` previo, dos peticiones simultáneas abren
            // dos sesiones y el pago siguiente no sabe a cuál pertenece.
            DB::statement(
                'ALTER TABLE sesiones_caja
                 ADD COLUMN caja_abierta BIGINT UNSIGNED
                   GENERATED ALWAYS AS (CASE WHEN cerrada_en IS NULL THEN caja_id END) VIRTUAL,
                 ADD COLUMN usuario_abierto BIGINT UNSIGNED
                   GENERATED ALWAYS AS (CASE WHEN cerrada_en IS NULL THEN usuario_id END) VIRTUAL'
            );

            DB::statement('CREATE UNIQUE INDEX sesiones_caja_una_abierta_por_caja ON sesiones_caja (caja_abierta)');
            DB::statement('CREATE UNIQUE INDEX sesiones_caja_una_abierta_por_usuario ON sesiones_caja (usuario_abierto)');
        }

        if (! Schema::hasColumn('metodos_pago', 'afecta_caja')) {
            Schema::table('metodos_pago', function (Blueprint $tabla) {
                $tabla->boolean('afecta_caja')->default(false)->after('requiere_confirmacion');
            });

            // El efectivo es lo único que entra al cajón por omisión. El cheque
            // NO se marca: se recibe en ventanilla pero se deposita, no se
            // cuenta como billetes, y si alguna escuela lo cuenta lo enciende.
            DB::table('metodos_pago')->where('clave', 'efectivo')->update(['afecta_caja' => true]);
        }

        if (! Schema::hasColumn('pagos', 'sesion_caja_id')) {
            Schema::table('pagos', function (Blueprint $tabla) {
                // Sin acción referencial: una sesión cerrada no se borra —es el
                // corte— y dejar el pago apuntando a la nada haría irreconstruible
                // el arqueo que ya se firmó.
                $tabla->foreignId('sesion_caja_id')->nullable()
                    ->after('metodo_pago_id')
                    ->constrained('sesiones_caja');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pagos', 'sesion_caja_id')) {
            Schema::table('pagos', function (Blueprint $tabla) {
                $tabla->dropForeign(['sesion_caja_id']);
                $tabla->dropColumn('sesion_caja_id');
            });
        }

        if (Schema::hasColumn('metodos_pago', 'afecta_caja')) {
            Schema::table('metodos_pago', fn (Blueprint $t) => $t->dropColumn('afecta_caja'));
        }

        Schema::dropIfExists('sesiones_caja');
        Schema::dropIfExists('cajas');
    }
};
