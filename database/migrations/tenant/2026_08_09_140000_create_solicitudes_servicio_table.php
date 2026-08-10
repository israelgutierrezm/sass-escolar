<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * solicitudes_servicio (TENANT) — lo que un alumno pidió del catálogo de
 * servicios: una constancia, una credencial de repuesto, un extraordinario.
 *
 * ── Por qué NO hay un estado «esperando pago» guardado ─────────────────────
 * Sería un segundo lugar donde vive la verdad sobre si algo está pagado, y el
 * primero es el adeudo. En cuanto existan los dos, se separan: un pago aplicado
 * de madrugada, un comprobante aprobado desde otra pantalla, una condonación —y
 * la solicitud se queda diciendo «esperando pago» de algo que ya se cobró, o al
 * revés. No lo avisaría nada.
 *
 * Así que aquí sólo se guarda el estado del TRÁMITE, que es lo único que esta
 * tabla manda: pedido, atendido, rechazado, cancelado. Si falta pagar se
 * pregunta al adeudo, cada vez.
 *
 * ── Por qué cuelga de la matrícula y no de la persona ──────────────────────
 * Porque el adeudo cuelga de la matrícula, y quien estudia dos carreras tiene
 * dos. Atarlo a la persona obligaría a adivinar a cuál de sus cuentas mandar el
 * cargo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_servicio', function (Blueprint $table) {
            $table->id();

            // `restrictOnDelete`: un servicio con solicitudes no se borra —de
            // hecho el catálogo ni siquiera borra, apaga—, y su nombre es lo
            // que explica qué se pidió.
            $table->foreignId('servicio_id')->constrained('servicios')->restrictOnDelete();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();

            // El cargo que generó, cuando el servicio tiene costo. Nulo en los
            // gratuitos, y también en los que se pidieron y se cancelaron antes
            // de llegar a generar nada.
            $table->foreignId('adeudo_id')->nullable()->constrained('adeudos')->nullOnDelete();

            $table->string('estado', 20)->default('pedida');

            // Lo que el alumno explica al pedirlo, y lo que la escuela contesta
            // al cerrarlo. Dos campos y no una bitácora: el trámite es de un
            // solo paso y una conversación completa aquí sería inventarse un
            // problema que la escuela no tiene.
            $table->string('nota_alumno', 500)->nullable();
            $table->string('respuesta', 500)->nullable();

            $table->unsignedBigInteger('atendida_por')->nullable();
            $table->timestamp('atendida_en')->nullable();

            $table->auditoria();

            $table->index(['estado', 'created_at']);
            $table->index('matricula_oferta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_servicio');
    }
};
