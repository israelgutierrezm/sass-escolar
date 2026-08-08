<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El comprobante de una transferencia, esperando que alguien lo valide.
 *
 * ── Un comprobante NO es un pago ───────────────────────────────────────────
 * Es la misma distinción que ya hace `intenciones_cobro` con las pasarelas, por
 * la misma razón y con más motivo: aquí no hay banco que confirme, hay una
 * imagen que alguien subió. Puede ser de otro pago, de otra escuela, de otro
 * monto, o simplemente estar mal leída. El `pago` nace cuando una persona lo
 * revisa y lo aprueba, no cuando se sube.
 *
 * ── Por qué se guarda a qué cargos iba ─────────────────────────────────────
 * Quien transfiere elige qué está pagando —«esto es la inscripción», «esto son
 * marzo y abril»—, y esa intención se pierde si sólo se guarda el monto. Al
 * aprobar se aplica a esos cargos; si alguno ya se liquidó por otra vía, el
 * registrador reparte lo que quede.
 *
 * ── El archivo va en disco privado ─────────────────────────────────────────
 * Un comprobante de transferencia trae nombre, banco y a veces número de
 * cuenta: son datos personales y financieros de alguien. Se sirve por una ruta
 * que comprueba quién pregunta, nunca desde una URL pública.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_pago', function (Blueprint $table) {
            $table->id();

            /*
             * Mismo titular dual que `adeudos`, `pagos` e `intenciones_cobro`:
             * el aspirante también transfiere su ficha antes de tener
             * matrícula. Ver el CHECK al final.
             */
            $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');
            $table->foreignId('aspirante_id')->nullable()->constrained('aspirantes');

            // A qué cuenta dice haber transferido. Nullable porque la escuela
            // podría borrar la cuenta después, y el comprobante no desaparece.
            $table->foreignId('cuenta_bancaria_id')->nullable()->constrained('cuentas_bancarias')->nullOnDelete();

            $table->decimal('monto', 12, 2);
            $table->date('fecha_transferencia');
            // La referencia o folio que dio el banco.
            $table->string('referencia', 100)->nullable();
            $table->string('archivo', 255);

            // Qué cargos venía a cubrir (ver la nota de arriba).
            $table->json('adeudo_ids')->nullable();

            // pendiente | aprobado | rechazado
            $table->string('estado', 20)->default('pendiente');

            /*
             * Por qué se rechazó. Obligatorio al rechazar —lo exige el
             * controlador—: un comprobante devuelto sin motivo obliga a quien
             * pagó a adivinar, y casi siempre acaba en una llamada.
             */
            $table->text('motivo_rechazo')->nullable();

            $table->foreignId('revisado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();

            // El pago que se generó al aprobarlo.
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();

            $table->auditoria();

            // La cola de revisión se abre por aquí: lo pendiente, lo primero.
            $table->index(['estado', 'created_at']);
            $table->index(['matricula_oferta_id', 'estado']);
        });

        DB::statement('ALTER TABLE comprobantes_pago ADD CONSTRAINT comprobantes_un_titular
            CHECK ((matricula_oferta_id IS NOT NULL) + (aspirante_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_pago');
    }
};
