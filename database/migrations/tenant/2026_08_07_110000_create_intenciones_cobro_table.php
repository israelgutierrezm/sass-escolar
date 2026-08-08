<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un intento de pago en línea. NO es un pago.
 *
 * ── Por qué una tabla y no un `pago` pendiente ─────────────────────────────
 * Cuando alguien pulsa «Pagar en línea» todavía no hay dinero: hay una promesa
 * que la mayoría de las veces se abandona a medio camino —se cierra la pestaña,
 * se rechaza la tarjeta, se piensa mejor—. Registrar eso como `pago` pendiente
 * llenaría la caja de cobros fantasma y el estado de cuenta del alumno de
 * renglones que nunca fueron dinero.
 *
 * Así que el intento vive aquí y el `pago` se crea SÓLO cuando la pasarela
 * confirma. Cuando eso pasa, la intención guarda a qué pago dio lugar.
 *
 * ── Para qué sirve de verdad ───────────────────────────────────────────────
 * El aviso de la pasarela (el webhook) llega solo, sin sesión y sin contexto:
 * trae un identificador de transacción y poco más. Esta tabla es lo que permite
 * responder «¿de quién era este dinero y qué cargos venía a cubrir?». Sin ella
 * el aviso no se puede conciliar con nadie.
 *
 * ── `adeudo_ids` es una foto, no una relación ──────────────────────────────
 * Se guarda la lista que el alumno eligió en el momento de iniciar el cobro. Si
 * mientras paga se le condona uno de esos cargos, lo que hay que conservar es
 * qué venía a pagar; el reparto real se decide al registrar el pago, con los
 * adeudos que sigan abiertos.
 *
 * ── La referencia externa es única ─────────────────────────────────────────
 * Es la defensa contra el pago duplicado. Los webhooks se reintentan —es su
 * diseño, no una falla—, así que el mismo cobro llega varias veces; el único
 * índice sobre `(pasarela, referencia_externa)` hace imposible que dos avisos
 * del mismo cobro creen dos pagos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intenciones_cobro', function (Blueprint $table) {
            $table->id();

            /*
             * Mismo titular dual que `adeudos` y `pagos`, y por la misma razón:
             * el aspirante paga su ficha antes de tener matrícula. Ver el CHECK
             * al final.
             */
            $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');
            $table->foreignId('aspirante_id')->nullable()->constrained('aspirantes');

            $table->string('pasarela', 30);

            /*
             * El ambiente se SELLA al crear la intención.
             *
             * Si la escuela pasa de pruebas a producción mientras alguien está
             * pagando, el aviso de ese cobro hay que consultarlo contra el
             * ambiente donde nació: preguntarle a producción por un cobro de
             * pruebas devuelve «no existe», y ese pago se perdería.
             */
            $table->string('ambiente', 20);

            // Lo que se le pidió a la pasarela que cobrara.
            $table->decimal('monto', 12, 2);

            // Qué cargos venía a cubrir (ver la nota de arriba).
            $table->json('adeudo_ids')->nullable();

            /*
             * El identificador del cobro EN la pasarela (la preferencia de
             * Mercado Pago, la sesión de Stripe…). Es por donde se le pregunta
             * a la pasarela qué pasó de verdad.
             */
            $table->string('referencia_externa', 190)->nullable();

            // pendiente | pagada | fallida | cancelada | expirada
            $table->string('estado', 20)->default('pendiente');

            // El pago que se generó al confirmarse. Null mientras no haya dinero.
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();

            /*
             * Lo último que dijo la pasarela, tal cual, para poder explicar un
             * cobro que no cuadra sin depender de que alguien recuerde qué pasó.
             */
            $table->json('respuesta')->nullable();

            $table->timestamp('resuelta_en')->nullable();

            // `auditoria()` trae timestamps, borrado suave y created_by/
            // updated_by, que es lo que el trait `TieneAuditoria` del modelo
            // espera encontrar.
            $table->auditoria();

            // El aviso llega con la referencia y nada más: se busca por aquí.
            $table->unique(['pasarela', 'referencia_externa'], 'intenciones_cobro_referencia_unica');
            $table->index(['matricula_oferta_id', 'estado']);
            $table->index(['aspirante_id', 'estado']);
        });

        // Exactamente un titular, como en adeudos y pagos.
        DB::statement('ALTER TABLE intenciones_cobro ADD CONSTRAINT intenciones_cobro_un_titular
            CHECK ((matricula_oferta_id IS NOT NULL) + (aspirante_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('intenciones_cobro');
    }
};
