<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuentas de la escuela donde se recibe una transferencia directa.
 *
 * ── Por qué existe, teniendo pasarelas ─────────────────────────────────────
 * La pasarela cobra y confirma sola, pero cobra comisión y no todas las
 * escuelas la tienen. Lo que casi todas SÍ tienen es una cuenta de banco a la
 * que el alumno transfiere y luego manda el comprobante. Eso ya pasa hoy —por
 * WhatsApp, por correo, en ventanilla—, y el sistema no se enteraba: el pago
 * existía y el adeudo seguía abierto hasta que alguien lo capturaba a mano.
 *
 * ── Por CARRERA, y por eso el pivote ───────────────────────────────────────
 * Una escuela con varias carreras suele tener una cuenta por cada una —o una
 * para posgrado y otra para licenciatura—, porque así cuadra su contabilidad.
 * Sin pivote habría que elegir entre una sola cuenta para todos o repetir la
 * cuenta por carrera; con él, una cuenta sin carreras asignadas vale para
 * todas, que es el caso simple y el más común.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();

            // Cómo la llama la escuela: «Colegiaturas BBVA», «Posgrado Santander».
            $table->string('nombre', 120);
            $table->string('banco', 80);
            $table->string('titular', 160);

            /*
             * La CLABE es lo que de verdad se teclea para transferir en México;
             * el número de cuenta se guarda porque algunos bancos lo piden para
             * depósitos en ventanilla. Ninguno es obligatorio por separado, pero
             * sin ninguno de los dos la cuenta no sirve —lo comprueba la
             * pantalla, no la base, para poder explicarlo bien—.
             */
            $table->string('clabe', 18)->nullable();
            $table->string('numero_cuenta', 30)->nullable();

            // Qué más hay que saber para que el pago se identifique: «pon tu
            // matrícula en el concepto», por ejemplo.
            $table->text('instrucciones')->nullable();

            $table->boolean('activa')->default(true);

            $table->auditoria();
        });

        Schema::create('cuenta_bancaria_carrera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias')->cascadeOnDelete();
            $table->foreignId('carrera_id')->constrained('carreras')->cascadeOnDelete();

            // Sin repetir: la misma carrera dos veces en la misma cuenta no
            // significa nada y duplicaría la cuenta en la pantalla del alumno.
            $table->unique(['cuenta_bancaria_id', 'carrera_id'], 'cuenta_carrera_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuenta_bancaria_carrera');
        Schema::dropIfExists('cuentas_bancarias');
    }
};
